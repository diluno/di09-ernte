<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->guardClientMailOutsideProduction();
    }

    /**
     * Defense in depth: anywhere but production, redirect ALL outgoing mail to
     * the operator so a real recipient (a client) can never be emailed from
     * local dev — even if the mailer is misconfigured to a live SMTP relay.
     *
     * Tests use the array mailer and assert real recipients, so they are
     * intentionally excluded.
     */
    private function guardClientMailOutsideProduction(): void
    {
        if ($this->app->isProduction() || $this->app->runningUnitTests()) {
            return;
        }

        if ($redirect = config('mail.dev_redirect_to') ?: config('mail.from.address')) {
            Mail::alwaysTo($redirect);
        }
    }
}
