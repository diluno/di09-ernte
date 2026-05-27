<?php

use App\Models\User;

test('user can update tweaks settings', function () {
    $user = User::factory()->create([
        'settings' => ['theme' => 'paper', 'density' => 'comfortable', 'accent' => '#2d4a3a'],
    ]);

    $this->actingAs($user)
        ->patch('/settings/tweaks', [
            'theme' => 'dark',
            'density' => 'compact',
            'accent' => '#c97b3c',
        ])
        ->assertRedirect();

    expect($user->fresh()->settings)->toMatchArray([
        'theme' => 'dark',
        'density' => 'compact',
        'accent' => '#c97b3c',
    ]);
});

test('invalid theme is rejected', function () {
    $user = User::factory()->create(['settings' => ['theme' => 'paper']]);

    $this->actingAs($user)
        ->patch('/settings/tweaks', ['theme' => 'neon-pink'])
        ->assertSessionHasErrors('theme');
});
