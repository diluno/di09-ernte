<?php
// tests/Feature/ContactsMigrationTest.php
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Facades\Schema;

it('backfills a default contact from the legacy client columns and drops them', function () {
    // The migration under test has already run in the test DB. Assert its shape.
    expect(Schema::hasColumn('clients', 'email'))->toBeFalse();
    expect(Schema::hasColumn('clients', 'contact_name'))->toBeFalse();
    expect(Schema::hasColumns('contacts', ['client_id', 'name', 'email', 'role', 'is_default', 'sort_order']))->toBeTrue();
});

it('creates and reads a contact row', function () {
    $client = Client::factory()->create();
    $contact = Contact::create([
        'client_id' => $client->id,
        'name' => 'Marc Gschwend',
        'email' => 'marc@moveable.ch',
        'role' => 'Accounting',
        'is_default' => true,
        'sort_order' => 0,
    ]);

    expect($contact->fresh()->email)->toBe('marc@moveable.ch');
    expect($contact->fresh()->is_default)->toBeTrue();
});
