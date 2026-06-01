# iOS Companion — Slice 2a‑i: Projects Read API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two read-only JSON endpoints — `GET /api/projects` (list + stats + counts) and `GET /api/projects/{code}` (detail) — so the iOS app can show project status. Part of Slice 2 (read-only status), split into 2a‑i (Projects) and 2a‑ii (Billing).

**Architecture:** Thin API controller methods behind `auth:sanctum` that reuse the existing, already-tested projection helpers (`App\Support\DashboardProjections` and `App\Support\ProjectDetail`) and return their arrays as JSON. The Inertia web app is untouched.

**Tech Stack:** Laravel 12, Sanctum 4, Pest 4. All commands via DDEV (`ddev artisan …`).

**Conventions (from this repo):** Pest + `RefreshDatabase`; `Project::factory()` auto-creates a `Client`; authenticate API requests in tests with `Laravel\Sanctum\Sanctum::actingAs($user)`. New API routes go inside the existing `auth:sanctum` group in `routes/api.php`, controllers under `App\Http\Controllers\Api`, imported at the top of `routes/api.php` for consistent style.

**JSON shape rationale:** Like the timer endpoints (Slice 1a), these reuse the projection arrays (`DashboardProjections::projects()`, `::stats()`, `ProjectDetail::payload()`) rather than introducing new `JsonResource` classes. Those projections are the established, tested serialization layer for these models; wrapping them in Resources would duplicate logic. The shapes are already stable and exercised by existing web tests.

---

## Task 1: `GET /api/projects` — list, stats, counts

**Files:**
- Create: `app/Http/Controllers/Api/ProjectController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/ProjectApiTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Api/ProjectApiTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/projects requires authentication', function () {
    $this->getJson('/api/projects')->assertUnauthorized();
});

test('GET /api/projects returns projects, stats and counts', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->archived()->create(['name' => 'Old One']);

    $this->getJson('/api/projects')
        ->assertOk()
        ->assertJsonStructure([
            'projects' => [['id', 'code', 'name', 'status', 'spent_hours', 'pct_hours', 'band', 'sparkline', 'client' => ['id', 'name']]],
            'stats' => ['active', 'week_hours', 'unbilled_amount', 'outstanding_amount'],
            'counts' => ['active', 'all', 'archived'],
        ])
        // default filter is 'active', so the archived project is excluded
        ->assertJsonPath('counts.all', 2)
        ->assertJsonPath('counts.active', 1)
        ->assertJsonPath('counts.archived', 1)
        ->assertJsonCount(1, 'projects');
});

test('GET /api/projects?filter=archived narrows to archived', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->archived()->create(['name' => 'Old One']);

    $this->getJson('/api/projects?filter=archived')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Old One');
});

test('GET /api/projects?q= filters by name', function () {
    Sanctum::actingAs(User::factory()->create());
    Project::factory()->create(['name' => 'Findable']);
    Project::factory()->create(['name' => 'Hidden']);

    $this->getJson('/api/projects?q=Find')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Findable');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev artisan test tests/Feature/Http/Api/ProjectApiTest.php`
Expected: FAIL — `/api/projects` not defined.

- [ ] **Step 3: Create the controller with the index action**

Create `app/Http/Controllers/Api/ProjectController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\DashboardProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'active')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'projects' => DashboardProjections::projects($filter, $search)->values(),
            'stats'    => DashboardProjections::stats($request->user()),
            'counts'   => [
                'active'   => Project::active()->count(),
                'all'      => Project::count(),
                'archived' => Project::archived()->count(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add the import `use App\Http\Controllers\Api\ProjectController;` at the top, and inside the `auth:sanctum` group add:

```php
    Route::get('/projects', [ProjectController::class, 'index']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `ddev artisan test tests/Feature/Http/Api/ProjectApiTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ProjectController.php routes/api.php tests/Feature/Http/Api/ProjectApiTest.php
git commit -m "feat(api): projects list endpoint"
```

---

## Task 2: `GET /api/projects/{code}` — detail

**Files:**
- Modify: `app/Http/Controllers/Api/ProjectController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/ProjectApiTest.php` (append)

**Note:** Route-model binding by `code` (mirrors the web route `{project:code}`). The body reuses `App\Support\ProjectDetail::payload($project)`, which returns `project`, `tasks`, `recent_entries`, `heatmap`, `counts`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Api/ProjectApiTest.php`:

```php
test('GET /api/projects/{code} requires authentication', function () {
    $project = Project::factory()->create(['code' => 'ACME-001']);
    $this->getJson("/api/projects/{$project->code}")->assertUnauthorized();
});

test('GET /api/projects/{code} returns the project detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $project = Project::factory()->create(['name' => 'Acme Site', 'code' => 'ACME-001']);

    $this->getJson("/api/projects/{$project->code}")
        ->assertOk()
        ->assertJsonStructure([
            'project' => ['id', 'name', 'code', 'status', 'spent_hours', 'pct_hours', 'band', 'client' => ['id', 'name']],
            'tasks',
            'recent_entries',
            'heatmap',
            'counts' => ['entries', 'tasks'],
        ])
        ->assertJsonPath('project.code', 'ACME-001')
        ->assertJsonPath('project.name', 'Acme Site');
});

test('GET /api/projects/{code} returns 404 for an unknown code', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/projects/NOPE-999')->assertNotFound();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev artisan test tests/Feature/Http/Api/ProjectApiTest.php`
Expected: FAIL — `/api/projects/{code}` not defined (the new tests).

- [ ] **Step 3: Add the show action**

In `app/Http/Controllers/Api/ProjectController.php`, add the import `use App\Support\ProjectDetail;` and the method:

```php
    public function show(Project $project): JsonResponse
    {
        return response()->json(ProjectDetail::payload($project));
    }
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group, add (after the index route):

```php
    Route::get('/projects/{project:code}', [ProjectController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `ddev artisan test tests/Feature/Http/Api/ProjectApiTest.php`
Expected: PASS (7 tests in the file).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ProjectController.php routes/api.php tests/Feature/Http/Api/ProjectApiTest.php
git commit -m "feat(api): project detail endpoint"
```

---

## Task 3: Regression + route review

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `ddev artisan test`
Expected: PASS — new project API tests plus all pre-existing tests.

- [ ] **Step 2: Route review**

Run: `ddev artisan route:list --path=api/projects`
Expected:
- `GET api/projects` (auth:sanctum)
- `GET api/projects/{project}` (auth:sanctum)

---

## Self-Review Notes

- **Spec coverage:** Implements the Projects half of Slice 2's backend — list (with the same status/budget/sparkline data the web dashboard shows) and detail (status, tasks, recent entries, heatmap, counts). Billing read endpoints (invoices/estimates) are Slice 2a‑ii. The iOS Projects tab is Slice 2b‑i.
- **Deviation from spec — Resources:** Reuses existing projection arrays instead of new `JsonResource` classes (same rationale as the Slice 1a timer endpoint). Documented above.
- **Type consistency:** Controller methods `index`/`show` both return `JsonResponse`; the `show` route binds by `code` exactly like the web route. Imports added to `routes/api.php` match the existing alias style.
