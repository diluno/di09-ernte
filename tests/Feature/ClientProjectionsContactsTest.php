<?php

use App\Models\Client;
use App\Models\Contact;
use App\Support\ClientDetail;
use App\Support\ClientProjections;

it('includes contacts in the client detail payload', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'm@x.ch', 'is_default' => true]);

    $payload = ClientDetail::payload($client->fresh());

    expect($payload['client']['contacts'])->toHaveCount(1);
    expect($payload['client']['contacts'][0]['email'])->toBe('m@x.ch');
    expect($payload['client'])->not->toHaveKey('email');
});

it('exposes the primary default contact in the index projection', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'm@x.ch', 'is_default' => true, 'sort_order' => 0]);

    $row = ClientProjections::index()->firstWhere('id', $client->id);
    expect($row['default_contact'])->toBe(['name' => 'Marc', 'email' => 'm@x.ch']);
});
