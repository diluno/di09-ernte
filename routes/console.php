<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ernte:invoices:generate-recurring')->dailyAt('06:00');
Schedule::command('ernte:invoices:remind')->dailyAt('09:00');
Schedule::command('ernte:invoices:stamp-overdue')->daily();
Schedule::command('ernte:backup')->dailyAt('03:00');
