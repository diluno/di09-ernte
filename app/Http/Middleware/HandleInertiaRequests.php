<?php

namespace App\Http\Middleware;

use App\Models\BusinessProfile;
use App\Support\SidebarProps;
use Illuminate\Http\Request;
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
                'host' => parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost',
                'port' => config('app.port', '7878'),
            ],
            'business' => fn () => [
                'name' => BusinessProfile::query()->value('name'),
            ],
            'running_entry' => fn () => $request->user()
                ? SidebarProps::runningEntry($request->user())
                : null,
            'sidebar' => fn () => $request->user()
                ? SidebarProps::sidebar($request->user())
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
