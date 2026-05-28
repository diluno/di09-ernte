<?php

use App\Models\BusinessProfile;
use App\Models\Client;
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

test('issue is rejected unless draft', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(\DomainException::class);
});

test('issue is rejected when the client has no email address', function () {
    test()->client->update(['email' => null]);
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
        ->with(test()->client->email)
        ->andThrow(new RuntimeException('SMTP down'));

    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(RuntimeException::class);
    expect($invoice->fresh()->status)->toBe('draft');
    expect($invoice->fresh()->sent_at)->toBeNull();
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(0);
})->group('browsershot');
