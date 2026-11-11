<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->contact = Contact::factory()->for($this->client)->create(['is_default' => true]);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
    $this->builder = app(EstimateBuilder::class);
    $this->lifecycle = app(EstimateLifecycle::class);
});

function draftEstimate(): \App\Models\Estimate
{
    return test()->builder->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500]],
        notes: 'Scope notes.',
        title: 'Partnerschaft auf Augenhöhe',
    );
}

test('accept transitions sent -> accepted and stamps decided_at + event', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);

    test()->lifecycle->accept($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('accepted');
    expect($estimate->decided_at)->not->toBeNull();
    expect($estimate->events()->where('kind', 'accepted')->count())->toBe(1);
});

test('decline transitions sent -> declined and stamps decided_at + event', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);

    test()->lifecycle->decline($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('declined');
    expect($estimate->decided_at)->not->toBeNull();
    expect($estimate->events()->where('kind', 'declined')->count())->toBe(1);
});

test('accept is rejected unless the estimate is sent', function () {
    $estimate = draftEstimate(); // draft
    expect(fn () => test()->lifecycle->accept($estimate))->toThrow(\DomainException::class);
});

test('convertToInvoice builds a linked draft invoice from the lines', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);

    $invoice = test()->lifecycle->convertToInvoice($estimate);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->status)->toBe('draft');
    expect($invoice->client_id)->toBe(test()->client->id);
    expect($invoice->project_id)->toBe(test()->project->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('Design phase');
    expect($invoice->lines->first()->amount_rappen)->toBe(29000);
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/'); // invoice numbering, not OF-

    $estimate->refresh();
    expect($estimate->converted_invoice_id)->toBe($invoice->id);
    expect($estimate->events()->where('kind', 'converted')->count())->toBe(1);
});

test('convertToInvoice carries the estimate title and notes onto the invoice', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);

    $invoice = test()->lifecycle->convertToInvoice($estimate);

    expect($invoice->title)->toBe('Partnerschaft auf Augenhöhe');
    expect($invoice->notes)->toBe('Scope notes.');
});

test('convertToInvoice is rejected unless the estimate is accepted', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent']);

    expect(fn () => test()->lifecycle->convertToInvoice($estimate))->toThrow(\DomainException::class);
});

test('convertToInvoice refuses to convert the same estimate twice', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);

    test()->lifecycle->convertToInvoice($estimate);

    expect(fn () => test()->lifecycle->convertToInvoice($estimate->fresh()))->toThrow(\DomainException::class);
});

test('markSent transitions draft -> sent without emailing or needing a client contact', function () {
    test()->contact->delete();
    $estimate = draftEstimate();
    Mail::fake();

    test()->lifecycle->markSent($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('sent');
    expect($estimate->issued_on)->not->toBeNull();
    expect($estimate->valid_until?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($estimate->sent_at)->not->toBeNull();
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(1);
    Mail::assertNothingSent();
});

test('markSent is rejected unless the estimate is draft', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->markSent($estimate))->toThrow(\DomainException::class);
});

test('send transitions draft -> sent, stamps dates, caches pdf, mails, writes events', function () {
    BusinessProfile::current()->update(['address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    $estimate = draftEstimate();
    Mail::fake();

    test()->lifecycle->send($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('sent');
    expect($estimate->issued_on)->not->toBeNull();
    expect($estimate->valid_until?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($estimate->pdf_path)->not->toBeNull();
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(1);
    expect($estimate->events()->where('kind', 'pdf_generated')->count())->toBe(1);
    Mail::assertSent(\App\Mail\EstimateMail::class, fn ($mail) => $mail->estimate->is($estimate) && $mail->pdfPath === $estimate->pdf_path);
})->group('browsershot');

test('send is rejected unless draft', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->send($estimate))->toThrow(\DomainException::class);
});

test('send is rejected when the client has no default contacts', function () {
    test()->contact->delete();
    $estimate = draftEstimate();

    expect(fn () => test()->lifecycle->send($estimate))->toThrow(\DomainException::class);
    expect($estimate->fresh()->status)->toBe('draft');
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(0);
});

test('mail failures keep the estimate as draft and write no sent event', function () {
    BusinessProfile::current()->update(['address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    $estimate = draftEstimate();

    Mail::shouldReceive('to')
        ->once()
        ->with(\Mockery::type(\Illuminate\Mail\Mailables\Address::class))
        ->andThrow(new RuntimeException('SMTP down'));

    expect(fn () => test()->lifecycle->send($estimate))->toThrow(RuntimeException::class);
    expect($estimate->fresh()->status)->toBe('draft');
    expect($estimate->fresh()->sent_at)->toBeNull();
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(0);
})->group('browsershot');

/** A lifecycle whose PDF "render" just drops a file, so these tests need no Chromium. */
function estimateLifecycleWithFakePdf(): EstimateLifecycle
{
    \Illuminate\Support\Facades\Storage::fake('local');

    $pdf = Mockery::mock(\App\Services\Estimating\EstimatePdfRenderer::class);
    $pdf->shouldReceive('pdf')->andReturnUsing(function (\App\Models\Estimate $estimate) {
        $path = "estimates/{$estimate->number}.pdf";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, '%PDF-fake');
        $estimate->update(['pdf_path' => $path]);

        return $path;
    });

    return new EstimateLifecycle($pdf, app(\App\Services\Invoicing\InvoiceBuilder::class));
}

test('send re-checks the status under a lock, so a second submit with a stale copy sends nothing', function () {
    $estimate = draftEstimate();
    $stale = \App\Models\Estimate::find($estimate->id);
    $lifecycle = estimateLifecycleWithFakePdf();
    Mail::fake();

    $lifecycle->send($estimate);

    expect(fn () => $lifecycle->send($stale))->toThrow(\DomainException::class, 'Only a draft can be sent');
    Mail::assertSent(\App\Mail\EstimateMail::class, 1);
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(1);
});

test('send mails only after its transaction has committed, and a mail failure restores the draft', function () {
    $estimate = draftEstimate();
    $lifecycle = estimateLifecycleWithFakePdf();
    $outer = \Illuminate\Support\Facades\DB::transactionLevel();

    Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($outer, $estimate) {
        expect(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($outer);
        expect($estimate->fresh()->status)->toBe('sent');

        throw new RuntimeException('SMTP down');
    });

    expect(fn () => $lifecycle->send($estimate))->toThrow(RuntimeException::class, 'SMTP down');

    $fresh = $estimate->fresh();
    expect($fresh->status)->toBe('draft');
    expect($fresh->issued_on)->toBeNull();
    expect($fresh->valid_until)->toBeNull();
    expect($fresh->sent_at)->toBeNull();
    expect($fresh->pdf_path)->toBeNull();
    expect($fresh->events()->whereIn('kind', ['sent', 'pdf_generated'])->count())->toBe(0);
});

test('send has committed its sent event before the mail goes out, so a crash there leaves no sent estimate without one', function () {
    $estimate = draftEstimate();
    $lifecycle = estimateLifecycleWithFakePdf();
    $kindsWhileMailing = null;

    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function () use (&$kindsWhileMailing, $estimate) {
        $kindsWhileMailing = $estimate->events()->whereIn('kind', ['pdf_generated', 'sent'])->pluck('kind')->sort()->values()->all();
    });

    $lifecycle->send($estimate);

    expect($kindsWhileMailing)->toBe(['pdf_generated', 'sent']);
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(1);
    expect($estimate->events()->where('kind', 'sent')->first()->payload['email_to'])->not->toBeEmpty();
});

test('convertToInvoice checks under a lock, so a double submit creates one invoice', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);
    $stale = \App\Models\Estimate::find($estimate->id);

    $invoice = test()->lifecycle->convertToInvoice($estimate);

    expect($stale->converted_invoice_id)->toBeNull();
    expect(fn () => test()->lifecycle->convertToInvoice($stale))->toThrow(\DomainException::class, 'already been converted');
    expect(Invoice::count())->toBe(1);
    expect($estimate->fresh()->converted_invoice_id)->toBe($invoice->id);
});
