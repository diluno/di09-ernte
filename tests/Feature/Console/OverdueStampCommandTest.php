<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;

test('overdue stamp command writes one event for sent overdue invoices', function () {
    $client = Client::factory()->create();
    $overdue = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'sent',
        'due_on' => now()->subDay()->toDateString(),
    ]);

    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'paid', 'due_on' => now()->subDay()->toDateString()]);

    $this->artisan('ernte:invoices:stamp-overdue')
        ->expectsOutput('Stamped 1 overdue invoice(s).')
        ->assertExitCode(0);

    $event = $overdue->events()->where('kind', 'overdue_stamped')->first();
    expect($event)->not->toBeNull();
    expect($event->payload['due_on'])->toBe($overdue->due_on->toDateString());
});

test('overdue stamp command is idempotent', function () {
    $invoice = Invoice::factory()->create([
        'status' => 'sent',
        'due_on' => now()->subDays(2)->toDateString(),
    ]);
    InvoiceEvent::create([
        'invoice_id' => $invoice->id,
        'kind' => 'overdue_stamped',
        'occurred_at' => now()->subDay(),
    ]);

    $this->artisan('ernte:invoices:stamp-overdue')
        ->expectsOutput('Stamped 0 overdue invoice(s).')
        ->assertExitCode(0);

    expect($invoice->events()->where('kind', 'overdue_stamped')->count())->toBe(1);
});
