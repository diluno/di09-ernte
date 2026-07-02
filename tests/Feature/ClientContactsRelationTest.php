<?php
// tests/Feature/ClientContactsRelationTest.php
use App\Models\Client;
use App\Models\Contact;

it('returns default recipients ordered by sort_order', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'B', 'email' => 'b@x.ch', 'is_default' => true, 'sort_order' => 1]);
    Contact::factory()->for($client)->create(['name' => 'A', 'email' => 'a@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'C', 'email' => 'c@x.ch', 'is_default' => false, 'sort_order' => 2]);

    expect($client->defaultRecipients())->toBe([
        ['name' => 'A', 'email' => 'a@x.ch'],
        ['name' => 'B', 'email' => 'b@x.ch'],
    ]);
});

it('returns an empty array when no default contacts exist', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['is_default' => false]);
    expect($client->defaultRecipients())->toBe([]);
});
