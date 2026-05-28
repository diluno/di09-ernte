<?php

use App\Models\BusinessProfile;
use App\Models\Client;
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
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
    $this->builder = app(EstimateBuilder::class);
    $this->lifecycle = app(EstimateLifecycle::class);
});

function draftEstimate(): \App\Models\Estimate
{
    return test()->builder->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false]],
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
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('Design phase');
    expect($invoice->lines->first()->amount_rappen)->toBe(29000);
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/'); // invoice numbering, not OF-

    $estimate->refresh();
    expect($estimate->converted_invoice_id)->toBe($invoice->id);
    expect($estimate->events()->where('kind', 'converted')->count())->toBe(1);
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

test('send is rejected when the client has no email address', function () {
    test()->client->update(['email' => null]);
    $estimate = draftEstimate();

    expect(fn () => test()->lifecycle->send($estimate))->toThrow(\DomainException::class);
    expect($estimate->fresh()->status)->toBe('draft');
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(0);
});
