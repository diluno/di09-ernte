<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fail loudly if the suite is not actually in the 'testing' environment.
     *
     * A cached config (bootstrap/cache/config.php, e.g. left behind by
     * `php artisan optimize`) freezes the .env/local values and makes Laravel
     * ignore phpunit.xml's overrides. The suite then silently runs against the
     * dev database (RefreshDatabase migrating real data) with CSRF still on, so
     * every mutating request 419s. We check right after the app is built —
     * before RefreshDatabase touches any database — and abort with the fix.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        if (! $this->app->environment('testing')) {
            throw new RuntimeException(
                "Tests are running in the '{$this->app->environment()}' environment, not 'testing'. "
                .'A cached config is likely shadowing phpunit.xml. Run: php artisan config:clear'
            );
        }
    }
}
