<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('redirect from / to /projects', function () {
    $this->actingAs($this->user)->get('/')->assertRedirect('/projects');
});

dataset('pages', [
    ['/projects', 'Projects',  'Projects/Index'],
    ['/timer',    'Today',     'Timer/Today'],
    ['/clients',  'Clients',   'Clients/Index'],
    ['/invoices', 'Invoices',  'Invoices/Index'],
    ['/reports',  'Reports',   'Reports/Placeholder'],
]);

test('authenticated user can load $route', function (string $route, string $title, string $component) {
    $this->actingAs($this->user)
        ->get($route)
        ->assertOk()
        ->assertSee($title)
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('pages');

test('unauthenticated user is redirected to login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
