<?php

use App\Models\User;

test('the old profile page redirects to settings', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertRedirect('/settings');
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/settings')
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
});

test('the account cannot be deleted', function () {
    // Deleting the only user would cascade to every time entry.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertStatus(405);

    $this->assertNotNull($user->fresh());
});

test('breeze email verification routes are gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/verify-email')->assertNotFound();
    $this->actingAs($user)->post('/email/verification-notification')->assertNotFound();
});
