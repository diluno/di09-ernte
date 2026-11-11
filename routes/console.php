<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ernte:invoices:generate-recurring')->dailyAt('06:00');
Schedule::command('ernte:invoices:remind')->dailyAt('09:00');
Schedule::command('ernte:invoices:stamp-overdue')->daily();
Schedule::command('ernte:backup')->dailyAt('03:00')->onFailure(fn () => app(\App\Services\Backups\BackupFailureAlert::class)());
Schedule::command('ernte:receipts:check-inbox')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('services.dropbox.inbox_check'));
