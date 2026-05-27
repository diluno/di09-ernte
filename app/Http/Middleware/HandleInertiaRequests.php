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
        $appBootedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user()?->only('id', 'name', 'email', 'settings'),
            ],
            'app' => [
                'version' => config('app.version', '0.1.0'),
                'port'    => env('APP_PORT', '7878'),
            ],
            'system' => fn () => [
                'db_driver'      => DB::connection()->getDriverName(),
                'db_version'     => $this->dbVersion(),
                'uptime_seconds' => (int) (microtime(true) - $appBootedAt),
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
}
