<?php

use App\Jobs\SendInvoiceReminderMail;
use App\Mail\InvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'billing@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
        'reminder_days_after_due' => 7,
    ]);
});

test('reminder command queues only sent overdue invoices past the reminder cadence', function () {
    Queue::fake();

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'client@example.test', 'is_default' => true]);
    $due = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'sent',
        'due_on' => now()->subDays(8)->toDateString(),
    ]);

    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->subDays(3)->toDateString()]);
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'paid', 'due_on' => now()->subDays(20)->toDateString()]);

    $this->artisan('ernte:invoices:remind')
        ->expectsOutput('Queued 1 reminder(s); skipped 0 missing email; skipped 0 recently reminded.')
        ->assertExitCode(0);

    Queue::assertPushed(SendInvoiceReminderMail::class, fn ($job) => $job->invoiceId === $due->id);
    Queue::assertPushed(SendInvoiceReminderMail::class, 1);
});

test('reminder command skips missing email and recently reminded invoices', function () {
    Queue::fake();

    $missingEmail = Client::factory()->create();
    Invoice::factory()->create([
        'client_id' => $missingEmail->id,
        'status' => 'sent',
        'due_on' => now()->subDays(10)->toDateString(),
    ]);

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'client@example.test', 'is_default' => true]);
    $recent = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'sent',
        'due_on' => now()->subDays(10)->toDateString(),
    ]);
    InvoiceEvent::create([
        'invoice_id' => $recent->id,
        'kind' => 'reminded',
        'occurred_at' => now()->subDays(2),
    ]);

    $this->artisan('ernte:invoices:remind')
        ->expectsOutput('Queued 0 reminder(s); skipped 1 missing email; skipped 1 recently reminded.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('reminder command still queues invoices whose recipients snapshot survives after client default contacts are removed', function () {
    Queue::fake();

    $client = Client::factory()->create();
    $contact = Contact::factory()->for($client)->create(['email' => 'client@example.test', 'is_default' => true]);

    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'sent',
        'due_on' => now()->subDays(10)->toDateString(),
        'recipients' => [['name' => $contact->name, 'email' => $contact->email]],
    ]);

    // Client's default contacts are removed after the invoice was sent.
    $contact->delete();

    $this->artisan('ernte:invoices:remind')
        ->expectsOutput('Queued 1 reminder(s); skipped 0 missing email; skipped 0 recently reminded.')
        ->assertExitCode(0);

    Queue::assertPushed(SendInvoiceReminderMail::class, fn ($job) => $job->invoiceId === $invoice->id);
});

test('reminder job sends mail and writes reminded event', function () {
    Mail::fake();

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'client@example.test', 'is_default' => true]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'sent',
        'due_on' => now()->subDays(10)->toDateString(),
        'total_rappen' => 123400,
    ]);

    (new SendInvoiceReminderMail($invoice->id))->handle();

    Mail::assertSent(InvoiceReminderMail::class, fn ($mail) => $mail->invoice->is($invoice));
    expect($invoice->events()->where('kind', 'reminded')->count())->toBe(1);
});

test('reminder job quietly skips invoices that no longer qualify', function () {
    Mail::fake();

    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['email' => 'client@example.test', 'is_default' => true]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'paid',
        'due_on' => now()->subDays(10)->toDateString(),
    ]);

    (new SendInvoiceReminderMail($invoice->id))->handle();

    Mail::assertNothingSent();
    expect($invoice->events()->where('kind', 'reminded')->count())->toBe(0);
});
