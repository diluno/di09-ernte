<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\ParallelTesting;
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

        $this->isolateFakeDisks();
    }

    /**
     * Give Storage::fake() a directory of its own per test process.
     *
     * Storage::fake('local') roots the fake at storage/framework/testing/disks/local and
     * empties it first, so two suites running at the same time (e.g. two `artisan test`
     * runs) wipe each other's files mid-test. Storage::fake() appends the parallel-testing
     * token to that path when there is one; outside `--parallel` we hand it the process id
     * instead. Only outside `--parallel`: there the real token also names the test databases.
     */
    private function isolateFakeDisks(): void
    {
        if (! empty($_SERVER['LARAVEL_PARALLEL_TESTING']) || ! $this->app->bound(ParallelTesting::class)) {
            return;
        }

        $token = 'p'.getmypid();
        $this->app->make(ParallelTesting::class)->resolveTokenUsing(fn () => $token);

        static $cleanupRegistered = false;
        if (! $cleanupRegistered) {
            $cleanupRegistered = true;
            $disks = storage_path('framework/testing/disks');
            register_shutdown_function(function () use ($disks, $token) {
                foreach (glob("{$disks}/*_test_{$token}", GLOB_ONLYDIR) ?: [] as $dir) {
                    (new Filesystem)->deleteDirectory($dir);
                }
            });
        }
    }
}
