<?php

use function Pest\Laravel\get;

test('registration route is removed', function () {
    get('/register')->assertNotFound();
});

test('seeded user can log in and lands on /projects', function () {
    $this->seed(\Database\Seeders\BootstrapSeeder::class);

    $this->post('/login', [
        'email' => 'owner@ernte.local',
        'password' => 'changeme',
    ])->assertRedirect('/projects');
});
