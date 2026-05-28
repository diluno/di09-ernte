<?php

use App\Models\Client;
use App\Services\Harvest\ClientImporter;

test('maps fields, generates a short code, links the primary contact', function () {
    $clients = [
        ['id' => 10, 'name' => 'Atlas Robotics', 'is_active' => true, 'address' => "Bahnhofstrasse 1\n8001 Zürich"],
    ];
    $contacts = [
        ['id' => 1, 'client' => ['id' => 10], 'first_name' => 'Mira', 'last_name' => 'Okafor', 'email' => 'mira@atlas.test'],
    ];

    $map = (new ClientImporter())->import($clients, $contacts);

    expect($map)->toHaveKey(10);
    $c = $map[10];
    expect($c)->toBeInstanceOf(Client::class);
    expect($c->name)->toBe('Atlas Robotics');
    expect($c->short_code)->toBe('ATLA');
    expect($c->address_line_1)->toBe('Bahnhofstrasse 1');
    expect($c->postal_code)->toBe('8001');
    expect($c->city)->toBe('Zürich');
    expect($c->country)->toBe('CH');
    expect($c->contact_name)->toBe('Mira Okafor');
    expect($c->email)->toBe('mira@atlas.test');
    expect($c->archived_at)->toBeNull();
});

test('de-duplicates short codes across clients with similar names', function () {
    $clients = [
        ['id' => 1, 'name' => 'Atlas Robotics', 'is_active' => true],
        ['id' => 2, 'name' => 'Atlas Logistics', 'is_active' => true],
    ];

    $map = (new ClientImporter())->import($clients, []);

    expect($map[1]->short_code)->toBe('ATLA');
    expect($map[2]->short_code)->not->toBe('ATLA');
    expect(strlen($map[2]->short_code))->toBeLessThanOrEqual(4);
});

test('inactive clients are archived', function () {
    $map = (new ClientImporter())->import([['id' => 5, 'name' => 'Old Co', 'is_active' => false]], []);

    expect($map[5]->archived_at)->not->toBeNull();
});

test('truncates an over-long address to 255 characters', function () {
    $map = (new ClientImporter())->import([
        ['id' => 1, 'name' => 'Co', 'is_active' => true, 'address' => str_repeat('x', 400)],
    ], []);

    expect(mb_strlen($map[1]->address_line_1))->toBe(255);
});
