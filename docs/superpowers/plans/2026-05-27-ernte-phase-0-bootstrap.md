# Ernte — Phase 0 (Bootstrap) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring up a Laravel + Inertia + Vue 3 app under DDEV with single-user auth seeded, the design's CSS tokens ported, and a navigable shell (topbar + sidebar + statusbar) on five placeholder pages. No business logic yet.

**Architecture:** DDEV for local dev (Laravel project type with a custom web-build image that adds Chromium for Browsershot later). Laravel 12 with Breeze Inertia+Vue starter. Self-registration disabled; user created by a `BootstrapSeeder` from `.env`. CSS ported verbatim from `design/ernte/project/styles.css`, then theme/density toggles wired through `data-theme` / `data-density` root attributes persisted to `users.settings`.

**Tech Stack:** PHP 8.3, Laravel 12, MariaDB 11.4, Inertia.js, Vue 3 (Composition API, `<script setup>`), Vite, Tailwind off / hand-rolled CSS, Pest, DDEV.

**Source spec:** `docs/superpowers/specs/2026-05-27-ernte-design.md`

**Prereqs on host:** DDEV ≥ v1.23, Docker, git. No host PHP/Node needed — everything runs in DDEV.

---

## File map for Phase 0

Created in this phase:

| Path | Responsibility |
|---|---|
| `.ddev/config.yaml` | DDEV project config (Laravel, PHP 8.3, MariaDB 11.4, daemons) |
| `.ddev/web-build/Dockerfile.chromium` | Adds Chromium to web container (for Browsershot in later phases) |
| `composer.json`, `package.json`, etc. | Standard Laravel 12 scaffold |
| `app/Providers/AppServiceProvider.php` | Disable self-registration route + share `$user.settings` to Inertia |
| `database/seeders/BootstrapSeeder.php` | Idempotently creates the singleton user from env |
| `database/migrations/0001_01_01_000005_add_settings_to_users.php` | Adds `settings` JSON column to `users` |
| `resources/css/app.css` | Imports design tokens + base styles |
| `resources/css/tokens.css` | Color, type, density CSS variables (ported from design) |
| `resources/css/base.css` | Reset + layout primitives ported from design |
| `resources/js/Layouts/AppLayout.vue` | App shell — slots in topbar / sidebar / statusbar / content |
| `resources/js/Components/Topbar.vue` | Wordmark, workspace label, ⌘K stub, user chip (timer chip stubbed) |
| `resources/js/Components/Sidebar.vue` | Nav (5 items), pinned/recent placeholders, week chart placeholder |
| `resources/js/Components/Statusbar.vue` | Real values: APP_PORT, version, DB driver/version, uptime |
| `resources/js/Components/TweaksPanel.vue` | Theme / density / accent toggle |
| `resources/js/composables/useTweaks.ts` | Reads settings shared prop, posts changes (debounced) |
| `resources/js/Pages/Projects/Index.vue` | Placeholder "Projects" page |
| `resources/js/Pages/Timer/Today.vue` | Placeholder "Timer" page |
| `resources/js/Pages/Clients/Index.vue` | Placeholder "Clients" page |
| `resources/js/Pages/Invoices/Index.vue` | Placeholder "Invoices" page |
| `resources/js/Pages/Reports/Placeholder.vue` | Reports placeholder |
| `routes/web.php` | Five nav routes returning placeholder Inertia pages |
| `app/Http/Middleware/HandleInertiaRequests.php` | Shares `auth.user.settings`, `app.version`, `app.port`, `system.db`, `system.uptime_seconds` |
| `tests/Feature/SmokeTest.php` | Renders every page once authenticated |
| `tests/Feature/SettingsTest.php` | Persists tweaks |
| `bin/install` | Idempotent setup script |
| `.env.example` | Documented env vars |
| `README.md` | Setup + dev loop instructions |

Modified:
- `routes/auth.php` — registration removed
- `resources/js/app.js` — wire layout + persistent layout pattern
- `app/Models/User.php` — cast `settings` to array

---

## Conventions used throughout this plan

- **Shell commands run inside DDEV** unless noted: `ddev artisan …`, `ddev composer …`, `ddev npm …`, `ddev exec …`. Host-side commands are written `host$ …`.
- **TDD where it pays off**: feature behavior (smoke render, settings persistence) gets tests first. Pure scaffolding (DDEV config, asset porting) verifies via run-it-and-look.
- **Commit after each task.** Commit messages: imperative mood, scoped prefix.

---

## Task 1: DDEV scaffold

**Files:**
- Create: `.ddev/config.yaml`
- Create: `.ddev/web-build/Dockerfile.chromium`

- [ ] **Step 1: Initialize DDEV config**

Run (host):
```
host$ ddev config --project-name=ernte --project-type=laravel --php-version=8.3 --database=mariadb:11.4 --docroot=public --webserver-type=nginx-fpm --nodejs-version=20
```
Expected: prints "Configuration complete." and creates `.ddev/config.yaml`.

- [ ] **Step 2: Customise `.ddev/config.yaml`**

Replace the generated file with:
```yaml
name: ernte
type: laravel
docroot: public
php_version: "8.3"
database:
  type: mariadb
  version: "11.4"
webserver_type: nginx-fpm
nodejs_version: "20"
router_http_port: "80"
router_https_port: "443"
web_environment:
  - APP_PORT=7878
web_extra_daemons:
  - name: scheduler
    command: "php artisan schedule:work"
    directory: /var/www/html
  - name: queue
    command: "php artisan queue:work --queue=default,emails --tries=3"
    directory: /var/www/html
```

- [ ] **Step 3: Add Chromium to the web container**

Create `.ddev/web-build/Dockerfile.chromium`:
```Dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium fonts-liberation libnss3 libatk-bridge2.0-0 libxkbcommon0 \
 && rm -rf /var/lib/apt/lists/*
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV BROWSERSHOT_CHROME_PATH=/usr/bin/chromium
```

- [ ] **Step 4: Start DDEV**

Run:
```
host$ ddev start
```
Expected: ends with "Successfully started ernte" and prints the project URL (e.g. `https://ernte.ddev.site`). The web container build will pull and install Chromium (slow first time).

- [ ] **Step 5: Verify Chromium is reachable in the container**

Run:
```
host$ ddev exec which chromium
```
Expected: `/usr/bin/chromium`.

- [ ] **Step 6: Commit**

```
host$ git add .ddev/
host$ git commit -m "chore: bootstrap DDEV with Laravel preset + Chromium web image"
```

---

## Task 2: Install Laravel 12

**Files:**
- Create: everything Laravel generates (`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `tests/`, `composer.json`, etc.)

- [ ] **Step 1: Create the Laravel project in the working directory**

Run:
```
host$ ddev composer create-project laravel/laravel:^12.0 tmp-laravel
host$ rsync -a tmp-laravel/ ./ && rm -rf tmp-laravel
```
Expected: a full Laravel skeleton sits next to `.ddev/` and `docs/`. `composer.json` shows `"laravel/framework": "^12.0"`.

- [ ] **Step 2: Configure `.env` for DDEV's MariaDB**

Edit `.env` so the DB block reads:
```
DB_CONNECTION=mariadb
DB_HOST=db
DB_PORT=3306
DB_DATABASE=db
DB_USERNAME=db
DB_PASSWORD=db
```
And set:
```
APP_NAME=Ernte
APP_URL=https://ernte.ddev.site
APP_TIMEZONE=Europe/Zurich
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

- [ ] **Step 3: Generate key, migrate, run**

Run:
```
host$ ddev artisan key:generate
host$ ddev artisan migrate
host$ ddev launch
```
Expected: browser opens `https://ernte.ddev.site` and shows Laravel's default welcome page.

- [ ] **Step 4: Commit**

```
host$ git add -A
host$ git commit -m "chore: install Laravel 12 skeleton"
```

---

## Task 3: Install Breeze (Inertia + Vue)

**Files:**
- Modified: many under `resources/`, `routes/`, `app/Http/`
- Created: Breeze stubs (Auth pages, middleware, etc.)

- [ ] **Step 1: Require Breeze**

Run:
```
host$ ddev composer require laravel/breeze --dev
```
Expected: composer adds breeze, returns 0.

- [ ] **Step 2: Install the Inertia+Vue stack**

Run:
```
host$ ddev artisan breeze:install vue --pest
```
Expected: prompts answered automatically; copies the Vue/Inertia preset, swaps PHPUnit for Pest.

- [ ] **Step 3: Install Node deps and build**

Run:
```
host$ ddev npm ci
host$ ddev npm run build
host$ ddev artisan migrate
```
Expected: Vite build completes; migrations run.

- [ ] **Step 4: Smoke check in the browser**

Open `https://ernte.ddev.site/login` — Breeze's login page should render.

- [ ] **Step 5: Commit**

```
host$ git add -A
host$ git commit -m "feat(auth): install Breeze Inertia+Vue starter with Pest"
```

---

## Task 4: Single-user mode — remove registration, seed bootstrap user

**Files:**
- Modify: `routes/auth.php`
- Modify: `app/Providers/RouteServiceProvider.php` (or wherever `HOME` is defined — repoint to `/projects`)
- Create: `database/seeders/BootstrapSeeder.php`
- Create: `database/migrations/0001_01_01_000005_add_settings_to_users.php`
- Modify: `app/Models/User.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `.env.example`
- Test: `tests/Feature/AuthRestrictionTest.php`

- [ ] **Step 1: Write the failing test — registration route should 404**

Create `tests/Feature/AuthRestrictionTest.php`:
```php
<?php

use function Pest\Laravel\get;

test('registration route is removed', function () {
    get('/register')->assertNotFound();
});

test('seeded user can log in and lands on /projects', function () {
    $this->seed(\Database\Seeders\BootstrapSeeder::class);

    $this->post('/login', [
        'email' => 'owner@ernte.local',
        'password' => 'changeme',
    ])->assertRedirect('/projects');
});
```

- [ ] **Step 2: Run the test to confirm it fails**

Run:
```
host$ ddev artisan test --filter=AuthRestriction
```
Expected: both tests FAIL — first because `/register` returns 200, second because the seeder doesn't exist.

- [ ] **Step 3: Strip registration from `routes/auth.php`**

Open `routes/auth.php`. Delete the guest-group lines that import `RegisteredUserController` and define `GET /register` and `POST /register`. Also delete the `use App\Http\Controllers\Auth\RegisteredUserController;` line.

- [ ] **Step 3b: Repoint post-login redirect to `/projects`**

Breeze redirects to `/dashboard` by default. We want `/projects`. In Laravel 12 with Breeze the redirect target lives in `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — its `store()` calls `redirect()->intended(route('dashboard', absolute: false))`. Change `'dashboard'` to `'projects.index'`. (The named route `projects.index` arrives in Task 8; the test will run after that. If you run tests between Task 4 and Task 8, you can temporarily change the redirect to `/projects` as a string instead of `route('projects.index')`.)

Also delete or repoint any other `/dashboard` references in `routes/web.php` if Breeze added them — they'll be replaced wholesale in Task 8.

- [ ] **Step 4: Add `settings` column migration**

Create `database/migrations/0001_01_01_000005_add_settings_to_users.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
```

- [ ] **Step 5: Cast settings on `User`**

In `app/Models/User.php`, add `'settings' => 'array'` to the `casts()` method:
```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'settings' => 'array',
    ];
}
```
Also add `'settings'` to `$fillable`.

- [ ] **Step 6: Write `BootstrapSeeder`**

Create `database/seeders/BootstrapSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ERNTE_USER_EMAIL', 'owner@ernte.local');
        $name = env('ERNTE_USER_NAME', 'Owner');
        $password = env('ERNTE_USER_PASSWORD', 'changeme');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'settings' => [
                    'theme' => 'paper',
                    'density' => 'comfortable',
                    'accent' => '#2d4a3a',
                ],
            ]
        );
    }
}
```

- [ ] **Step 7: Wire seeder into `DatabaseSeeder`**

Edit `database/seeders/DatabaseSeeder.php`'s `run()`:
```php
public function run(): void
{
    $this->call(BootstrapSeeder::class);
}
```

- [ ] **Step 8: Add env vars to `.env.example`**

Append to `.env.example`:
```
ERNTE_USER_EMAIL=owner@ernte.local
ERNTE_USER_NAME=Owner
ERNTE_USER_PASSWORD=changeme
```
And mirror them in `.env` so the seeder picks them up locally.

- [ ] **Step 9: Run migrations and seed, then run tests**

```
host$ ddev artisan migrate:fresh --seed
host$ ddev artisan test --filter=AuthRestriction
```
Expected: both tests PASS.

- [ ] **Step 10: Commit**

```
host$ git add -A
host$ git commit -m "feat(auth): single-user mode — disable registration, seed bootstrap user, add settings column"
```

---

## Task 5: Pin JetBrains Mono via Bunny Fonts (self-hosted, no Google calls)

**Files:**
- Modify: `resources/views/app.blade.php`

- [ ] **Step 1: Replace Google Fonts link with Bunny Fonts**

Open `resources/views/app.blade.php`. In `<head>`, replace any `fonts.googleapis.com` link with:
```html
<link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600,700&display=swap" rel="stylesheet">
```
(Bunny serves the same files GDPR-safe; no API key needed.)

- [ ] **Step 2: Verify font loads**

Run:
```
host$ ddev npm run dev
```
Open `https://ernte.ddev.site/login` in a browser, inspect a heading — `font-family` should resolve to `"JetBrains Mono"`.

- [ ] **Step 3: Commit**

```
host$ git add resources/views/app.blade.php
host$ git commit -m "chore(ui): pin JetBrains Mono via Bunny Fonts"
```

---

## Task 6: Port design tokens to CSS

**Files:**
- Create: `resources/css/tokens.css`
- Create: `resources/css/base.css`
- Modify: `resources/css/app.css`

- [ ] **Step 1: Create `tokens.css`**

Create `resources/css/tokens.css`:
```css
:root,
:root[data-theme="paper"] {
  --paper: #f5f1ea;
  --paper-2: #efe9dc;
  --ink: #1a1a1a;
  --ink-2: #3d3d3d;
  --ink-3: #6b6b6b;
  --ink-4: #9a9a9a;
  --bg-3: #efe9dc;
  --border: #e8e1d4;
  --border-strong: #c9c0ad;
  --forest: #2d4a3a;
  --rust: #c97b3c;
  --red: #b54834;
  --gold: #b8941f;
  --accent: var(--forest);
}

:root[data-theme="dark"] {
  --paper: #1a1a1a;
  --paper-2: #232323;
  --ink: #f5f1ea;
  --ink-2: #c9c0ad;
  --ink-3: #9a9a9a;
  --ink-4: #6b6b6b;
  --bg-3: #232323;
  --border: #2d2d2d;
  --border-strong: #3d3d3d;
  --forest: #6b8c7a;
  --rust: #e09a5f;
  --red: #d36651;
  --gold: #d4af4a;
  --accent: var(--forest);
}

:root[data-density="comfortable"] {
  --row-h: 36px;
  --pad-y: 10px;
  --pad-x: 14px;
}

:root[data-density="compact"] {
  --row-h: 28px;
  --pad-y: 6px;
  --pad-x: 10px;
}

:root {
  --fs-xs: 11px;
  --fs-sm: 13px;
  --fs-md: 15px;
  --fs-lg: 24px;
  --fs-xl: 36px;
  --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
}
```

- [ ] **Step 2: Create `base.css` (reset + layout primitives)**

Create `resources/css/base.css` by copying *only* the non-component sections from `design/ernte/project/styles.css` — reset, body, `.app`, `.topbar`, `.sidebar`, `.content`, `.statusbar`, `.kbd`, `.mono-tag`, `.dim`, `.muted`, `.btn`, `.btn.primary`, `.btn.ghost`, `.chip`, `.search`, `.page-head`, `.crumb`, `.page-title`, `.filter-row`, `.section-title`. Replace any hardcoded color hex with the `var(--…)` from tokens.css.

For first pass copy verbatim, then sweep find/replace hex → var. Reference file: `design/ernte/project/styles.css`.

- [ ] **Step 3: Wire `app.css`**

Replace the entire `resources/css/app.css` with:
```css
@import './tokens.css';
@import './base.css';

html, body { background: var(--paper); color: var(--ink); }
body { font-family: var(--font-mono); font-size: var(--fs-sm); }
```
Remove any `@tailwind` directives.

- [ ] **Step 4: Disable Tailwind in `vite.config.js`**

If `tailwindcss` is in `postcss.config.js`, remove it. If `tailwind.config.js` exists, delete it. Run:
```
host$ ddev npm uninstall tailwindcss @tailwindcss/forms autoprefixer postcss
```

- [ ] **Step 5: Visual sanity check**

```
host$ ddev npm run build
```
Open `https://ernte.ddev.site/login` — page should have paper background (`#f5f1ea`), JetBrains Mono everywhere. Form styling will be plain (we'll restyle next phase) but readable.

- [ ] **Step 6: Commit**

```
host$ git add resources/css/ vite.config.js postcss.config.js package.json package-lock.json
host$ git commit -m "feat(ui): port design tokens and base CSS, drop Tailwind"
```

---

## Task 7: Share user settings + system info as Inertia props

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/InertiaPropsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InertiaPropsTest.php`:
```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared props include user settings and system info', function () {
    $user = User::factory()->create([
        'settings' => ['theme' => 'dark', 'density' => 'compact', 'accent' => '#c97b3c'],
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.settings.theme', 'dark')
            ->where('auth.user.settings.density', 'compact')
            ->has('app.version')
            ->has('app.port')
            ->has('system.db_driver')
            ->has('system.db_version')
            ->has('system.uptime_seconds')
        );
});
```

- [ ] **Step 2: Run the test — should fail**

```
host$ ddev artisan test --filter=InertiaProps
```
Expected: FAIL — `app.version` etc. not in shared props.

- [ ] **Step 3: Implement shared props**

Open `app/Http/Middleware/HandleInertiaRequests.php`. Replace `share()`:
```php
public function share(Request $request): array
{
    $appBootedAt = config('app.booted_at') ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));

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
            'db_driver'      => \DB::connection()->getDriverName(),
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
        return \DB::selectOne('SELECT VERSION() AS v')->v ?? 'unknown';
    } catch (\Throwable) {
        return 'unknown';
    }
}
```

Add to `config/app.php`:
```php
'version' => env('APP_VERSION', '0.1.0'),
```

- [ ] **Step 4: Run the test — should pass**

```
host$ ddev artisan test --filter=InertiaProps
```
Expected: PASS.

- [ ] **Step 5: Commit**

```
host$ git add app/Http/Middleware/HandleInertiaRequests.php config/app.php tests/Feature/InertiaPropsTest.php
host$ git commit -m "feat(inertia): share user settings + app/system info as global props"
```

---

## Task 8: Routes + placeholder pages

**Files:**
- Modify: `routes/web.php`
- Create: 5 Inertia page Vue files

- [ ] **Step 1: Update web routes**

Replace `routes/web.php` with:
```php
<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => redirect('/projects'));

    Route::get('/projects', fn () => Inertia::render('Projects/Index'))->name('projects.index');
    Route::get('/timer',    fn () => Inertia::render('Timer/Today'))->name('timer.show');
    Route::get('/clients',  fn () => Inertia::render('Clients/Index'))->name('clients.index');
    Route::get('/invoices', fn () => Inertia::render('Invoices/Index'))->name('invoices.index');
    Route::get('/reports',  fn () => Inertia::render('Reports/Placeholder'))->name('reports.show');
});

require __DIR__.'/auth.php';
```

Delete the Breeze-generated `/dashboard` route block (or keep `/dashboard` aliased to `/projects` — either way, the existing route should be removed or replaced).

- [ ] **Step 2: Create the 5 placeholder pages**

Create each Vue page using this template (substitute the title):

`resources/js/Pages/Projects/Index.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
defineOptions({ layout: AppLayout });
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / projects</div>
      <h1 class="page-title">Projects</h1>
    </div>
  </div>
  <div style="padding: 28px; color: var(--ink-3)">
    <div style="border: 1px dashed var(--border-strong); padding: 48px; text-align: center">
      Projects view — coming in Phase 2.
    </div>
  </div>
</template>
```

Repeat for:
- `resources/js/Pages/Timer/Today.vue` (crumb `~ / timer`, title `Today`, body "Timer view — coming in Phase 2.")
- `resources/js/Pages/Clients/Index.vue` (crumb `~ / clients`, title `Clients`)
- `resources/js/Pages/Invoices/Index.vue` (crumb `~ / invoices`, title `Invoices`)
- `resources/js/Pages/Reports/Placeholder.vue` (crumb `~ / reports`, title `Reports`)

(`AppLayout.vue` doesn't exist yet — Task 9. The pages will fail to render until then, that's fine.)

- [ ] **Step 3: Commit**

```
host$ git add routes/web.php resources/js/Pages/
host$ git commit -m "feat(routes): five nav routes with placeholder pages"
```

---

## Task 9: AppLayout + shell components

**Files:**
- Create: `resources/js/Layouts/AppLayout.vue`
- Create: `resources/js/Components/Topbar.vue`
- Create: `resources/js/Components/Sidebar.vue`
- Create: `resources/js/Components/Statusbar.vue`
- Modify: `resources/js/app.js` (use persistent layout pattern)

- [ ] **Step 1: Wire persistent layout in `app.js`**

Open `resources/js/app.js`. After Inertia is created, set the default layout resolver:
```js
createInertiaApp({
  // ...existing code
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
    const page = pages[`./Pages/${name}.vue`];
    return page;
  },
  // ...
});
```
(If the file already uses `resolvePageComponent` from Laravel Vite, leave as-is — page components themselves declare their layout via `defineOptions({ layout })`.)

- [ ] **Step 2: Create `AppLayout.vue`**

`resources/js/Layouts/AppLayout.vue`:
```vue
<script setup lang="ts">
import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Topbar from '@/Components/Topbar.vue';
import Sidebar from '@/Components/Sidebar.vue';
import Statusbar from '@/Components/Statusbar.vue';

const page = usePage();
const settings = computed(() => page.props.auth?.user?.settings ?? {
  theme: 'paper', density: 'comfortable', accent: '#2d4a3a',
});

function applyTokens() {
  const r = document.documentElement;
  r.setAttribute('data-theme', settings.value.theme);
  r.setAttribute('data-density', settings.value.density);
  r.style.setProperty('--accent', settings.value.accent);
}

watch(settings, applyTokens, { immediate: true, deep: true });
</script>

<template>
  <div class="app">
    <Topbar />
    <Sidebar />
    <main class="content">
      <slot />
    </main>
    <Statusbar />
  </div>
</template>
```

- [ ] **Step 3: Create `Topbar.vue` (shell — no live timer yet)**

`resources/js/Components/Topbar.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const initials = computed(() => {
  const n = user.value?.name ?? '?';
  return n.split(/\s+/).map((p: string) => p[0]).slice(0, 2).join('').toUpperCase();
});
</script>

<template>
  <header class="topbar">
    <Link href="/projects" class="wordmark">
      <span class="wordmark-mark" />
      <span>ernte</span>
    </Link>
    <div class="mono-tag" title="Workspace">workspace: {{ user?.name?.toLowerCase() ?? 'guest' }}@ernte</div>
    <div class="topbar-spacer" />
    <button class="cmdk" title="Command palette (coming soon)" disabled>
      <span style="color: var(--ink-4)">›</span>
      <span style="flex: 1; text-align: left">Jump to project, client, invoice…</span>
      <span class="kbd">⌘K</span>
    </button>
    <div class="topbar-spacer" />
    <!-- running timer chip stub: real one lands in Phase 2 -->
    <div class="user-chip">
      <span class="avatar">{{ initials }}</span>
      <span>{{ user?.name ?? 'guest' }}</span>
    </div>
  </header>
</template>
```

- [ ] **Step 4: Create `Sidebar.vue` (shell — no week chart yet)**

`resources/js/Components/Sidebar.vue`:
```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const NAV = [
  { id: 'projects', href: '/projects', label: 'Projects', glyph: '▤' },
  { id: 'timer',    href: '/timer',    label: 'Timer',    glyph: '◐' },
  { id: 'clients',  href: '/clients',  label: 'Clients',  glyph: '◇' },
  { id: 'invoices', href: '/invoices', label: 'Invoices', glyph: '≡' },
  { id: 'reports',  href: '/reports',  label: 'Reports',  glyph: '△' },
];

const page = usePage();
const current = computed(() => page.url);
const isActive = (href: string) => current.value.startsWith(href);
</script>

<template>
  <aside class="sidebar">
    <nav>
      <Link
        v-for="n in NAV" :key="n.id"
        :href="n.href"
        class="nav-item"
        :aria-current="isActive(n.href) ? 'page' : undefined"
      >
        <span class="glyph">{{ n.glyph }}</span>
        <span>{{ n.label }}</span>
      </Link>
    </nav>

    <div class="side-section">Pinned</div>
    <div class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">No pinned projects yet</div>

    <div class="side-section">This week</div>
    <div class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">Coming in Phase 2</div>
  </aside>
</template>
```

- [ ] **Step 5: Create `Statusbar.vue` with real values**

`resources/js/Components/Statusbar.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const app = computed(() => page.props.app);
const sys = computed(() => page.props.system);

const uptime = computed(() => {
  const s = sys.value?.uptime_seconds ?? 0;
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  return d > 0 ? `${d}d ${h}h` : `${h}h`;
});
</script>

<template>
  <footer class="statusbar">
    <span><span class="dot" />connected</span>
    <span class="sep">│</span>
    <span>localhost<span class="muted">:{{ app?.port }}</span></span>
    <span class="sep">│</span>
    <span>v{{ app?.version }} <span class="muted">(self-hosted)</span></span>
    <span class="sep">│</span>
    <span>db <span class="muted">{{ sys?.db_driver }} {{ sys?.db_version }}</span></span>
    <span class="spacer" />
    <span class="muted">uptime {{ uptime }}</span>
  </footer>
</template>
```

- [ ] **Step 6: Build and visually verify**

```
host$ ddev npm run build
```
Visit `/projects` — should see the shell with topbar, sidebar (Projects highlighted), statusbar with real DB version + uptime, and the placeholder "Projects view — coming in Phase 2." card.

- [ ] **Step 7: Commit**

```
host$ git add resources/js/Layouts/ resources/js/Components/ resources/js/app.js
host$ git commit -m "feat(ui): AppLayout shell with topbar, sidebar, statusbar"
```

---

## Task 10: TweaksPanel + settings persistence

**Files:**
- Create: `resources/js/Components/TweaksPanel.vue`
- Create: `resources/js/composables/useTweaks.ts`
- Modify: `resources/js/Layouts/AppLayout.vue` (mount TweaksPanel)
- Modify: `resources/js/Components/Topbar.vue` (gear button)
- Create: `app/Http/Controllers/SettingsController.php`
- Modify: `routes/web.php` (PATCH /settings/tweaks)
- Test: `tests/Feature/SettingsTweaksTest.php`

- [ ] **Step 1: Write the failing controller test**

Create `tests/Feature/SettingsTweaksTest.php`:
```php
<?php

use App\Models\User;

test('user can update tweaks settings', function () {
    $user = User::factory()->create([
        'settings' => ['theme' => 'paper', 'density' => 'comfortable', 'accent' => '#2d4a3a'],
    ]);

    $this->actingAs($user)
        ->patch('/settings/tweaks', [
            'theme' => 'dark',
            'density' => 'compact',
            'accent' => '#c97b3c',
        ])
        ->assertRedirect();

    expect($user->fresh()->settings)->toMatchArray([
        'theme' => 'dark',
        'density' => 'compact',
        'accent' => '#c97b3c',
    ]);
});

test('invalid theme is rejected', function () {
    $user = User::factory()->create(['settings' => ['theme' => 'paper']]);

    $this->actingAs($user)
        ->patch('/settings/tweaks', ['theme' => 'neon-pink'])
        ->assertSessionHasErrors('theme');
});
```

- [ ] **Step 2: Run — should fail**

```
host$ ddev artisan test --filter=SettingsTweaks
```
Expected: FAIL (404 — route doesn't exist).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/SettingsController.php`:
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function updateTweaks(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme'   => 'sometimes|in:paper,dark',
            'density' => 'sometimes|in:comfortable,compact',
            'accent'  => 'sometimes|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $user = $request->user();
        $user->settings = array_merge($user->settings ?? [], $data);
        $user->save();

        return back();
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the auth group:
```php
Route::patch('/settings/tweaks', [\App\Http\Controllers\SettingsController::class, 'updateTweaks'])
    ->name('settings.tweaks');
```

- [ ] **Step 5: Run — should pass**

```
host$ ddev artisan test --filter=SettingsTweaks
```
Expected: PASS.

- [ ] **Step 6: Create `useTweaks` composable**

`resources/js/composables/useTweaks.ts`:
```ts
import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

type Settings = { theme: 'paper' | 'dark'; density: 'comfortable' | 'compact'; accent: string };

let timeout: ReturnType<typeof setTimeout> | null = null;

export function useTweaks() {
  const page = usePage();
  const current = (page.props.auth?.user?.settings ?? {}) as Settings;
  const settings = ref<Settings>({
    theme: current.theme ?? 'paper',
    density: current.density ?? 'comfortable',
    accent: current.accent ?? '#2d4a3a',
  });

  function set<K extends keyof Settings>(key: K, value: Settings[K]) {
    settings.value[key] = value;
  }

  watch(settings, (val) => {
    if (timeout) clearTimeout(timeout);
    timeout = setTimeout(() => {
      router.patch('/settings/tweaks', val, { preserveScroll: true, preserveState: true });
    }, 500);
  }, { deep: true });

  return { settings, set };
}
```

- [ ] **Step 7: Create `TweaksPanel.vue`**

`resources/js/Components/TweaksPanel.vue`:
```vue
<script setup lang="ts">
import { ref } from 'vue';
import { useTweaks } from '@/composables/useTweaks';

const { settings, set } = useTweaks();
const open = ref(false);

const ACCENTS = ['#2d4a3a', '#c97b3c', '#1a1a1a', '#b8941f'];
</script>

<template>
  <button
    class="tweaks-toggle"
    :aria-expanded="open"
    @click="open = !open"
    title="Tweaks"
  >☰</button>

  <aside v-if="open" class="tweaks-panel">
    <div class="section-title">Theme</div>
    <div class="tweaks-row">
      <button
        v-for="t in ['paper', 'dark'] as const" :key="t"
        :aria-pressed="settings.theme === t"
        class="chip"
        @click="set('theme', t)"
      >{{ t }}</button>
    </div>

    <div class="section-title">Density</div>
    <div class="tweaks-row">
      <button
        v-for="d in ['comfortable', 'compact'] as const" :key="d"
        :aria-pressed="settings.density === d"
        class="chip"
        @click="set('density', d)"
      >{{ d }}</button>
    </div>

    <div class="section-title">Accent</div>
    <div class="tweaks-row">
      <button
        v-for="a in ACCENTS" :key="a"
        class="accent-swatch"
        :aria-pressed="settings.accent === a"
        :style="{ background: a }"
        @click="set('accent', a)"
      />
    </div>
  </aside>
</template>

<style scoped>
.tweaks-toggle { position: fixed; right: 16px; bottom: 28px; z-index: 50; border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 10px; font-family: var(--font-mono); cursor: pointer; }
.tweaks-panel { position: fixed; right: 16px; bottom: 64px; width: 240px; border: 1px solid var(--border-strong); background: var(--paper); padding: 14px; z-index: 50; }
.tweaks-row { display: flex; gap: 6px; margin: 8px 0 16px; }
.accent-swatch { width: 24px; height: 24px; border: 2px solid var(--border); cursor: pointer; }
.accent-swatch[aria-pressed="true"] { border-color: var(--ink); }
</style>
```

- [ ] **Step 8: Mount in `AppLayout.vue`**

Add to `AppLayout.vue` template, after `<Statusbar />`:
```vue
<TweaksPanel />
```
And the import:
```js
import TweaksPanel from '@/Components/TweaksPanel.vue';
```

- [ ] **Step 9: Visual verification**

```
host$ ddev npm run dev
```
Visit `/projects`, click the ☰ button bottom-right. Toggle dark mode — page should invert. Toggle compact — row heights should shrink. Pick a different accent — `--accent` value changes. Reload — settings persist (came from DB).

- [ ] **Step 10: Commit**

```
host$ git add -A
host$ git commit -m "feat(ui): tweaks panel with theme/density/accent persistence"
```

---

## Task 11: Smoke test all five pages

**Files:**
- Create: `tests/Feature/SmokeTest.php`

- [ ] **Step 1: Write the smoke test**

`tests/Feature/SmokeTest.php`:
```php
<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('redirect from / to /projects', function () {
    $this->actingAs($this->user)->get('/')->assertRedirect('/projects');
});

dataset('pages', [
    ['/projects', 'Projects'],
    ['/timer',    'Today'],
    ['/clients',  'Clients'],
    ['/invoices', 'Invoices'],
    ['/reports',  'Reports'],
]);

test('authenticated user can load $route', function (string $route, string $title) {
    $this->actingAs($this->user)
        ->get($route)
        ->assertOk()
        ->assertSee($title);
})->with('pages');

test('unauthenticated user is redirected to login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run — should pass**

```
host$ ddev artisan test --filter=Smoke
```
Expected: 7 PASS.

- [ ] **Step 3: Run the full suite**

```
host$ ddev artisan test
```
Expected: all green.

- [ ] **Step 4: Commit**

```
host$ git add tests/Feature/SmokeTest.php
host$ git commit -m "test: smoke-test all five nav pages"
```

---

## Task 12: `bin/install` + README

**Files:**
- Create: `bin/install`
- Create or update: `README.md`

- [ ] **Step 1: Create `bin/install`**

`bin/install`:
```bash
#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  cp .env.example .env
  echo "→ created .env from .env.example"
fi

composer install --no-interaction --prefer-dist
npm ci
npm run build

php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --class=BootstrapSeeder --force --no-interaction

echo
echo "✓ Ernte ready. Log in with the credentials in .env (ERNTE_USER_*)."
```
Make executable:
```
host$ chmod +x bin/install
```

- [ ] **Step 2: Write the README**

`README.md`:
```markdown
# Ernte

Self-hosted time tracking & invoicing. Single user. Swiss QR-bill on invoices.

## Local development (DDEV)

```bash
git clone <repo> ernte && cd ernte
ddev start                  # builds web image with Chromium
ddev exec bin/install       # composer + npm + migrate + seed
ddev npm run dev            # vite watcher
ddev launch                 # opens browser
```

Default login: see `ERNTE_USER_*` in your `.env`.

## Tests

```bash
ddev artisan test
```

## Production deploy

See `docs/superpowers/specs/2026-05-27-ernte-design.md` § 8 (docker-compose).
```

- [ ] **Step 3: Smoke-test the install script**

```
host$ ddev artisan migrate:fresh
host$ ddev exec bin/install
```
Expected: ends with "✓ Ernte ready."

- [ ] **Step 4: Commit**

```
host$ git add bin/install README.md
host$ git commit -m "chore: bin/install script and README"
```

---

## Task 13: Verify the full Phase 0 outcome

- [ ] **Step 1: Full reset**

```
host$ ddev artisan migrate:fresh --seed
host$ ddev npm run build
```

- [ ] **Step 2: Browser walkthrough**

Open the app URL. Verify in order:
- Redirected to `/login` when logged out.
- Logged in with seeded credentials.
- Redirected to `/projects`.
- Click each nav item: Projects, Timer, Clients, Invoices, Reports — each renders its placeholder card with the correct breadcrumb (`~ / <name>`) and title.
- Sidebar highlights the current nav item.
- Statusbar shows real DB version (`mariadb 11.4.x`) and ticking-ish uptime.
- Open Tweaks (☰): toggle dark → page inverts; toggle compact → rows tighten; pick rust accent → accent swatches reflect choice.
- Reload — settings persist.

- [ ] **Step 3: Run full test suite**

```
host$ ddev artisan test
```
Expected: all PASS.

- [ ] **Step 4: Tag the phase**

```
host$ git tag -a phase-0 -m "Phase 0 (Bootstrap) complete"
```

---

## What's next (not in this plan)

Phase 1 (schema + domain), Phase 2 (views), Phase 3 (production package) will be written as their own plans once Phase 0 is complete and reviewed. They live next to this file:
- `docs/superpowers/plans/2026-05-27-ernte-phase-1-schema.md` *(not yet written)*
- `docs/superpowers/plans/2026-05-27-ernte-phase-2-views.md` *(not yet written)*
- `docs/superpowers/plans/2026-05-27-ernte-phase-3-deploy.md` *(not yet written)*

Reason for splitting: each phase is a useful checkpoint where the app is in a deployable, reviewable state, and the plans for later phases will be sharper once we've seen Phase 0 land.
