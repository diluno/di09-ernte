<?php

use App\Models\User;

test('a user can create an api token', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-device')->plainTextToken;

    expect($token)->toBeString()->not->toBeEmpty();
    expect($user->tokens()->count())->toBe(1);
});
