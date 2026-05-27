<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user()?->only('id', 'name', 'email', 'settings'),
            ],
            'app' => [
                'version' => config('app.version', '0.1.0'),
                'port'    => config('app.port', '7878'),
            ],
            'system' => fn () => [
                'db_driver'      => DB::connection()->getDriverName(),
                'db_version'     => $this->dbVersion(),
                'uptime_seconds' => $this->uptimeSeconds(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
        ];
    }

    private function dbVersion(): string
    {
        try {
            return DB::selectOne('SELECT VERSION() AS v')->v ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function uptimeSeconds(): int
    {
        // Linux/Docker: /proc/uptime first column = seconds since boot
        if (is_readable('/proc/uptime')) {
            return (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0];
        }
        // Fallback: remember first observation in cache, return seconds since then
        $bootedAt = \Illuminate\Support\Facades\Cache::rememberForever(
            'system:booted_at',
            fn () => time(),
        );
        return max(0, time() - $bootedAt);
    }
}
