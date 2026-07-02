<?php

use App\Models\Client;
use App\Models\Contact;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates a client with contacts', function () {
    $this->post('/clients', [
        'name' => 'Moveable', 'short_code' => 'MOV', 'country' => 'CH',
        'contacts' => [
            ['name' => 'Marc', 'email' => 'marc@x.ch', 'role' => 'Lead', 'is_default' => true],
            ['name' => 'Acct', 'email' => 'acct@x.ch', 'role' => null, 'is_default' => false],
        ],
    ])->assertRedirect('/clients');

    $client = Client::where('short_code', 'MOV')->firstOrFail();
    expect($client->contacts)->toHaveCount(2);
    expect($client->defaultRecipients())->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('syncs contacts on update: adds, edits, deletes', function () {
    $client = Client::factory()->create();
    $keep = Contact::factory()->for($client)->create(['name' => 'Old', 'email' => 'old@x.ch', 'is_default' => true]);
    $drop = Contact::factory()->for($client)->create(['name' => 'Gone', 'email' => 'gone@x.ch']);

    $this->patch("/clients/{$client->id}", [
        'contacts' => [
            ['id' => $keep->id, 'name' => 'Renamed', 'email' => 'old@x.ch', 'role' => null, 'is_default' => true],
            ['name' => 'New', 'email' => 'new@x.ch', 'role' => null, 'is_default' => false],
        ],
    ])->assertRedirect();

    $client->refresh();
    expect($client->contacts->pluck('name')->sort()->values()->all())->toBe(['New', 'Renamed']);
    expect(Contact::find($drop->id))->toBeNull();
});

it('rejects a contact id belonging to another client', function () {
    $clientA = Client::factory()->create();
    Contact::factory()->for($clientA)->create(['name' => 'A Contact', 'email' => 'a@x.ch']);

    $clientB = Client::factory()->create();
    $foreignContact = Contact::factory()->for($clientB)->create(['name' => 'B Contact', 'email' => 'b@x.ch']);

    $this->patch("/clients/{$clientA->id}", [
        'contacts' => [
            ['id' => $foreignContact->id, 'name' => 'Hijacked', 'email' => 'b@x.ch', 'role' => null, 'is_default' => false],
        ],
    ])->assertSessionHasErrors('contacts.0.id');

    expect(Contact::find($foreignContact->id))->not->toBeNull();
    expect(Contact::find($foreignContact->id)->name)->toBe('B Contact');
});
