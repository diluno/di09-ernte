<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->contact = Contact::factory()->for($this->client)->create(['is_default' => true]);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
    $this->builder = app(InvoiceBuilder::class);
    $this->lifecycle = app(InvoiceLifecycle::class);
});

function draftWithEntry(): array
{
    $start = now()->subDays(2);
    $entry = TimeEntry::factory()->create([
        'user_id' => test()->user->id, 'project_id' => test()->project->id,
        'description' => 'Work', 'started_at' => $start, 'ended_at' => (clone $start)->addHour(),
        'billable' => true,
    ]);
    $invoice = test()->builder->buildDraftFromEntries(
        test()->client, test()->project, TimeEntry::all(),
        now()->subDays(7)->toDateString(), now()->toDateString()
    );
    return [$invoice, $entry];
}

test('markPaid transitions sent -> paid and stamps paid_at + event', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent', 'issued_on' => now()->subDays(3), 'due_on' => now()->addDays(27), 'sent_at' => now()->subDays(3)]);

    test()->lifecycle->markPaid($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->paid_at)->not->toBeNull();
    expect($invoice->events()->where('kind', 'paid')->count())->toBe(1);
});

test('markPaid with a date stamps that day and stores the payload', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent', 'issued_on' => '2026-07-01', 'due_on' => '2026-07-31', 'sent_at' => '2026-07-01']);

    test()->lifecycle->markPaid($invoice, \Illuminate\Support\Carbon::parse('2026-07-22 12:00:00'), ['statement_line_id' => 7]);

    expect($invoice->fresh()->paid_at->toDateString())->toBe('2026-07-22');
    expect($invoice->events()->where('kind', 'paid')->first()->payload)->toBe(['statement_line_id' => 7]);
});

test('reopen takes a paid invoice back to sent and is rejected otherwise', function () {
    [$invoice] = draftWithEntry();
    expect(fn () => test()->lifecycle->reopen($invoice))->toThrow(\DomainException::class);

    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
    test()->lifecycle->reopen($invoice);

    expect($invoice->fresh()->status)->toBe('sent');
    expect($invoice->fresh()->paid_at)->toBeNull();
    expect($invoice->events()->where('kind', 'reopened')->count())->toBe(1);
});

test('markPaid is rejected unless the invoice is sent', function () {
    [$invoice] = draftWithEntry(); // draft
    expect(fn () => test()->lifecycle->markPaid($invoice))
        ->toThrow(\DomainException::class);
});

test('void clears linked entries invoice_id so they return to unbilled', function () {
    [$invoice, $entry] = draftWithEntry();
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
    expect(TimeEntry::unbilled()->billable()->count())->toBe(0);

    test()->lifecycle->void($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('void');
    expect($entry->fresh()->invoice_id)->toBeNull();
    expect(TimeEntry::unbilled()->billable()->count())->toBe(1);   // re-invoiceable again
    expect($invoice->events()->where('kind', 'voided')->count())->toBe(1);
});

test('void works on a sent invoice too', function () {
    [$invoice, $entry] = draftWithEntry();
    $invoice->update(['status' => 'sent', 'issued_on' => now(), 'due_on' => now()->addDays(30)]);

    test()->lifecycle->void($invoice);

    expect($invoice->fresh()->status)->toBe('void');
    expect($entry->fresh()->invoice_id)->toBeNull();
});

test('voiding does not free the number', function () {
    [$invoice] = draftWithEntry();
    $number = $invoice->number;
    test()->lifecycle->void($invoice);
    expect($invoice->fresh()->number)->toBe($number);
});

test('issue transitions draft -> sent, stamps dates, writes pdf_generated + sent events', function () {
    BusinessProfile::current()->update(['qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    [$invoice] = draftWithEntry();
    Mail::fake();

    test()->lifecycle->issue($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('sent');
    expect($invoice->issued_on)->not->toBeNull();
    expect($invoice->due_on?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($invoice->pdf_path)->not->toBeNull();
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(1);
    expect($invoice->events()->where('kind', 'pdf_generated')->count())->toBe(1);
    Mail::assertSent(\App\Mail\InvoiceMail::class, fn ($mail) => $mail->invoice->is($invoice) && $mail->pdfPath === $invoice->pdf_path);
})->group('browsershot');

test('markSent transitions draft -> sent without emailing or needing a client contact', function () {
    test()->contact->delete();
    [$invoice] = draftWithEntry();
    Mail::fake();

    test()->lifecycle->markSent($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('sent');
    expect($invoice->issued_on)->not->toBeNull();
    expect($invoice->due_on?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($invoice->sent_at)->not->toBeNull();
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(1);
    Mail::assertNothingSent();
});

test('markSent is rejected unless draft', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->markSent($invoice))->toThrow(\DomainException::class);
});

test('issue is rejected unless draft', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(\DomainException::class);
});

test('issue is rejected when the client has no default contacts', function () {
    test()->contact->delete();
    [$invoice] = draftWithEntry();

    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(\DomainException::class);
    expect($invoice->fresh()->status)->toBe('draft');
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(0);
});

test('mail failures keep the invoice as draft and write no sent event', function () {
    BusinessProfile::current()->update(['qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    [$invoice] = draftWithEntry();

    Mail::shouldReceive('to')
        ->once()
        ->with(\Mockery::type(\Illuminate\Mail\Mailables\Address::class))
        ->andThrow(new RuntimeException('SMTP down'));

    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(RuntimeException::class);
    expect($invoice->fresh()->status)->toBe('draft');
    expect($invoice->fresh()->sent_at)->toBeNull();
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(0);
})->group('browsershot');

/** A lifecycle whose PDF "render" just drops a file, so these tests need no Chromium. */
function lifecycleWithFakePdf(): InvoiceLifecycle
{
    \Illuminate\Support\Facades\Storage::fake('local');

    $pdf = Mockery::mock(\App\Services\Invoicing\InvoicePdfRenderer::class);
    $pdf->shouldReceive('pdf')->andReturnUsing(function (Invoice $invoice) {
        $path = "invoices/{$invoice->number}.pdf";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, '%PDF-fake');
        $invoice->update(['pdf_path' => $path]);

        return $path;
    });

    return new InvoiceLifecycle($pdf);
}

test('issue re-checks the status under a lock, so a second submit with a stale copy sends nothing', function () {
    [$invoice] = draftWithEntry();
    $stale = Invoice::find($invoice->id);
    $lifecycle = lifecycleWithFakePdf();
    Mail::fake();

    $lifecycle->issue($invoice);

    expect($stale->status)->toBe('draft'); // what a concurrent request would still hold
    expect(fn () => $lifecycle->issue($stale))->toThrow(\DomainException::class, 'Only a draft can be sent');

    Mail::assertSent(\App\Mail\InvoiceMail::class, 1);
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(1);
});

test('issue sends the mail only after its transaction has committed', function () {
    [$invoice] = draftWithEntry();
    $lifecycle = lifecycleWithFakePdf();
    $outer = \Illuminate\Support\Facades\DB::transactionLevel();
    $levelWhileMailing = null;
    $statusWhileMailing = null;

    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function () use (&$levelWhileMailing, &$statusWhileMailing, $invoice) {
        $levelWhileMailing = \Illuminate\Support\Facades\DB::transactionLevel();
        $statusWhileMailing = $invoice->fresh()->status;
    });

    $lifecycle->issue($invoice);

    expect($levelWhileMailing)->toBe($outer);
    expect($statusWhileMailing)->toBe('sent');
});

test('issue has committed its sent event before the mail goes out, so a crash there leaves no sent invoice without one', function () {
    [$invoice] = draftWithEntry();
    $lifecycle = lifecycleWithFakePdf();
    $kindsWhileMailing = null;

    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function () use (&$kindsWhileMailing, $invoice) {
        $kindsWhileMailing = $invoice->events()->whereIn('kind', ['pdf_generated', 'sent'])->pluck('kind')->sort()->values()->all();
    });

    $lifecycle->issue($invoice);

    expect($kindsWhileMailing)->toBe(['pdf_generated', 'sent']);
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(1);
    expect($invoice->events()->where('kind', 'sent')->first()->payload['email_to'])->toBe([test()->contact->email]);
});

test('a mail failure after the commit puts the invoice back to a clean draft', function () {
    [$invoice] = draftWithEntry();
    $lifecycle = lifecycleWithFakePdf();

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

    expect(fn () => $lifecycle->issue($invoice))->toThrow(RuntimeException::class, 'SMTP down');

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('draft');
    expect($fresh->issued_on)->toBeNull();
    expect($fresh->due_on)->toBeNull();
    expect($fresh->sent_at)->toBeNull();
    expect($fresh->pdf_path)->toBeNull();
    expect($invoice->status)->toBe('draft');
    expect($fresh->events()->whereIn('kind', ['sent', 'pdf_generated'])->count())->toBe(0);
});

test('issue fails cleanly, leaving a draft, when no IBAN is configured', function () {
    [$invoice] = draftWithEntry();
    Mail::fake();

    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(\DomainException::class, 'No IBAN is configured');

    expect($invoice->fresh()->status)->toBe('draft');
    expect($invoice->status)->toBe('draft');
    expect($invoice->fresh()->issued_on)->toBeNull();
    Mail::assertNothingSent();
});

test('markPaid and void re-check the status from the database, not the stale copy', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent']);
    $staleA = Invoice::find($invoice->id);
    $staleB = Invoice::find($invoice->id);

    test()->lifecycle->markPaid($invoice);

    expect(fn () => test()->lifecycle->markPaid($staleA))->toThrow(\DomainException::class, 'Only a sent invoice');
    expect(fn () => test()->lifecycle->void($staleB))->toThrow(\DomainException::class, 'Cannot void a paid');
    expect($invoice->events()->where('kind', 'paid')->count())->toBe(1);
});
