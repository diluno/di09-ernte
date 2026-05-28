<?php

use App\Models\Client;
use App\Models\Invoice;

test('Client has many invoices', function () {
    $client = Client::factory()->create();
    Invoice::factory()->count(2)->create(['client_id' => $client->id]);
    Invoice::factory()->create(); // different client

    expect($client->invoices)->toHaveCount(2);
    expect($client->invoices->first())->toBeInstanceOf(Invoice::class);
});

test('Client::invoices returns a HasMany relation', function () {
    $client = Client::factory()->create();
    expect($client->invoices())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});
