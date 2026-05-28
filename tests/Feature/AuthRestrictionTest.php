<?php

use Database\Seeders\BootstrapSeeder;

use function Pest\Laravel\get;

test('registration route is removed', function () {
    get('/register')->assertNotFound();
});

test('seeded user can log in and lands on /projects', function () {
    $this->seed(BootstrapSeeder::class);

    $this->post('/login', [
        'email' => env('ERNTE_USER_EMAIL', 'owner@ernte.local'),
        'password' => env('ERNTE_USER_PASSWORD', 'changeme'),
    ])->assertRedirect('/projects');
});
