<?php

use App\Models\Client;

test('client can be created with factory defaults', function () {
    $c = Client::factory()->create();

    expect($c)
        ->name->not->toBeEmpty()
        ->country->toBe('CH')
        ->default_rate_rappen->toBeInt();
});

test('active and archived scopes', function () {
    Client::factory()->count(3)->create();
    Client::factory()->archived()->count(2)->create();

    expect(Client::active()->count())->toBe(3);
    expect(Client::archived()->count())->toBe(2);
});
