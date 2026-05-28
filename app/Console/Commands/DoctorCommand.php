<?php

namespace App\Console\Commands;

use App\Models\BusinessProfile;
use App\Support\SwissIban;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'ernte:doctor
        {--json : Output machine-readable JSON}
        {--advisory : Print failures but exit successfully}';

    protected $description = 'Check the production runtime dependencies Ernte needs.';

    public function handle(): int
    {
        $checks = [
            $this->appKey(),
            $this->appUrl(),
            $this->database(),
            $this->tables(),
            $this->businessProfile(),
            $this->storage(),
            $this->queue(),
            $this->mail(),
            $this->chrome(),
            $this->mysqldump(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf('[%s] %s - %s', strtoupper($check['status']), $check['name'], $check['detail']));
            }
        }

        if ($this->option('advisory')) {
            return self::SUCCESS;
        }

        return collect($checks)->contains(fn (array $check) => $check['status'] === 'fail')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function appKey(): array
    {
        return filled(config('app.key'))
            ? $this->checkOk('APP_KEY', 'configured')
            : $this->checkFail('APP_KEY', 'missing; run php artisan key:generate --force');
    }

    private function appUrl(): array
    {
        $url = (string) config('app.url');

        if ($this->isProduction() && ! str_starts_with($url, 'https://')) {
            return $this->checkFail('APP_URL', "must be https in production; current value is {$url}");
        }

        return $this->checkOk('APP_URL', $url);
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->checkOk('database', config('database.default'));
        } catch (Throwable $e) {
            return $this->checkFail('database', $e->getMessage());
        }
    }

    private function tables(): array
    {
        $required = ['jobs', 'failed_jobs', 'backups', 'business_profile'];
        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));

        return $missing === []
            ? $this->checkOk('database tables', implode(', ', $required))
            : $this->checkFail('database tables', 'missing: '.implode(', ', $missing));
    }

    private function businessProfile(): array
    {
        try {
            $profile = BusinessProfile::query()->first();

            if (! $profile) {
                return $this->checkFail('business profile', 'missing; run php artisan db:seed --class=BootstrapSeeder --force');
            }

            if ($profile->qr_iban && ! SwissIban::isQrIban($profile->qr_iban)) {
                return $this->checkWarn('business profile', 'QR-IBAN field contains a normal IBAN; move it to IBAN or use a QR-IBAN with IID 30000-31999');
            }

            return $this->checkOk('business profile', 'seeded');
        } catch (Throwable $e) {
            return $this->checkFail('business profile', $e->getMessage());
        }
    }

    private function storage(): array
    {
        $paths = [
            storage_path('app/private'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
        ];

        $missing = array_values(array_filter($paths, fn (string $path) => ! is_dir($path) || ! is_writable($path)));

        return $missing === []
            ? $this->checkOk('storage', 'writable')
            : $this->checkFail('storage', 'not writable: '.implode(', ', $missing));
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default');

        if ($this->isProduction() && $connection === 'sync') {
            return $this->checkFail('queue', 'QUEUE_CONNECTION=sync cannot deliver reminder jobs in production');
        }

        return $connection === 'database'
            ? $this->checkOk('queue', 'database')
            : $this->checkWarn('queue', "using {$connection}; Forge runbook assumes database");
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        if ($this->isProduction() && in_array($mailer, ['array', 'log'], true)) {
            return $this->checkFail('mail', "MAIL_MAILER={$mailer} will not send invoice mail in production");
        }

        return in_array($mailer, ['array', 'log'], true)
            ? $this->checkWarn('mail', "MAIL_MAILER={$mailer}")
            : $this->checkOk('mail', "MAIL_MAILER={$mailer}");
    }

    private function chrome(): array
    {
        $path = config('services.browsershot.chrome_path');

        if ($path && is_executable($path)) {
            return $this->checkOk('chromium', $path);
        }

        if ($path) {
            return $this->requiredDependency('chromium', "BROWSERSHOT_CHROME_PATH is not executable: {$path}");
        }

        $found = $this->findExecutable(['google-chrome-stable', 'chromium', 'chromium-browser']);

        if ($found) {
            return $this->isProduction()
                ? $this->checkFail('chromium', "set BROWSERSHOT_CHROME_PATH={$found}")
                : $this->checkWarn('chromium', "found {$found}; BROWSERSHOT_CHROME_PATH is not set");
        }

        return $this->requiredDependency('chromium', 'not found; install Chrome/Chromium for Browsershot');
    }

    private function mysqldump(): array
    {
        $path = $this->findExecutable(['mysqldump']);

        return $path
            ? $this->checkOk('mysqldump', $path)
            : $this->requiredDependency('mysqldump', 'not found; install default-mysql-client for backups');
    }

    private function requiredDependency(string $name, string $detail): array
    {
        return $this->isProduction() ? $this->checkFail($name, $detail) : $this->checkWarn($name, $detail);
    }

    private function findExecutable(array $names): ?string
    {
        $finder = new ExecutableFinder;

        foreach ($names as $name) {
            if ($path = $finder->find($name)) {
                return $path;
            }
        }

        return null;
    }

    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    private function checkOk(string $name, string $detail): array
    {
        return compact('name', 'detail') + ['status' => 'ok'];
    }

    private function checkWarn(string $name, string $detail): array
    {
        return compact('name', 'detail') + ['status' => 'warn'];
    }

    private function checkFail(string $name, string $detail): array
    {
        return compact('name', 'detail') + ['status' => 'fail'];
    }
}
