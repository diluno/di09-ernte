<?php
// tests/Feature/ClientContactsRelationTest.php
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

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

it('falls back to the first contact when none is marked default', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'B', 'email' => 'b@x.ch', 'is_default' => false, 'sort_order' => 1]);
    Contact::factory()->for($client)->create(['name' => 'A', 'email' => 'a@x.ch', 'is_default' => false, 'sort_order' => 0]);

    expect($client->defaultRecipients())->toBe([
        ['name' => 'A', 'email' => 'a@x.ch'],
    ]);
});

it('returns an empty array when the client has no contacts', function () {
    $client = Client::factory()->create();
    expect($client->defaultRecipients())->toBe([]);
});

it('does not run an extra query when contacts are already eager-loaded', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['is_default' => true]);

    $loaded = Client::with('contacts')->find($client->id);

    DB::enableQueryLog();
    $loaded->defaultRecipients();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});
