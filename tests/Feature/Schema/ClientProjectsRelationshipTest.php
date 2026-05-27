<?php

use App\Models\Client;
use App\Models\Project;

test('Client has many projects', function () {
    $client = Client::factory()->create();
    Project::factory()->count(3)->create(['client_id' => $client->id]);
    Project::factory()->create(); // belongs to a different client

    expect($client->projects)->toHaveCount(3);
    expect($client->projects->first())->toBeInstanceOf(Project::class);
});

test('Client::projects returns a HasMany relation', function () {
    $client = Client::factory()->create();
    expect($client->projects())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});
