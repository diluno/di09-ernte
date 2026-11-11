<?php

namespace App\Services\Backups;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** A nightly backup that fails must not go unnoticed until the day it is needed. */
class BackupFailureAlert
{
    public function __invoke(): void
    {
        $last = Backup::query()->max('created_at');
        $message = 'The scheduled backup (ernte:backup) failed. Last good backup: '.($last ?? 'none').'. '
            .'Run "php artisan ernte:backup" on the server to see why.';
        Log::error($message);

        $owner = User::query()->orderBy('id')->value('email');
        if (! $owner || in_array(config('mail.default'), ['array', 'log', null], true)) {
            return;
        }

        try {
            Mail::raw($message, fn ($mail) => $mail->to($owner)->subject('['.config('app.name').'] Backup failed'));
        } catch (\Throwable $e) {
            Log::error('The backup failure mail could not be sent: '.$e->getMessage());
        }
    }
}
