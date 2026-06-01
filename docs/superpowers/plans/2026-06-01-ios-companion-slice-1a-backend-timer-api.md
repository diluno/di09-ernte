# iOS Companion — Slice 1a: Backend API Foundation + Timer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a token-authenticated JSON API to the existing ernte Laravel app, exposing auth (token issue/revoke), the current user, and the full timer feature — the backend the iOS app's Timer tab will consume.

**Architecture:** Additive `/api` routes behind `auth:sanctum`, issued via Sanctum personal access tokens. API controllers are thin wrappers that reuse the existing `TimerService` and `App\Support\TimerToday` projection, returning JSON instead of Inertia. The existing web app is untouched.

**Tech Stack:** Laravel 12, Laravel Sanctum 4 (already in `composer.json`), Pest 4. All artisan/test commands run through DDEV (`ddev artisan …`).

**Conventions from this repo:**
- Tests use Pest with `RefreshDatabase` (configured in `tests/Pest.php` for `Feature`).
- `User::factory()` creates users with password `password`.
- Authenticate API requests in tests with `Laravel\Sanctum\Sanctum::actingAs($user)`.
- Run a single test file: `ddev artisan test tests/Feature/<path>` ; filter: `ddev artisan test --filter='<name>'`.

---

## Task 1: Install the API scaffolding (Sanctum + api routing)

**Files:**
- Modify: `bootstrap/app.php` (adds `api:` routing — done by the installer)
- Create: `routes/api.php` (created by the installer)
- Create: `database/migrations/*_create_personal_access_tokens_table.php` (published by the installer)

- [ ] **Step 1: Run the API installer**

Sanctum is already required in `composer.json`. The installer publishes the token migration, creates `routes/api.php`, and registers `api:` routing in `bootstrap/app.php`.

Run:
```bash
ddev artisan install:api --no-interaction
```

- [ ] **Step 2: Run the migration**

Run:
```bash
ddev artisan migrate
```
Expected: `personal_access_tokens` table created.

- [ ] **Step 3: Verify api routing is registered**

Run:
```bash
ddev artisan route:list --path=api
```
Expected: at least the installer's default `GET api/user` route appears. (We will replace that default route in Task 3.)

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php routes/api.php database/migrations
git commit -m "chore(api): install sanctum api scaffolding"
```

---

## Task 2: Give the User model API tokens

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Http/Api/AuthTokenTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Api/AuthTokenTest.php`:

```php
<?php

use App\Models\User;

test('a user can create an api token', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-device')->plainTextToken;

    expect($token)->toBeString()->not->toBeEmpty();
    expect($user->tokens()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/AuthTokenTest.php
```
Expected: FAIL — `Call to undefined method App\Models\User::createToken()`.

- [ ] **Step 3: Add the HasApiTokens trait**

In `app/Models/User.php`, add the import and trait:

```php
use Laravel\Sanctum\HasApiTokens;
```

Change the `use` line inside the class from:
```php
    use HasFactory, Notifiable;
```
to:
```php
    use HasApiTokens, HasFactory, Notifiable;
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/AuthTokenTest.php
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Http/Api/AuthTokenTest.php
git commit -m "feat(api): add HasApiTokens to User"
```

---

## Task 3: Token auth endpoints (`POST`/`DELETE /api/auth/token`)

**Files:**
- Create: `app/Http/Controllers/Api/AuthController.php`
- Create: `app/Http/Requests/Api/TokenRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/AuthTokenTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Api/AuthTokenTest.php`:

```php
test('POST /api/auth/token returns a token for valid credentials', function () {
    $user = User::factory()->create(['email' => 'me@ernte.local']);

    $response = $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'password',
        'device_name' => 'iPhone',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    expect($user->fresh()->tokens()->count())->toBe(1);
});

test('POST /api/auth/token rejects invalid credentials with 422', function () {
    User::factory()->create(['email' => 'me@ernte.local']);

    $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'wrong-password',
        'device_name' => 'iPhone',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('POST /api/auth/token requires device_name', function () {
    $this->postJson('/api/auth/token', [
        'email' => 'me@ernte.local',
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('device_name');
});

test('DELETE /api/auth/token revokes the current token', function () {
    $user = User::factory()->create();
    // Authenticate with a REAL token (not Sanctum::actingAs, whose transient
    // token has no ->delete()), so currentAccessToken() resolves a deletable row.
    $plain = $user->createToken('iPhone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->deleteJson('/api/auth/token')
        ->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/AuthTokenTest.php
```
Expected: FAIL — route `/api/auth/token` not defined (404/405).

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/Api/TokenRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Api/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function store(TokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($request->string('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api.php`, remove the installer's default `Route::get('/user', …)` block and add:

```php
use App\Http\Controllers\Api\AuthController;

Route::post('/auth/token', [AuthController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/auth/token', [AuthController::class, 'destroy']);
});
```

- [ ] **Step 6: Run tests to verify they pass**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/AuthTokenTest.php
```
Expected: PASS (all 5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/AuthController.php app/Http/Requests/Api/TokenRequest.php routes/api.php tests/Feature/Http/Api/AuthTokenTest.php
git commit -m "feat(api): token issue and revoke endpoints"
```

---

## Task 4: Current-user endpoint (`GET /api/me`)

**Files:**
- Create: `app/Http/Controllers/Api/MeController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/MeTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Api/MeTest.php`:

```php
<?php

use App\Models\BusinessProfile;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/me requires authentication', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

test('GET /api/me returns the user and business basics', function () {
    BusinessProfile::create([
        'name' => 'Diluno GmbH',
        'default_currency' => 'CHF',
    ]);
    $user = User::factory()->create(['name' => 'Sam', 'email' => 'sam@ernte.local']);
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJson([
            'user' => ['name' => 'Sam', 'email' => 'sam@ernte.local'],
            'business' => ['name' => 'Diluno GmbH', 'currency' => 'CHF'],
        ]);
});

test('GET /api/me tolerates a missing business profile', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJson(['business' => null]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/MeTest.php
```
Expected: FAIL — `/api/me` not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/MeController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = BusinessProfile::query()->first();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'business' => $profile ? [
                'name' => $profile->name,
                'currency' => $profile->default_currency,
            ] : null,
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the existing `Route::middleware('auth:sanctum')->group(...)`, add:

```php
    Route::get('/me', [\App\Http\Controllers\Api\MeController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/MeTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MeController.php routes/api.php tests/Feature/Http/Api/MeTest.php
git commit -m "feat(api): current-user endpoint"
```

---

## Task 5: Timer read endpoint (`GET /api/timer`)

**Files:**
- Create: `app/Http/Controllers/Api/TimerController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/TimerApiTest.php`

**Note on JSON shape:** We deliberately reuse `App\Support\TimerToday::payload()` as the response body — it is already a stable, tested, fully-serialized array (`entries`, `totals`, `by_project`, `quick_start`, `projects`). The API adds one convenience key, `running`, holding the currently-running entry (or `null`), so the client doesn't have to scan `entries`. `TimerToday` is NOT modified (the web app keeps its exact shape).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Api/TimerApiTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
});

test('GET /api/timer requires authentication', function () {
    $this->getJson('/api/timer')->assertUnauthorized();
});

test('GET /api/timer returns today payload with a running key', function () {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/timer')
        ->assertOk()
        ->assertJsonStructure([
            'entries',
            'totals' => ['total_seconds', 'billable_seconds', 'earnings_amount'],
            'by_project',
            'quick_start',
            'projects',
            'running',
        ])
        ->assertJson(['running' => null]);
});

test('GET /api/timer reports the running entry', function () {
    Sanctum::actingAs($this->user);
    TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'description' => 'in progress',
        'started_at' => now()->subMinutes(10),
        'ended_at' => null,
        'billable' => true,
    ]);

    $this->getJson('/api/timer')
        ->assertOk()
        ->assertJson([
            'running' => [
                'description' => 'in progress',
                'project' => ['id' => $this->project->id],
            ],
        ]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/TimerApiTest.php
```
Expected: FAIL — `/api/timer` not defined.

- [ ] **Step 3: Create the controller with the read action**

Create `app/Http/Controllers/Api/TimerController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Services\Timer\TimerService;
use App\Support\TimerToday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimerController extends Controller
{
    public function __construct(private TimerService $timer) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $user = $request->user();
        $data = TimerToday::payload($user);

        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with('project:id,name,code')
            ->first();

        $data['running'] = $running ? [
            'id' => $running->id,
            'description' => $running->description,
            'started_at' => $running->started_at->toIso8601String(),
            'duration_seconds' => $running->duration_seconds,
            'billable' => (bool) $running->billable,
            'project' => [
                'id' => $running->project->id,
                'name' => $running->project->name,
                'code' => $running->project->code,
            ],
        ] : null;

        return $data;
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group, add:

```php
    Route::get('/timer', [\App\Http\Controllers\Api\TimerController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/TimerApiTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/TimerController.php routes/api.php tests/Feature/Http/Api/TimerApiTest.php
git commit -m "feat(api): timer read endpoint"
```

---

## Task 6: Timer mutation endpoints (start / switch / stop / discard)

**Files:**
- Modify: `app/Http/Controllers/Api/TimerController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/TimerApiTest.php` (append)

**Note:** `start` and `switch` reuse the existing `App\Http\Requests\StartTimerRequest` (it validates `project_id` exists, `task_id` belongs to the project, `description` ≤ 500). All four actions return the same payload as `GET /api/timer` so the client refreshes its whole view from one response.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Api/TimerApiTest.php`:

```php
test('POST /api/timer/start creates a running entry and returns it', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/timer/start', [
        'project_id' => $this->project->id,
        'description' => 'kick off',
    ])
        ->assertOk()
        ->assertJson(['running' => ['description' => 'kick off']]);

    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('POST /api/timer/start auto-stops the previous entry', function () {
    Sanctum::actingAs($this->user);
    $other = Project::factory()->create();

    $this->postJson('/api/timer/start', ['project_id' => $other->id]);
    $first = TimeEntry::running()->first();

    $this->postJson('/api/timer/start', ['project_id' => $this->project->id])->assertOk();

    expect(TimeEntry::running()->count())->toBe(1);
    expect($first->fresh()->ended_at)->not->toBeNull();
});

test('POST /api/timer/start validates task ownership', function () {
    Sanctum::actingAs($this->user);
    $otherProject = Project::factory()->create();
    $task = \App\Models\Task::create(['project_id' => $otherProject->id, 'name' => 'x', 'sort_order' => 0]);

    $this->postJson('/api/timer/start', [
        'project_id' => $this->project->id,
        'task_id' => $task->id,
    ])->assertStatus(422)->assertJsonValidationErrors('task_id');
});

test('POST /api/timer/switch behaves like start', function () {
    Sanctum::actingAs($this->user);
    $other = Project::factory()->create();
    $this->postJson('/api/timer/start', ['project_id' => $other->id]);

    $this->postJson('/api/timer/switch', [
        'project_id' => $this->project->id,
        'description' => 'new context',
    ])->assertOk()->assertJson(['running' => ['description' => 'new context']]);

    expect(TimeEntry::running()->first()->project_id)->toBe($this->project->id);
});

test('POST /api/timer/stop ends the running entry', function () {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/timer/start', ['project_id' => $this->project->id]);

    $this->postJson('/api/timer/stop')
        ->assertOk()
        ->assertJson(['running' => null]);

    expect(TimeEntry::running()->count())->toBe(0);
});

test('POST /api/timer/discard deletes the running entry', function () {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/timer/start', ['project_id' => $this->project->id]);
    $id = TimeEntry::running()->first()->id;

    $this->postJson('/api/timer/discard')
        ->assertOk()
        ->assertJson(['running' => null]);

    expect(TimeEntry::find($id))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/TimerApiTest.php
```
Expected: FAIL — start/switch/stop/discard routes not defined.

- [ ] **Step 3: Add the mutation actions to the controller**

In `app/Http/Controllers/Api/TimerController.php`, add these imports at the top:

```php
use App\Http\Requests\StartTimerRequest;
use App\Models\Project;
use App\Models\Task;
```

And add these methods to the class (after `show`):

```php
    public function start(StartTimerRequest $request): JsonResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->start($request->user(), $project, $task, (string) $request->input('description', ''));

        return response()->json($this->payload($request));
    }

    public function switch(StartTimerRequest $request): JsonResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->switch($request->user(), $project, $task, (string) $request->input('description', ''));

        return response()->json($this->payload($request));
    }

    public function stop(Request $request): JsonResponse
    {
        $this->timer->stop($request->user());

        return response()->json($this->payload($request));
    }

    public function discard(Request $request): JsonResponse
    {
        $this->timer->discard($request->user());

        return response()->json($this->payload($request));
    }
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the `auth:sanctum` group, add:

```php
    Route::post('/timer/start', [\App\Http\Controllers\Api\TimerController::class, 'start']);
    Route::post('/timer/switch', [\App\Http\Controllers\Api\TimerController::class, 'switch']);
    Route::post('/timer/stop', [\App\Http\Controllers\Api\TimerController::class, 'stop']);
    Route::post('/timer/discard', [\App\Http\Controllers\Api\TimerController::class, 'discard']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
ddev artisan test tests/Feature/Http/Api/TimerApiTest.php
```
Expected: PASS (all 9 tests in the file).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/TimerController.php routes/api.php tests/Feature/Http/Api/TimerApiTest.php
git commit -m "feat(api): timer start/switch/stop/discard endpoints"
```

---

## Task 7: Full suite regression + route review

**Files:** none (verification only)

- [ ] **Step 1: Run the whole test suite**

Run:
```bash
ddev artisan test
```
Expected: PASS — all new API tests plus every pre-existing test (web routes, timer service, Inertia props) still green.

- [ ] **Step 2: Review the final API surface**

Run:
```bash
ddev artisan route:list --path=api
```
Expected routes:
- `POST   api/auth/token`
- `DELETE api/auth/token`  (auth:sanctum)
- `GET    api/me`          (auth:sanctum)
- `GET    api/timer`       (auth:sanctum)
- `POST   api/timer/start` (auth:sanctum)
- `POST   api/timer/switch`(auth:sanctum)
- `POST   api/timer/stop`  (auth:sanctum)
- `POST   api/timer/discard`(auth:sanctum)

- [ ] **Step 3: Commit (if route:list prompted any tidy-ups)**

No commit expected unless you adjusted routes. If you did:
```bash
git add routes/api.php
git commit -m "chore(api): tidy route registration"
```

---

## Self-Review Notes

- **Spec coverage:** This plan covers the Slice 1 *backend* items from the design — API routing, Sanctum tokens, `HasApiTokens`, token login/revoke, `/api/me`, and all five timer operations. The iOS app (login, Keychain, `APIClient`, Timer tab) is Slice 1b, a separate plan.
- **Deviation from spec — Resources:** The design proposed `App\Http\Resources\*` classes. For the timer, the response is `TimerToday`'s already-serialized array (not an Eloquent model), so a `JsonResource` wrapper would add indirection without value. We reuse the tested array and add a `running` key. Formal `JsonResource` classes are still planned for Slice 2 (projects/invoices/estimates), which serialize Eloquent models.
- **Auth model:** single-user app; tokens are long-lived (no expiry) and revoked explicitly via `DELETE /api/auth/token`.
