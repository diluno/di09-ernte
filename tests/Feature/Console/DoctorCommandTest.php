<?php

use App\Models\BusinessProfile;

beforeEach(function () {
    BusinessProfile::firstOrCreate(['id' => 1], [
        'name' => 'Ernte Test',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);
});

test('doctor command succeeds when required runtime checks pass', function () {
    config([
        'app.key' => 'base64:test',
        'app.env' => 'testing',
        'app.url' => 'http://localhost',
    ]);

    $this->artisan('ernte:doctor')
        ->expectsOutputToContain('[OK] APP_KEY')
        ->expectsOutputToContain('[OK] database')
        ->assertExitCode(0);
});

test('doctor command fails when the app key is missing', function () {
    config(['app.key' => null]);

    $this->artisan('ernte:doctor')
        ->expectsOutputToContain('[FAIL] APP_KEY')
        ->assertExitCode(1);
});

test('doctor command rejects log mail in production', function () {
    config([
        'app.env' => 'production',
        'app.key' => 'base64:test',
        'app.url' => 'https://ernte.example.com',
        'mail.default' => 'log',
        'queue.default' => 'database',
        'services.browsershot.chrome_path' => PHP_BINARY,
    ]);

    $this->artisan('ernte:doctor')
        ->expectsOutputToContain('[FAIL] mail')
        ->assertExitCode(1);
});
