<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('redirect from / to /projects', function () {
    $this->actingAs($this->user)->get('/')->assertRedirect('/projects');
});

dataset('pages', [
    ['/projects', 'Projects'],
    ['/timer',    'Today'],
    ['/clients',  'Clients'],
    ['/invoices', 'Invoices'],
    ['/reports',  'Reports'],
]);

test('authenticated user can load $route', function (string $route, string $title) {
    $this->actingAs($this->user)
        ->get($route)
        ->assertOk()
        ->assertSee($title);
})->with('pages');

test('unauthenticated user is redirected to login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
