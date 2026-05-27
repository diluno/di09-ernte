# Ernte — Phase 2a (Projects + Timer + Clients flows) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the full time-tracking half of the app — Projects, Tasks, Timer, Manual entries, Clients — with real HTTP controllers, Inertia pages, the chrome wired to live data (running-timer chip, sidebar pinned/recent/week-bars, statusbar backup + db size), and the five custom SVG chart components. At the end of Phase 2a the app is usable end-to-end for tracking time; only invoicing and the polish layer remain for Phase 2b.

**Architecture:** Server-authoritative timer (already built in Phase 1's `TimerService`) is exposed via four `POST /timer/*` endpoints. Inertia shared props carry `running_entry` + sidebar data on every page so the topbar chip and sidebar widgets work without polling. Each page is a thin Vue SFC consuming pre-aggregated props from its controller — no client-side aggregation. SVG chart components are 30-80 LOC pure-render Vue files, no chart library. CSS is the verbatim port from `design/ernte/project/styles.css` already in `resources/css/app.css` from Phase 0 — Phase 2a adds **no new CSS**, only Vue templates that consume the existing classes.

**Tech Stack:** Laravel 12, Inertia.js 2.x + Vue 3 Composition API, Ziggy for route names, Pest. No new composer or npm packages this phase.

**Source spec:** `docs/superpowers/specs/2026-05-27-ernte-design.md` (§5 routes, §7 UX system)
**Carryover from Phase 1:** `docs/superpowers/phase-2-carryover.md`
**Predecessor plan:** `docs/superpowers/plans/2026-05-27-ernte-phase-1-schema.md` (tag `phase-1`)
**Sibling plan to be written next:** Phase 2b — Invoices CRUD + PDF + QR-bill + SMTP + reminder scheduler + Settings/Profile + Reports placeholder + ⌘K palette + keyboard shortcuts + backup command.

---

## File map for Phase 2a

Created:

| Path | Responsibility |
|---|---|
| `app/Http/Controllers/ProjectController.php` | `index/show/store/update/archive` for `/projects` |
| `app/Http/Controllers/TaskController.php` | `store/update/destroy` for `/tasks` (rename, toggle done, budget, reorder) |
| `app/Http/Controllers/TimerController.php` | `show/start/stop/switch/discard` for `/timer/*` |
| `app/Http/Controllers/EntryController.php` | `store/update/destroy` for `/entries` (manual entries) |
| `app/Http/Controllers/ClientController.php` | Resource controller for `/clients` |
| `app/Http/Requests/StoreProjectRequest.php` | Validation for new project |
| `app/Http/Requests/UpdateProjectRequest.php` | Validation for project edit |
| `app/Http/Requests/StoreTaskRequest.php` | Validation for new task |
| `app/Http/Requests/UpdateTaskRequest.php` | Validation for task edit |
| `app/Http/Requests/StartTimerRequest.php` | Validation for `POST /timer/start` and `/timer/switch` |
| `app/Http/Requests/StoreEntryRequest.php` | Validation for manual entry |
| `app/Http/Requests/UpdateEntryRequest.php` | Validation for entry edit |
| `app/Http/Requests/StoreClientRequest.php` | Validation for new client |
| `app/Http/Requests/UpdateClientRequest.php` | Validation for client edit |
| `app/Support/SidebarProps.php` | Builds the topbar/sidebar shared-prop payload (running entry, pinned, week hours, nav counts) |
| `app/Support/DashboardProjections.php` | Pre-aggregates project rows + sparklines for `Projects/Index` |
| `resources/js/composables/useTimer.js` | Reactive elapsed-seconds composable driven by the shared `running_entry` prop |
| `resources/js/Components/RunningTimerChip.vue` | Topbar running-timer chip |
| `resources/js/Components/Sparkline.vue` | 14-day sparkline (≤60 LOC) |
| `resources/js/Components/BudgetBar.vue` | Segmented hours/fees budget bar |
| `resources/js/Components/WeekBars.vue` | Sidebar week-bars chart |
| `resources/js/Components/BurnDown.vue` | Project burn-down chart |
| `resources/js/Components/Heatmap.vue` | 12-week activity heatmap |
| `resources/js/Components/EntryRow.vue` | One time-entry row used in Timer/Today + Projects/Show |
| `resources/js/Components/TaskRow.vue` | One task row used in Projects/Show |
| `resources/js/Components/TimerHero.vue` | Big timer display + controls used on Timer/Today |
| `resources/js/Pages/Projects/Index.vue` | Projects table page (replaces Phase 0 placeholder) |
| `resources/js/Pages/Projects/Show.vue` | Project detail page with tabs |
| `resources/js/Pages/Timer/Today.vue` | Today timer + entries page (replaces placeholder) |
| `resources/js/Pages/Clients/Index.vue` | Clients table (replaces placeholder) |
| `resources/js/Pages/Clients/Create.vue` | New-client form |
| `resources/js/Pages/Clients/Edit.vue` | Edit-client form |
| `tests/Feature/Http/ProjectControllerTest.php` | Index lists + show props + store/update/archive |
| `tests/Feature/Http/TaskControllerTest.php` | Store/update/destroy mutations + reorder |
| `tests/Feature/Http/TimerControllerTest.php` | start/stop/switch/discard + show page props |
| `tests/Feature/Http/EntryControllerTest.php` | Manual entry store/update/destroy + validation |
| `tests/Feature/Http/ClientControllerTest.php` | Resource CRUD + archive |
| `tests/Feature/SharedPropsTest.php` | `running_entry`, `sidebar.*` shapes on every authenticated page |
| `tests/Feature/Schema/ClientProjectsRelationshipTest.php` | Carryover: `Client::projects()` |

Modified:

| Path | What changes |
|---|---|
| `app/Models/Client.php` | Add `projects()` hasMany |
| `app/Http/Middleware/HandleInertiaRequests.php` | Add `running_entry` + `sidebar` shared props via `SidebarProps` |
| `routes/web.php` | Replace placeholder closures with real controllers + add all new routes |
| `resources/js/Components/Topbar.vue` | Mount `RunningTimerChip` where the placeholder comment is |
| `resources/js/Components/Sidebar.vue` | Wire pinned/recent/week-bars to shared props |
| `resources/js/Components/Statusbar.vue` | Render backup + db-size + project version |
| `app/Http/Middleware/HandleInertiaRequests.php` | Add `system.db_size_bytes` and `system.backup_last_at` |

---

## Conventions

- **Branch:** create `phase-2a-views` from `main` before Task 1.
- **All shell commands run inside DDEV:** `ddev artisan`, `ddev composer`, `ddev npm`, `ddev exec`.
- **Money:** Server-side computations stay in integer rappen. The display layer formats. **No `_rappen` field is ever exposed to Vue as a float.**
- **Times:** DB stores UTC (`datetime`). Inertia serializes Carbon to ISO-8601 strings (`2026-05-27T16:30:00.000000Z`). The Vue layer parses with `new Date(iso)` and renders in the user's local time.
- **Form requests:** every mutation has a dedicated `app/Http/Requests/*Request.php`. Validation lives there, **not** in the controller. Controllers stay thin.
- **Inertia tests:** use `Inertia\Testing\AssertableInertia` (already wired in `tests/Feature/InertiaPropsTest.php`). Assert component name + prop shape, never rendered HTML.
- **Routing:** Ziggy is already installed (Phase 0). In Vue, use `route('projects.show', { project: code })` rather than hand-built paths.
- **No new CSS.** The styles port from `design/ernte/project/styles.css` is complete. If you find a class missing, grep `resources/css/app.css` first — almost certainly it's there.
- **Translate JSX → Vue** mechanically: `className` → `class`, `onClick` → `@click`, `style={{}}` → `:style="{...}"`, `{cond && <X/>}` → `<X v-if="cond"/>`, `.map((x) => <Y/>)` → `<Y v-for="x in list" :key="x.id"/>`, React's `useState` → Vue's `ref`. Visual details come from `design/ernte/project/views.jsx` — when a step says "port views.jsx lines 100-165 for the dashboard layout", treat that JSX as canonical for markup, classes, and inline styles.
- **Commits:** imperative + scoped, same pattern as Phases 0/1. One commit at the end of each task. If a task is large, multiple commits within it are fine (the plan calls them out).

---

## Task 0: Branch + baseline

- [ ] **Step 1: Branch off main**

```
host$ git checkout main
host$ git pull
host$ git checkout -b phase-2a-views main
host$ git status
```
Expected: "On branch phase-2a-views, nothing to commit, working tree clean".

- [ ] **Step 2: Confirm baseline tests pass**

```
host$ ddev artisan test
```
Expected: all Phase 1 tests pass (~50+ tests, the exact number is whatever `main` was at).

- [ ] **Step 3: Confirm Vite builds**

```
host$ ddev npm run build
```
Expected: build succeeds, no warnings about missing imports.

This task has no commit — setup only.

---

## Task 1: Carryover — `Client::projects()` relationship

Carries one bullet from `docs/superpowers/phase-2-carryover.md` (the one needed by Phase 2a). The `invoices()` relationship is deferred to Phase 2b.

**Files:**
- Modify: `app/Models/Client.php`
- Test: `tests/Feature/Schema/ClientProjectsRelationshipTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Schema/ClientProjectsRelationshipTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Project;

test('Client has many projects', function () {
    $client = Client::factory()->create();
    Project::factory()->count(3)->create(['client_id' => $client->id]);
    Project::factory()->create(); // belongs to a different client

    expect($client->projects)->toHaveCount(3);
    expect($client->projects->first())->toBeInstanceOf(Project::class);
});

test('Client::projects returns a HasMany relation', function () {
    $client = Client::factory()->create();
    expect($client->projects())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```
host$ ddev artisan test --filter=ClientProjectsRelationship
```
Expected: FAIL with "Call to undefined method App\Models\Client::projects()".

- [ ] **Step 3: Add the relationship**

In `app/Models/Client.php`, add inside the class (next to existing scopes):
```php
public function projects()
{
    return $this->hasMany(Project::class);
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
host$ ddev artisan test --filter=ClientProjectsRelationship
```
Expected: PASS (2 tests).

- [ ] **Step 5: Full suite still green**

```
host$ ddev artisan test
```
Expected: no regressions.

- [ ] **Step 6: Commit**

```
host$ git add app/Models/Client.php tests/Feature/Schema/ClientProjectsRelationshipTest.php
host$ git commit -m "feat(model): Client::projects relationship"
```

---

## Task 2: Shared-props infrastructure for chrome (topbar + sidebar)

The topbar's running-timer chip and the sidebar's pinned/recent/week-bars need data on every authenticated page. We centralize this in `SidebarProps` (a query helper) and expose it through `HandleInertiaRequests::share()`. Inertia closures are lazy — only evaluated on full page loads, not partial reloads that don't request them.

**Files:**
- Create: `app/Support/SidebarProps.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/SharedPropsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/SharedPropsTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared props expose running_entry as null when nothing is running', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('running_entry', null));
});

test('shared props expose the running entry with project + task labels', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT']);

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'description' => 'Telemetry side-panel',
        'started_at' => now()->subMinutes(15),
        'ended_at' => null,
        'billable' => true,
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('running_entry', fn (Assert $e) => $e
                ->where('project.name', 'Fleet Console v2')
                ->where('project.code', 'ATLS-FLT')
                ->where('description', 'Telemetry side-panel')
                ->has('started_at')
                ->has('id')
                ->etc()
            )
        );
});

test('sidebar shared prop contains nav_counts, pinned, week_hours', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->count(3)->create(['client_id' => $client->id, 'status' => 'active']);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sidebar', fn (Assert $s) => $s
                ->has('nav_counts.projects')
                ->has('nav_counts.clients')
                ->has('pinned')                  // array of {code, name, glyph}
                ->has('week_hours')              // 7-element array Mon..Sun
                ->has('today_hours')             // number, seconds today
                ->etc()
            )
        );
});
```

- [ ] **Step 2: Run the test to verify it fails**

```
host$ ddev artisan test --filter=SharedProps
```
Expected: FAIL — `running_entry` key absent.

- [ ] **Step 3: Create `SidebarProps`**

`app/Support/SidebarProps.php`:
```php
<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

class SidebarProps
{
    public static function runningEntry(User $user): ?array
    {
        $entry = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with(['project:id,name,code,glyph,rate_rappen', 'task:id,name'])
            ->first();

        if (! $entry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'description' => $entry->description,
            'started_at' => $entry->started_at->toIso8601String(),
            'billable' => (bool) $entry->billable,
            'project' => [
                'id' => $entry->project->id,
                'name' => $entry->project->name,
                'code' => $entry->project->code,
                'glyph' => $entry->project->glyph,
                'rate_rappen' => (int) $entry->project->rate_rappen,
            ],
            'task' => $entry->task ? [
                'id' => $entry->task->id,
                'name' => $entry->task->name,
            ] : null,
        ];
    }

    public static function sidebar(User $user): array
    {
        return [
            'nav_counts' => [
                'projects' => Project::active()->count(),
                'clients'  => Client::active()->count(),
            ],
            'pinned' => self::pinnedProjects(),
            'week_hours' => self::weekHours($user),
            'today_hours' => self::todaySeconds($user) / 3600,
        ];
    }

    /** Top 4 active projects ordered by last activity (most recent entry's started_at). */
    private static function pinnedProjects(): array
    {
        return Project::active()
            ->select('projects.id', 'projects.name', 'projects.code', 'projects.glyph')
            ->leftJoin('time_entries', 'time_entries.project_id', '=', 'projects.id')
            ->groupBy('projects.id', 'projects.name', 'projects.code', 'projects.glyph')
            ->orderByRaw('COALESCE(MAX(time_entries.started_at), projects.created_at) DESC')
            ->limit(4)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'glyph' => $p->glyph,
            ])
            ->all();
    }

    /** Returns [mon, tue, wed, thu, fri, sat, sun] in hours, for the current ISO-week. */
    private static function weekHours(User $user): array
    {
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $rows = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$monday, $monday->copy()->addDays(7)])
            ->selectRaw('
                WEEKDAY(started_at) AS dow,
                SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))) AS secs
            ')
            ->groupBy('dow')
            ->pluck('secs', 'dow');

        // WEEKDAY: Monday=0..Sunday=6
        $week = array_fill(0, 7, 0.0);
        foreach ($rows as $dow => $secs) {
            $week[(int) $dow] = round(((int) $secs) / 3600, 1);
        }
        return $week;
    }

    private static function todaySeconds(User $user): int
    {
        return (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('started_at', Carbon::today())
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s')
            ->value('s');
    }
}
```

- [ ] **Step 4: Wire shared props in `HandleInertiaRequests`**

In `app/Http/Middleware/HandleInertiaRequests.php`, inside `share()`, add two new closures after the existing `'system'` key:

```php
'running_entry' => fn () => $request->user()
    ? \App\Support\SidebarProps::runningEntry($request->user())
    : null,
'sidebar' => fn () => $request->user()
    ? \App\Support\SidebarProps::sidebar($request->user())
    : null,
```

- [ ] **Step 5: Run the shared-props tests**

```
host$ ddev artisan test --filter=SharedProps
```
Expected: PASS (3 tests).

- [ ] **Step 6: Full suite green**

```
host$ ddev artisan test
```
Expected: no regressions.

- [ ] **Step 7: Commit**

```
host$ git add app/Support/SidebarProps.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/SharedPropsTest.php
host$ git commit -m "feat(chrome): shared running_entry + sidebar props"
```

---

## Task 3: SVG chart components

Five small Vue components, no chart library. Each receives already-aggregated data via props. Total ≈250 LOC. No tests — pure render with no business logic; the integration tests for the consuming pages prove they render.

**Files:**
- Create: `resources/js/Components/Sparkline.vue`
- Create: `resources/js/Components/BudgetBar.vue`
- Create: `resources/js/Components/WeekBars.vue`
- Create: `resources/js/Components/BurnDown.vue`
- Create: `resources/js/Components/Heatmap.vue`

- [ ] **Step 1: Sparkline.vue**

`resources/js/Components/Sparkline.vue` — port `design/ernte/project/app.jsx` lines 28-41 (`function Spark`):
```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  data:  { type: Array,  required: true },     // numbers
  w:     { type: Number, default: 90 },
  h:     { type: Number, default: 22 },
  color: { type: String, default: 'var(--ink-3)' },
});

const path = computed(() => {
  const max = Math.max(...props.data, 1);
  const stepX = props.w / Math.max(props.data.length - 1, 1);
  return props.data.map((v, i) => {
    const x = (i * stepX).toFixed(1);
    const y = (props.h - (v / max) * (props.h - 2) - 1).toFixed(1);
    return `${i === 0 ? 'M' : 'L'}${x},${y}`;
  }).join(' ');
});

const area = computed(() => {
  const max = Math.max(...props.data, 1);
  const stepX = props.w / Math.max(props.data.length - 1, 1);
  const pts = props.data.map((v, i) => {
    const x = (i * stepX).toFixed(1);
    const y = (props.h - (v / max) * (props.h - 2) - 1).toFixed(1);
    return `L${x},${y}`;
  }).join(' ');
  return `M0,${props.h} ${pts} L${props.w},${props.h} Z`;
});

const areaStyle = computed(() => ({ fill: `color-mix(in oklch, ${props.color} 14%, transparent)` }));
const lineStyle = computed(() => ({ stroke: props.color }));
</script>

<template>
  <svg class="spark" :viewBox="`0 0 ${w} ${h}`" :width="w" :height="h" preserveAspectRatio="none">
    <path class="area" :d="area" :style="areaStyle" />
    <path :d="path" :style="lineStyle" fill="none" />
  </svg>
</template>
```

- [ ] **Step 2: BudgetBar.vue**

`resources/js/Components/BudgetBar.vue` — port `design/ernte/project/app.jsx` lines 43-66 (`function BudgetCell`):
```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  spent:  { type: Number, required: true },
  budget: { type: Number, required: true },
  unit:   { type: String, default: 'h' }, // 'h' or '€'
});

const pct = computed(() => props.budget ? Math.round((props.spent / props.budget) * 100) : 0);
const band = computed(() => pct.value > 100 ? 'over' : pct.value >= 85 ? 'warn' : 'ok');
const width = computed(() => Math.min(100, pct.value));

function fmt(v) {
  return props.unit === 'h'
    ? `${v.toFixed(1)}h`
    : `€${Math.round(v).toLocaleString('en-US')}`;
}
</script>

<template>
  <div class="budget-cell">
    <div class="label">
      <span>
        {{ fmt(spent) }}
        <span class="ascii-dot">/</span>
        <span class="muted">{{ fmt(budget) }}</span>
      </span>
      <span class="pct" :class="band">{{ pct }}%</span>
    </div>
    <div class="budget-bar">
      <div class="budget-fill" :class="band" :style="{ width: `${width}%` }" />
      <div
        v-if="band === 'over'"
        class="budget-fill over"
        :style="{ width: `${Math.min(100, pct - 100)}%`, left: 0, opacity: 0.4 }"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 3: WeekBars.vue**

`resources/js/Components/WeekBars.vue` — port `design/ernte/project/app.jsx` lines 226-249 (sidebar week-bars block):
```vue
<script setup>
defineProps({
  hours:  { type: Array,  required: true },  // 7 numbers, Mon..Sun
  target: { type: Number, default: 40 },
});

const DAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const todayIdx = (new Date().getDay() + 6) % 7; // JS: Sun=0..Sat=6 → ISO Mon=0..Sun=6
</script>

<template>
  <div>
    <div style="display: flex; gap: 3px; align-items: flex-end; height: 28px">
      <div
        v-for="(h, i) in hours" :key="i"
        :title="`${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]}: ${h}h`"
        :style="{
          flex: 1,
          height: `${Math.max(2, (h / 10) * 100)}%`,
          background: i === todayIdx ? 'var(--accent)' : h === 0 ? 'var(--bg-3)' : 'var(--ink-3)',
          opacity: i === 5 || i === 6 ? 0.5 : 1,
        }"
      />
    </div>
    <div style="display: flex; justify-content: space-between; font-size: 9px; color: var(--ink-4); margin-top: 4px; letter-spacing: .05em">
      <span v-for="(d, i) in DAYS" :key="i">{{ d }}</span>
    </div>
  </div>
</template>
```

- [ ] **Step 4: BurnDown.vue**

`resources/js/Components/BurnDown.vue` — port `design/ernte/project/views.jsx` lines 314-359 (`function BurnDown`). Vue version receives `spent` + `budget` numbers as props (the JSX synthesizes them from `project` — we pass them explicitly):
```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  spent:  { type: Number, required: true },
  budget: { type: Number, required: true },
  days:   { type: Number, default: 60 },
});

const W = 720, H = 140, PAD = 20;

function xs(i) { return PAD + (i / (props.days - 1)) * (W - PAD * 2); }
function ys(v) { return PAD + (1 - v / Math.max(props.budget, 1)) * (H - PAD * 2); }

const idealPath = computed(() => {
  const pts = Array.from({ length: props.days }).map((_, i) => props.budget - (props.budget / props.days) * i);
  return pts.map((v, i) => `${i === 0 ? 'M' : 'L'}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(' ');
});

const actualSeries = computed(() => {
  const burnFactor = props.budget ? props.spent / props.budget : 0;
  return Array.from({ length: props.days }).map((_, i) => {
    const progress = i / props.days;
    return Math.max(0, props.budget - props.budget * progress * burnFactor * (1 + Math.sin(i * 0.4) * 0.05));
  });
});

const actualPath = computed(() =>
  actualSeries.value.map((v, i) => `${i === 0 ? 'M' : 'L'}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(' ')
);

const todayIdx = computed(() => Math.min(
  props.days - 1,
  Math.floor(actualSeries.value.length * (props.budget ? props.spent / props.budget : 0) * 1.05)
));

const W_VB = W, H_VB = H, PAD_VB = PAD;
</script>

<template>
  <div class="burndown" style="position: relative">
    <svg :viewBox="`0 0 ${W_VB} ${H_VB}`" width="100%" :height="H_VB" preserveAspectRatio="none">
      <line v-for="p in [0.25, 0.5, 0.75]" :key="p"
        :x1="PAD_VB" :x2="W_VB - PAD_VB"
        :y1="PAD_VB + p * (H_VB - PAD_VB * 2)" :y2="PAD_VB + p * (H_VB - PAD_VB * 2)"
        stroke="var(--border)" stroke-dasharray="2 4" />
      <line :x1="PAD_VB" :x2="W_VB - PAD_VB" :y1="H_VB - PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--border)" />
      <line :x1="PAD_VB" :x2="PAD_VB" :y1="PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--border)" />
      <path :d="idealPath" fill="none" stroke="var(--ink-4)" stroke-width="1" stroke-dasharray="4 4" />
      <path :d="actualPath" fill="none" stroke="var(--forest)" stroke-width="1.5" />
      <template v-if="todayIdx < days">
        <line :x1="xs(todayIdx)" :x2="xs(todayIdx)" :y1="PAD_VB" :y2="H_VB - PAD_VB" stroke="var(--rust)" stroke-dasharray="2 2" />
        <circle :cx="xs(todayIdx)" :cy="ys(actualSeries[todayIdx])" r="3" fill="var(--rust)" />
      </template>
    </svg>
    <div style="position: absolute; top: 6px; left: 8px; font-size: var(--fs-xs); color: var(--ink-4); display: flex; gap: 14px">
      <span><span style="display: inline-block; width: 10px; height: 1.5px; background: var(--forest); vertical-align: middle; margin-right: 4px" />actual</span>
      <span><span style="display: inline-block; width: 10px; border-top: 1px dashed var(--ink-4); vertical-align: middle; margin-right: 4px" />ideal</span>
      <span><span style="display: inline-block; width: 6px; height: 6px; background: var(--rust); border-radius: 50%; vertical-align: middle; margin-right: 4px" />today</span>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Heatmap.vue**

`resources/js/Components/Heatmap.vue` — receives 60 numbers (hours per day over 12 weeks). The design has 60 cells; we accept either an explicit array or fall back to all-zero so the component never throws:
```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  cells: { type: Array, default: () => Array(60).fill(0) }, // 60 numbers, oldest → newest
});

function level(v) {
  if (v >= 4) return 'l4';
  if (v >= 2.5) return 'l3';
  if (v >= 1) return 'l2';
  if (v > 0) return 'l1';
  return '';
}

const filled = computed(() => {
  const out = Array(60).fill(0);
  for (let i = 0; i < Math.min(props.cells.length, 60); i++) out[i] = props.cells[i];
  return out;
});
</script>

<template>
  <div>
    <div class="heat">
      <div
        v-for="(v, i) in filled" :key="i"
        class="sq"
        :class="level(v)"
        :title="`${v.toFixed(1)}h`"
      />
    </div>
    <div style="font-size: var(--fs-xs); color: var(--ink-4); margin-top: 8px; display: flex; justify-content: space-between">
      <span>12 weeks ago</span><span>today</span>
    </div>
  </div>
</template>
```

- [ ] **Step 6: Verify build**

```
host$ ddev npm run build
```
Expected: all 5 components compile, no warnings.

- [ ] **Step 7: Commit**

```
host$ git add resources/js/Components/Sparkline.vue resources/js/Components/BudgetBar.vue resources/js/Components/WeekBars.vue resources/js/Components/BurnDown.vue resources/js/Components/Heatmap.vue
host$ git commit -m "feat(charts): Sparkline/BudgetBar/WeekBars/BurnDown/Heatmap SVG components"
```

---

## Task 4: useTimer composable + RunningTimerChip + chrome wiring

Wires the topbar chip to live data, ticks elapsed seconds, refactors the sidebar to consume shared props, and adds the topbar/sidebar `Recent` localStorage tracker.

**Files:**
- Create: `resources/js/composables/useTimer.js`
- Create: `resources/js/Components/RunningTimerChip.vue`
- Modify: `resources/js/Components/Topbar.vue`
- Modify: `resources/js/Components/Sidebar.vue`

- [ ] **Step 1: `useTimer.js` composable**

`resources/js/composables/useTimer.js`:
```javascript
import { computed, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Reactive elapsed-seconds reader for the running timer.
 *
 * Reads `running_entry` from Inertia shared props on every page.
 * Display-only — server is authoritative for the actual entry duration.
 */
export function useTimer() {
  const page = usePage();
  const tick = ref(Date.now());

  const interval = setInterval(() => { tick.value = Date.now(); }, 1000);
  onUnmounted(() => clearInterval(interval));

  const running = computed(() => page.props.running_entry || null);

  const elapsedSeconds = computed(() => {
    if (!running.value) return 0;
    const started = new Date(running.value.started_at).getTime();
    return Math.max(0, Math.floor((tick.value - started) / 1000));
  });

  function stop()    { router.post('/timer/stop',    {}, { preserveScroll: true }); }
  function discard() { router.post('/timer/discard', {}, { preserveScroll: true }); }

  return { running, elapsedSeconds, stop, discard };
}

/** "01:23:45" from a seconds count. */
export function fmtHMS(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}
```

- [ ] **Step 2: `RunningTimerChip.vue`**

`resources/js/Components/RunningTimerChip.vue` — port `design/ernte/project/app.jsx` lines 159-168 (the `<button className="running-timer">` block):
```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import { useTimer, fmtHMS } from '@/composables/useTimer.js';

const { running, elapsedSeconds, stop } = useTimer();

function onStop(e) {
  e.preventDefault();
  e.stopPropagation();
  stop();
}
</script>

<template>
  <Link v-if="running" href="/timer" class="running-timer" title="Open timer">
    <span class="pulse" />
    <span style="opacity: 0.8; font-size: var(--fs-xs)">{{ running.project.name }}</span>
    <span style="font-weight: 700">{{ fmtHMS(elapsedSeconds) }}</span>
    <button class="timer-stop" title="Stop" @click="onStop" />
  </Link>
  <div v-else class="running-timer idle" title="No timer running">
    <span class="pulse idle" />
    <span style="opacity: 0.6; font-size: var(--fs-xs)">idle</span>
  </div>
</template>
```

- [ ] **Step 3: Mount the chip in `Topbar.vue`**

Replace the comment `<!-- Running timer chip lands in Phase 2 -->` in `resources/js/Components/Topbar.vue` with `<RunningTimerChip />`, and add the import at the top of `<script setup>`:

```vue
<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import RunningTimerChip from '@/Components/RunningTimerChip.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const initials = computed(() => {
  const n = user.value?.name ?? '?';
  return n.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
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
    <button class="cmdk" title="Command palette (Phase 2b)" disabled>
      <span style="color: var(--ink-4)">›</span>
      <span style="flex: 1; text-align: left">Jump to project, client, invoice…</span>
      <span class="kbd">⌘K</span>
    </button>
    <div class="topbar-spacer" />
    <RunningTimerChip />
    <div class="user-chip">
      <span class="avatar">{{ initials }}</span>
      <span>{{ user?.name ?? 'guest' }}</span>
    </div>
  </header>
</template>
```

- [ ] **Step 4: Rewrite `Sidebar.vue` to consume shared props**

Replace `resources/js/Components/Sidebar.vue` in full — port `design/ernte/project/app.jsx` lines 178-253:
```vue
<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import WeekBars from '@/Components/WeekBars.vue';

const page = usePage();
const sidebar = computed(() => page.props.sidebar ?? { nav_counts: {}, pinned: [], week_hours: [0,0,0,0,0,0,0], today_hours: 0 });

const NAV = computed(() => [
  { id: 'projects', href: '/projects', label: 'Projects', glyph: '▤', count: sidebar.value.nav_counts.projects },
  { id: 'timer',    href: '/timer',    label: 'Timer',    glyph: '◐', count: sidebar.value.today_hours ? `${sidebar.value.today_hours.toFixed(1)}h` : null },
  { id: 'clients',  href: '/clients',  label: 'Clients',  glyph: '◇', count: sidebar.value.nav_counts.clients },
  { id: 'invoices', href: '/invoices', label: 'Invoices', glyph: '≡', count: null },
  { id: 'reports',  href: '/reports',  label: 'Reports',  glyph: '△', count: null },
]);

const current = computed(() => page.url);
const isActive = (href) => current.value.startsWith(href);

// Recent: last 5 visited entities, kept in localStorage. Tracked by visiting a project or client page (see Show pages).
const recent = ref([]);
onMounted(() => {
  try { recent.value = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]'); }
  catch { recent.value = []; }
});
// Re-read when Inertia navigates (because pages push entries on visit).
watch(current, () => {
  try { recent.value = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]'); }
  catch {}
});

const weekTotal = computed(() => sidebar.value.week_hours.reduce((a, h) => a + h, 0).toFixed(1));
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
        <span v-if="n.count !== null && n.count !== undefined" class="count">{{ n.count }}</span>
      </Link>
    </nav>

    <div class="side-section">Pinned</div>
    <div v-if="sidebar.pinned.length === 0" class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">No projects yet</div>
    <Link
      v-for="(p, i) in sidebar.pinned" :key="p.id"
      :href="`/projects/${p.code}`"
      class="pin-row"
    >
      <span class="pin-dot" :class="{ solid: i < 2 }" :style="{ color: ['var(--forest)', 'var(--rust)', 'var(--ink)', 'var(--gold)'][i] }" />
      <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ p.name }}</span>
    </Link>

    <div class="side-section">Recent</div>
    <div v-if="recent.length === 0" class="muted" style="padding: 4px 14px; font-size: var(--fs-xs)">—</div>
    <Link
      v-for="r in recent" :key="r.url"
      :href="r.url"
      class="pin-row muted"
      style="font-size: var(--fs-xs)"
    >{{ r.label }}</Link>

    <div style="flex: 1" />
    <div style="padding: 12px 14px; border-top: 1px solid var(--border); margin-top: 8px">
      <div style="font-size: var(--fs-xs); color: var(--ink-4); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 8px">This week</div>
      <div style="font-size: var(--fs-lg); font-weight: 700; color: var(--ink); letter-spacing: -0.02em">
        {{ weekTotal }}<span style="font-size: var(--fs-sm); color: var(--ink-3); font-weight: 400; margin-left: 2px">h</span>
        <span style="color: var(--ink-4); font-weight: 400; font-size: var(--fs-sm); margin-left: 6px">/ 40h</span>
      </div>
      <WeekBars :hours="sidebar.week_hours" />
    </div>
  </aside>
</template>
```

- [ ] **Step 5: Add a tiny `recent.js` helper for Show pages**

`resources/js/composables/useRecent.js`:
```javascript
export function pushRecent(entry) {
  try {
    const list = JSON.parse(localStorage.getItem('ernte.recent') ?? '[]');
    const dedup = [entry, ...list.filter((e) => e.url !== entry.url)].slice(0, 5);
    localStorage.setItem('ernte.recent', JSON.stringify(dedup));
  } catch {}
}
```
(Consumed by Projects/Show.vue and Clients/Edit.vue in later tasks.)

- [ ] **Step 6: Build + visit manually to verify the chip ticks**

```
host$ ddev npm run dev   # leave running; new terminal for the next command
host$ ddev artisan db:seed --class=DemoFixturesSeeder
```
Visit https://ernte.ddev.site/projects in the browser. The topbar should show "idle" (no timer running). The sidebar should show the 5 nav items with active counts for projects/clients, the pinned section listing demo projects, and the week-bars rendering. There should be no console errors.

- [ ] **Step 7: Sanity test — running entry shows up in the chip**

```
host$ ddev artisan tinker --execute="\
  \$u = App\Models\User::first(); \
  \$p = App\Models\Project::where('code','ATLS-FLT')->first(); \
  app(App\Services\Timer\TimerService::class)->start(\$u, \$p, null, 'sanity check'); \
"
```
Reload the page. Topbar chip should show "Fleet Console v2" and an incrementing clock. Then:
```
host$ ddev artisan tinker --execute="\
  app(App\Services\Timer\TimerService::class)->stop(App\Models\User::first()); \
"
```
Reload — chip goes back to idle.

- [ ] **Step 8: Run full test suite**

```
host$ ddev artisan test
```
Expected: no regressions.

- [ ] **Step 9: Commit**

```
host$ git add resources/js/composables/useTimer.js resources/js/composables/useRecent.js \
              resources/js/Components/RunningTimerChip.vue \
              resources/js/Components/Topbar.vue resources/js/Components/Sidebar.vue
host$ git commit -m "feat(chrome): live running-timer chip + sidebar wired to shared props"
```

---

## Task 5: ProjectController@index + Projects/Index page

The "Dashboard" — a table of projects with stats strip, filter chips, sparklines, sort. Replaces the Phase 0 placeholder.

**Files:**
- Create: `app/Http/Controllers/ProjectController.php`
- Create: `app/Support/DashboardProjections.php`
- Modify: `routes/web.php`
- Replace: `resources/js/Pages/Projects/Index.vue`
- Create: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing controller test**

`tests/Feature/Http/ProjectControllerTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('GET /projects renders Projects/Index with the project list', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT', 'status' => 'active',
        'budget_hours' => 220, 'budget_amount_rappen' => 31900_00,
        'rate_rappen' => 14500,
    ]);
    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'description' => 'work', 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        'billable' => true,
    ]);

    $this->actingAs($user)->get('/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->has('projects', 1, fn (Assert $p) => $p
                ->where('code', 'ATLS-FLT')
                ->where('name', 'Fleet Console v2')
                ->where('client.name', 'Atlas Robotics')
                ->where('budget_hours', 220)
                ->where('budget_amount', 31900)              // CHF, not rappen
                ->where('rate', 145)                         // CHF/h
                ->has('spent_hours')
                ->has('spent_amount')
                ->has('band')
                ->has('sparkline', 14)                       // 14 numbers
                ->etc()
            )
            ->has('stats', fn (Assert $s) => $s
                ->where('active', 1)
                ->has('week_hours')
                ->has('unbilled_amount')
                ->has('outstanding_amount')                  // 0 in Phase 2a
            )
            ->has('counts', fn (Assert $c) => $c
                ->where('active', 1)->where('all', 1)->where('archived', 0)
            )
        );
});

test('GET /projects filter=archived excludes active projects', function () {
    $user = User::factory()->create();
    Project::factory()->create(['status' => 'active']);
    Project::factory()->create(['status' => 'archived']);

    $this->actingAs($user)->get('/projects?filter=archived')
        ->assertInertia(fn (Assert $page) => $page->has('projects', 1));
});

test('POST /projects creates a new project', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $this->actingAs($user)->post('/projects', [
        'client_id' => $client->id,
        'name' => 'New Project',
        'code' => 'NEW-1',
        'glyph' => 'alt-0',
        'rate_rappen' => 12000,
        'budget_hours' => 100,
        'budget_amount_rappen' => 1200000,
        'billable' => true,
    ])->assertRedirect('/projects/NEW-1');

    expect(Project::where('code', 'NEW-1')->exists())->toBeTrue();
});

test('POST /projects rejects a duplicate code', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->create(['code' => 'DUP', 'client_id' => $client->id]);

    $this->actingAs($user)->post('/projects', [
        'client_id' => $client->id,
        'name' => 'x', 'code' => 'DUP',
        'glyph' => 'alt-0', 'rate_rappen' => 0,
        'billable' => true,
    ])->assertSessionHasErrors('code');
});

test('POST /projects/{p}/archive archives the project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['status' => 'active']);

    $this->actingAs($user)->post("/projects/{$project->code}/archive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe('archived');
});

test('unauthenticated /projects redirects to /login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run — expect FAIL (no controller)**

```
host$ ddev artisan test --filter=ProjectControllerTest
```
Expected: FAIL — route already returns placeholder, prop assertions miss `projects`.

- [ ] **Step 3: Create `DashboardProjections`**

`app/Support/DashboardProjections.php`:
```php
<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardProjections
{
    /**
     * Project list as shown on /projects, with computed `spent_*`, `band`, `last_activity_at`,
     * `sparkline` (14 numbers, hours per day for the past 14 days).
     *
     * @return Collection<int, array>
     */
    public static function projects(string $filter = 'active', ?string $search = null): Collection
    {
        $q = Project::query()->with('client:id,name');

        if ($filter === 'active')   $q->where('status', 'active');
        if ($filter === 'archived') $q->where('status', 'archived');

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('projects.name', 'like', "%{$search}%")
                  ->orWhere('projects.code', 'like', "%{$search}%")
                  ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $projects = $q->orderByDesc('updated_at')->get();
        $ids = $projects->pluck('id');

        // One aggregate query for all projects: total seconds + last_activity_at.
        $totals = TimeEntry::query()
            ->whereIn('project_id', $ids)
            ->selectRaw('
                project_id,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs,
                MAX(started_at) AS last_started_at
            ')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        // 14-day sparklines: one query, group by (project, day).
        $start = Carbon::today()->subDays(13);
        $spark = TimeEntry::query()
            ->whereIn('project_id', $ids)
            ->where('started_at', '>=', $start)
            ->selectRaw('
                project_id,
                DATE(started_at) AS day,
                SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))) AS secs
            ')
            ->groupBy('project_id', 'day')
            ->get()
            ->groupBy('project_id');

        return $projects->map(function (Project $p) use ($totals, $spark, $start) {
            $t = $totals->get($p->id);
            $secs = $t ? (int) $t->secs : 0;
            $hours = round($secs / 3600, 2);
            $amount = (int) round($hours * (int) $p->rate_rappen);
            $pct = $p->budget_hours > 0 ? (int) round(($hours / $p->budget_hours) * 100) : 0;
            $band = $pct > 100 ? 'over' : ($pct >= 85 ? 'warn' : 'ok');

            $byDay = ($spark->get($p->id) ?? collect())->keyBy('day');
            $sparkline = [];
            for ($i = 0; $i < 14; $i++) {
                $key = $start->copy()->addDays($i)->toDateString();
                $sparkline[] = round((($byDay->get($key)->secs ?? 0)) / 3600, 1);
            }

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'glyph' => $p->glyph,
                'status' => $p->status,
                'billable' => (bool) $p->billable,
                'retainer' => (bool) $p->retainer,
                'rate' => (int) round($p->rate_rappen / 100),
                'budget_hours' => (int) $p->budget_hours,
                'budget_amount' => (int) round($p->budget_amount_rappen / 100),
                'spent_hours' => $hours,
                'spent_amount' => round($amount / 100, 2),
                'pct_hours' => $pct,
                'band' => $band,
                'last_activity_at' => $t?->last_started_at,
                'client' => [
                    'id' => $p->client->id,
                    'name' => $p->client->name,
                ],
                'sparkline' => $sparkline,
            ];
        });
    }

    /** Top-of-page summary numbers. */
    public static function stats(User $user): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $weekSecs = (int) TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $weekStart)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s')
            ->value('s');

        // Unbilled = billable entries with NULL invoice_id, summed across all projects.
        $unbilledRows = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereNull('invoice_id')
            ->where('billable', true)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP())) * projects.rate_rappen) / 3600, 0) AS rappen,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs
            ')
            ->first();

        return [
            'active' => Project::active()->count(),
            'week_hours' => round($weekSecs / 3600, 1),
            'unbilled_amount' => round(((float) $unbilledRows->rappen) / 100, 2),
            'unbilled_hours' => round(((int) $unbilledRows->secs) / 3600, 1),
            'outstanding_amount' => 0.0,        // populated in Phase 2b
        ];
    }
}
```

- [ ] **Step 4: `StoreProjectRequest` + `UpdateProjectRequest`**

`app/Http/Requests/StoreProjectRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }   // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:32|unique:projects,code',
            'description' => 'nullable|string',
            'glyph' => 'required|in:alt-0,alt-1,alt-2,alt-3,alt-4',
            'billable' => 'required|boolean',
            'retainer' => 'sometimes|boolean',
            'retainer_hours' => 'nullable|integer|min:0',
            'retainer_resets_monthly' => 'sometimes|boolean',
            'budget_hours' => 'required|integer|min:0',
            'budget_amount_rappen' => 'required|integer|min:0',
            'rate_rappen' => 'required|integer|min:0',
            'started_on' => 'nullable|date',
            'deadline_on' => 'nullable|date|after_or_equal:started_on',
        ];
    }
}
```

`app/Http/Requests/UpdateProjectRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('project')->id;
        return [
            'client_id' => 'sometimes|exists:clients,id',
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('projects', 'code')->ignore($id)],
            'description' => 'nullable|string',
            'glyph' => 'sometimes|in:alt-0,alt-1,alt-2,alt-3,alt-4',
            'billable' => 'sometimes|boolean',
            'retainer' => 'sometimes|boolean',
            'retainer_hours' => 'nullable|integer|min:0',
            'retainer_resets_monthly' => 'sometimes|boolean',
            'budget_hours' => 'sometimes|integer|min:0',
            'budget_amount_rappen' => 'sometimes|integer|min:0',
            'rate_rappen' => 'sometimes|integer|min:0',
            'started_on' => 'nullable|date',
            'deadline_on' => 'nullable|date',
        ];
    }
}
```

- [ ] **Step 5: `ProjectController` (index + store + archive stubs; show/update land in Task 6)**

`app/Http/Controllers/ProjectController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Support\DashboardProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'active')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Projects/Index', [
            'projects' => DashboardProjections::projects($filter, $search)->values(),
            'stats'    => DashboardProjections::stats($request->user()),
            'counts'   => [
                'active'   => Project::active()->count(),
                'all'      => Project::count(),
                'archived' => Project::archived()->count(),
            ],
            'filters'  => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());
        return redirect("/projects/{$project->code}");
    }

    public function show(Project $project): Response
    {
        // Implemented in Task 6.
        abort(501, 'Project show not yet implemented');
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());
        return back();
    }

    public function archive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'archived']);
        return back();
    }
}
```

- [ ] **Step 6: Replace `routes/web.php` routes for projects**

In `routes/web.php`, replace the `Route::get('/projects', ...)` placeholder and add the four new routes:

```php
use App\Http\Controllers\ProjectController;

Route::get   ('/projects',                   [ProjectController::class, 'index'])->name('projects.index');
Route::post  ('/projects',                   [ProjectController::class, 'store'])->name('projects.store');
Route::get   ('/projects/{project:code}',    [ProjectController::class, 'show'])->name('projects.show');
Route::patch ('/projects/{project}',         [ProjectController::class, 'update'])->name('projects.update');
Route::post  ('/projects/{project:code}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
```

- [ ] **Step 7: Run controller tests — expect PASS for index/store/archive**

```
host$ ddev artisan test --filter=ProjectControllerTest
```
Expected: PASS for index, archive, store, duplicate-code, and auth tests. The "show" tests in Task 6 don't exist yet.

- [ ] **Step 8: Replace `Projects/Index.vue`**

`resources/js/Pages/Projects/Index.vue` — port `design/ernte/project/views.jsx` lines 4-165 (`function Dashboard`). Full Vue:

```vue
<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sparkline from '@/Components/Sparkline.vue';
import BudgetBar from '@/Components/BudgetBar.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  projects: { type: Array, required: true },
  stats:    { type: Object, required: true },
  counts:   { type: Object, required: true },
  filters:  { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'active');

function setFilter(f) {
  router.get('/projects', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

let searchTimer = null;
function onSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get('/projects', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
  }, 250);
}

function fmtMoneyShort(v) { return '€' + Math.round(v).toLocaleString('en-US'); }

const sparkColor = (band) => band === 'over' ? 'var(--red)' : band === 'warn' ? 'var(--rust)' : 'var(--forest)';

function relativeTime(iso) {
  if (!iso) return '—';
  const sec = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (sec < 60)        return `${sec}s ago`;
  if (sec < 3600)      return `${Math.floor(sec / 60)}m ago`;
  if (sec < 86400)     return `${Math.floor(sec / 3600)}h ago`;
  if (sec < 86400 * 7) return `${Math.floor(sec / 86400)}d ago`;
  return new Date(iso).toLocaleDateString();
}
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / projects</div>
      <h1 class="page-title">
        Projects
        <span class="meta">{{ projects.length }} of {{ counts.all }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn ghost" disabled title="Phase 2b">Import</button>
      <button class="btn primary" disabled title="Phase 2b">+ New project</button>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Active projects</div>
      <div class="val">{{ stats.active }}<span class="unit">running</span></div>
    </div>
    <div class="stat">
      <div class="label">This week</div>
      <div class="val">{{ stats.week_hours.toFixed(1) }}<span class="unit">h / 40h</span></div>
    </div>
    <div class="stat">
      <div class="label">Unbilled</div>
      <div class="val">{{ fmtMoneyShort(stats.unbilled_amount) }}<span class="unit">· {{ stats.unbilled_hours.toFixed(1) }}h</span></div>
    </div>
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.outstanding_amount) }}</div>
      <div class="delta muted">(Phase 2b)</div>
    </div>
  </div>

  <div class="filter-row">
    <button
      v-for="c in [
        { id: 'active',   label: 'Active',   n: counts.active },
        { id: 'all',      label: 'All',      n: counts.all },
        { id: 'archived', label: 'Archived', n: counts.archived },
      ]" :key="c.id"
      class="chip"
      :aria-pressed="filter === c.id"
      @click="setFilter(c.id)"
    >{{ c.label }} <span class="dim" style="margin-left: 4px">{{ c.n }}</span></button>

    <span class="filter-divider" />
    <div class="search">
      <span style="color: var(--ink-4)">⌕</span>
      <input v-model="search" placeholder="filter…" @input="onSearch" />
      <span class="kbd">/</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l" style="width: 280px">Project</th>
          <th>Client</th>
          <th class="num" style="width: 90px">Rate</th>
          <th style="width: 260px">Hours budget</th>
          <th style="width: 240px">Fees budget</th>
          <th style="width: 130px">14-day</th>
          <th style="width: 90px">Status</th>
          <th class="pad-r num" style="width: 110px">Last activity</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in projects" :key="p.id">
          <td class="pad-l strong">
            <Link :href="`/projects/${p.code}`" class="proj-cell" style="color: inherit">
              <span class="proj-glyph" :class="p.glyph">{{ p.code[0] }}</span>
              <span>
                {{ p.name }}
                <span class="dim" style="margin-left: 8px; font-weight: 400">{{ p.code }}</span>
              </span>
            </Link>
          </td>
          <td>{{ p.client.name }}</td>
          <td class="num">
            <template v-if="p.rate">€{{ p.rate }}/h</template>
            <span v-else class="dim">—</span>
          </td>
          <td>
            <BudgetBar v-if="p.budget_hours > 0" :spent="p.spent_hours" :budget="p.budget_hours" unit="h" />
            <span v-else class="dim">no budget · {{ p.spent_hours.toFixed(1) }}h logged</span>
          </td>
          <td>
            <BudgetBar v-if="p.budget_amount > 0" :spent="p.spent_amount" :budget="p.budget_amount" unit="€" />
            <span v-else class="dim">non-billable</span>
          </td>
          <td><Sparkline :data="p.sparkline" :w="120" :h="20" :color="sparkColor(p.band)" /></td>
          <td>
            <span v-if="p.retainer" class="badge dot active">retainer</span>
            <span v-else-if="p.band === 'over'" class="badge dot over">over</span>
            <span v-else-if="p.band === 'warn'" class="badge dot warn">at risk</span>
            <span v-else class="badge dot active">active</span>
          </td>
          <td class="pad-r num dim">{{ relativeTime(p.last_activity_at) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

- [ ] **Step 9: Visit `/projects` in the browser**

```
host$ ddev npm run dev   # if not already running
```
Visit https://ernte.ddev.site/projects. You should see 8 demo projects in the table (from `DemoFixturesSeeder`). Click a chip — URL updates with `?filter=…` and the table filters. Type in the search box — the table narrows after 250ms.

- [ ] **Step 10: Full test suite**

```
host$ ddev artisan test
```
Expected: no regressions.

- [ ] **Step 11: Commit**

```
host$ git add app/Http/Controllers/ProjectController.php \
              app/Http/Requests/StoreProjectRequest.php app/Http/Requests/UpdateProjectRequest.php \
              app/Support/DashboardProjections.php \
              routes/web.php \
              resources/js/Pages/Projects/Index.vue \
              tests/Feature/Http/ProjectControllerTest.php
host$ git commit -m "feat(projects): index page + dashboard projections + store/archive"
```

---

## Task 6: ProjectController@show + Projects/Show page

The project detail page. Phase 2a ships the **overview tab** (burn-down + tasks + recent entries + details sidebar + heatmap). The entries/team/settings tabs are placeholders in 2a — they get fleshed out in 2b.

**Files:**
- Modify: `app/Http/Controllers/ProjectController.php` (implement `show`)
- Create: `app/Support/ProjectDetail.php` (aggregates the show payload)
- Create: `resources/js/Pages/Projects/Show.vue`
- Modify: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Add tests for `show`**

Append to `tests/Feature/Http/ProjectControllerTest.php`:
```php
test('GET /projects/{code} renders Projects/Show with overview payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Fleet Console v2', 'code' => 'ATLS-FLT', 'description' => 'Operator UI',
        'budget_hours' => 220, 'budget_amount_rappen' => 31900_00,
        'rate_rappen' => 14500,
        'started_on' => '2026-03-02', 'deadline_on' => '2026-07-18',
    ]);
    \App\Models\Task::create(['project_id' => $project->id, 'name' => 'Cluster rendering', 'budget_hours' => 16, 'done' => false, 'sort_order' => 0]);
    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'description' => 'work', 'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true,
    ]);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('project.code', 'ATLS-FLT')
            ->where('project.client.name', 'Atlas Robotics')
            ->has('project.spent_hours')
            ->has('project.budget_hours')
            ->has('project.band')
            ->has('tasks', 1, fn (Assert $t) => $t
                ->where('name', 'Cluster rendering')
                ->where('done', false)
                ->where('budget_hours', 16)
                ->has('spent_hours')
                ->etc()
            )
            ->has('recent_entries', 1)
            ->has('heatmap', 60)
            ->has('counts.entries')
            ->has('counts.tasks')
        );
});

test('GET /projects/UNKNOWN 404s', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/projects/UNKNOWN')->assertNotFound();
});

test('PATCH /projects/{p} updates fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Old']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertRedirect();

    expect($project->fresh()->name)->toBe('Renamed');
});
```

- [ ] **Step 2: Run — expect FAIL (show throws 501)**

```
host$ ddev artisan test --filter=ProjectControllerTest
```
Expected: the 3 new tests fail with 501 / wrong component.

- [ ] **Step 3: `ProjectDetail` aggregator**

`app/Support/ProjectDetail.php`:
```php
<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class ProjectDetail
{
    public static function payload(Project $project): array
    {
        $project->load('client:id,name,short_code');

        $secs = (int) TimeEntry::query()
            ->where('project_id', $project->id)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s')
            ->value('s');
        $hours = round($secs / 3600, 2);
        $amount = (int) round($hours * (int) $project->rate_rappen);
        $pct = $project->budget_hours > 0 ? (int) round(($hours / $project->budget_hours) * 100) : 0;
        $band = $pct > 100 ? 'over' : ($pct >= 85 ? 'warn' : 'ok');

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'glyph' => $project->glyph,
                'status' => $project->status,
                'description' => $project->description,
                'billable' => (bool) $project->billable,
                'retainer' => (bool) $project->retainer,
                'rate' => (int) round($project->rate_rappen / 100),
                'budget_hours' => (int) $project->budget_hours,
                'budget_amount' => (int) round($project->budget_amount_rappen / 100),
                'spent_hours' => $hours,
                'spent_amount' => round($amount / 100, 2),
                'pct_hours' => $pct,
                'band' => $band,
                'started_on' => $project->started_on?->toDateString(),
                'deadline_on' => $project->deadline_on?->toDateString(),
                'client' => [
                    'id' => $project->client->id,
                    'name' => $project->client->name,
                ],
            ],
            'tasks' => self::tasks($project),
            'recent_entries' => self::recentEntries($project, limit: 8),
            'heatmap' => self::heatmap($project),
            'counts' => [
                'entries' => TimeEntry::where('project_id', $project->id)->count(),
                'tasks'   => Task::where('project_id', $project->id)->count(),
            ],
        ];
    }

    private static function tasks(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $spent = TimeEntry::query()
            ->where('project_id', $project->id)
            ->whereNotNull('task_id')
            ->selectRaw('
                task_id,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS s
            ')
            ->groupBy('task_id')
            ->pluck('s', 'task_id');

        return $tasks->map(fn (Task $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'done' => (bool) $t->done,
            'budget_hours' => (int) ($t->budget_hours ?? 0),
            'spent_hours' => round(((int) ($spent[$t->id] ?? 0)) / 3600, 2),
            'sort_order' => (int) $t->sort_order,
        ])->all();
    }

    private static function recentEntries(Project $project, int $limit): array
    {
        return TimeEntry::query()
            ->where('project_id', $project->id)
            ->with('task:id,name')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'description' => $e->description,
                'task_name' => $e->task?->name,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_seconds' => $e->duration_seconds,
                'billable' => (bool) $e->billable,
                'running' => $e->ended_at === null,
            ])
            ->all();
    }

    /** 60 cells, hours/day, oldest → newest (today is the last cell). */
    private static function heatmap(Project $project): array
    {
        $start = Carbon::today()->subDays(59);

        $byDay = TimeEntry::query()
            ->where('project_id', $project->id)
            ->where('started_at', '>=', $start)
            ->selectRaw('
                DATE(started_at) AS day,
                SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))) AS s
            ')
            ->groupBy('day')
            ->pluck('s', 'day');

        $cells = [];
        for ($i = 0; $i < 60; $i++) {
            $key = $start->copy()->addDays($i)->toDateString();
            $cells[] = round(((int) ($byDay[$key] ?? 0)) / 3600, 1);
        }
        return $cells;
    }
}
```

- [ ] **Step 4: Implement `ProjectController@show`**

Replace the placeholder body in `app/Http/Controllers/ProjectController.php`:
```php
public function show(Project $project): Response
{
    return Inertia::render('Projects/Show', \App\Support\ProjectDetail::payload($project));
}
```

- [ ] **Step 5: Run show tests — expect PASS**

```
host$ ddev artisan test --filter=ProjectControllerTest
```
Expected: all `ProjectControllerTest` tests pass.

- [ ] **Step 6: `Projects/Show.vue`**

`resources/js/Pages/Projects/Show.vue` — port `design/ernte/project/views.jsx` lines 168-300 (`function ProjectDetail`). Phase 2a renders the overview tab; the other tab buttons are disabled placeholders.

```vue
<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BurnDown from '@/Components/BurnDown.vue';
import Heatmap from '@/Components/Heatmap.vue';
import EntryRow from '@/Components/EntryRow.vue';
import TaskRow from '@/Components/TaskRow.vue';
import { pushRecent } from '@/composables/useRecent.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  tasks:   { type: Array,  required: true },
  recent_entries: { type: Array, required: true },
  heatmap: { type: Array, required: true },
  counts:  { type: Object, required: true },
});

const tab = ref('overview');

onMounted(() => {
  pushRecent({ url: `/projects/${props.project.code}`, label: props.project.name });
});

function fmtHours(h) { return `${h.toFixed(1)}h`; }
function fmtMoneyShort(v) { return '€' + Math.round(v).toLocaleString('en-US'); }

const remaining = computed(() => Math.max(0, props.project.budget_hours - props.project.spent_hours));
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">~ / projects</Link>
        <span class="ascii-dot">/</span>
        <span>{{ project.code }}</span>
      </div>
      <h1 class="page-title">
        <span class="proj-glyph" :class="project.glyph" style="width: 28px; height: 28px; font-size: 14px">{{ project.code[0] }}</span>
        {{ project.name }}
        <span class="meta">{{ project.client.name }}<span class="ascii-dot">·</span>€{{ project.rate }}/h</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn ghost" disabled title="Use the timer page or ⌘+space (Phase 2b shortcut)">⏵ Start timer</button>
      <button class="btn" disabled title="Phase 2b">Export</button>
      <button class="btn primary" disabled title="Phase 2b">+ Invoice</button>
    </div>
  </div>

  <div class="filter-row">
    <button
      v-for="t in [
        { id: 'overview', label: 'Overview' },
        { id: 'entries',  label: 'Entries',  count: counts.entries },
        { id: 'tasks',    label: 'Tasks',    count: counts.tasks },
        { id: 'team',     label: 'Team',     count: 1 },
        { id: 'settings', label: 'Settings' },
      ]" :key="t.id"
      class="chip"
      :aria-pressed="tab === t.id"
      :disabled="t.id !== 'overview'"
      @click="tab = t.id"
    >
      {{ t.label }}
      <span v-if="t.count !== undefined" class="dim" style="margin-left: 4px">{{ t.count }}</span>
    </button>
  </div>

  <div class="detail-grid">
    <div class="detail-main">
      <h3 class="section-title">Budget burn-down</h3>
      <BurnDown :spent="project.spent_hours" :budget="Math.max(project.budget_hours, 1)" />

      <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin: 20px 0 28px">
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Hours spent</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px" :style="{ color: project.band === 'over' ? 'var(--red)' : 'var(--ink)' }">
            {{ fmtHours(project.spent_hours) }}
          </div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">of {{ fmtHours(project.budget_hours) }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Fees</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ fmtMoneyShort(project.spent_amount) }}</div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">of {{ fmtMoneyShort(project.budget_amount) }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Remaining</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ fmtHours(remaining) }}</div>
          <div style="font-size: var(--fs-xs); color: var(--ink-3); margin-top: 2px">{{ project.band === 'over' ? 'exceeded' : 'available' }}</div>
        </div>
        <div>
          <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">Budget used</div>
          <div style="font-size: var(--fs-xl); font-weight: 600; margin-top: 4px">{{ project.pct_hours }}%</div>
        </div>
      </div>

      <h3 class="section-title">Tasks</h3>
      <div class="task-list">
        <TaskRow v-for="t in tasks" :key="t.id" :task="t" :project-id="project.id" />
        <button class="btn ghost" style="margin-top: 12px; align-self: flex-start" disabled title="Wire to TaskController in next iteration">+ Add task</button>
      </div>

      <h3 class="section-title" style="margin-top: 28px">Recent entries</h3>
      <div>
        <EntryRow v-for="(e, i) in recent_entries" :key="e.id" :entry="e" :color-index="i" />
        <div v-if="recent_entries.length === 0" class="muted" style="padding: 12px">No entries yet</div>
      </div>
    </div>

    <aside class="detail-side">
      <h3 class="section-title">Details</h3>
      <dl class="kv">
        <dt>Client</dt><dd>{{ project.client.name }}</dd>
        <dt>Code</dt><dd><span class="mono-tag">{{ project.code }}</span></dd>
        <dt>Status</dt><dd><span class="badge dot" :class="project.status">{{ project.status }}</span></dd>
        <dt>Started</dt><dd>{{ project.started_on ?? '—' }}</dd>
        <dt>Due</dt><dd>{{ project.deadline_on ?? '—' }}</dd>
        <dt>Billing</dt><dd>{{ project.billable ? `Hourly · €${project.rate}/h` : 'non-billable' }}</dd>
      </dl>

      <h3 class="section-title" style="margin-top: 24px">Description</h3>
      <p style="font-size: var(--fs-sm); color: var(--ink-2); line-height: 1.6; margin: 0">{{ project.description ?? '—' }}</p>

      <h3 class="section-title" style="margin-top: 24px">Activity heatmap</h3>
      <Heatmap :cells="heatmap" />
    </aside>
  </div>
</template>
```

- [ ] **Step 7: `EntryRow.vue`**

`resources/js/Components/EntryRow.vue`:
```vue
<script setup>
defineProps({
  entry: { type: Object, required: true },
  colorIndex: { type: Number, default: 0 },
});

const COLORS = ['#2d4a3a', '#c97b3c', '#b8941f', '#1a1a1a', '#7a8c5c', '#b54834'];

function fmtTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}
function fmtHM(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}
</script>

<template>
  <div class="entry-row">
    <div class="bar-color" :style="{ background: COLORS[colorIndex % COLORS.length] }" />
    <div class="desc">
      {{ entry.description || entry.task_name || '—' }}
      <span v-if="entry.task_name && entry.description !== entry.task_name" class="sub">{{ entry.task_name }}</span>
    </div>
    <div class="time">
      {{ fmtTime(entry.started_at) }} –
      <span v-if="entry.running" style="color: var(--rust)">now</span>
      <span v-else>{{ fmtTime(entry.ended_at) }}</span>
    </div>
    <div class="dur">{{ fmtHM(entry.duration_seconds) }}</div>
    <div class="billable" :class="{ no: !entry.billable }">{{ entry.billable ? 'billable' : '—' }}</div>
  </div>
</template>
```

- [ ] **Step 8: `TaskRow.vue`**

`resources/js/Components/TaskRow.vue`:
```vue
<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  task: { type: Object, required: true },
  projectId: { type: Number, required: true },
});

const pct = computed(() => props.task.budget_hours > 0
  ? Math.round((props.task.spent_hours / props.task.budget_hours) * 100)
  : 0);

function toggleDone() {
  router.patch(`/tasks/${props.task.id}`, { done: !props.task.done }, { preserveScroll: true });
}
</script>

<template>
  <div class="task-row">
    <button class="task-check" :class="{ done: task.done }" @click="toggleDone">{{ task.done ? '✓' : '' }}</button>
    <div class="task-name" :class="{ done: task.done }">{{ task.name }}</div>
    <div class="task-num">{{ task.spent_hours.toFixed(1) }}h / {{ task.budget_hours }}h</div>
    <div class="task-num dim">{{ pct }}%</div>
    <div class="task-bar">
      <div
        class="task-bar-fill"
        :style="{ width: `${Math.min(100, pct)}%`, background: pct > 100 ? 'var(--red)' : 'var(--forest)' }"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 9: Visit a project in the browser**

```
host$ ddev npm run dev
```
Visit https://ernte.ddev.site/projects, click any project. You should land on `/projects/ATLS-FLT` (or similar), see the burn-down chart, the tasks list, recent entries, and the heatmap on the right.

- [ ] **Step 10: Full test suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 11: Commit**

```
host$ git add app/Http/Controllers/ProjectController.php app/Support/ProjectDetail.php \
              resources/js/Pages/Projects/Show.vue \
              resources/js/Components/EntryRow.vue resources/js/Components/TaskRow.vue \
              tests/Feature/Http/ProjectControllerTest.php
host$ git commit -m "feat(projects): show page (overview tab) + ProjectDetail aggregator"
```

---

## Task 7: TaskController

CRUD for tasks. Used by the task list in `Projects/Show` (rename, toggle done, set budget, reorder, delete).

**Files:**
- Create: `app/Http/Controllers/TaskController.php`
- Create: `app/Http/Requests/StoreTaskRequest.php`
- Create: `app/Http/Requests/UpdateTaskRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Http/TaskControllerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Http/TaskControllerTest.php`:
```php
<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->actingAs($this->user);
});

test('POST /tasks creates a task on the given project', function () {
    $this->post('/tasks', [
        'project_id' => $this->project->id,
        'name' => 'New task',
        'budget_hours' => 8,
    ])->assertRedirect();

    expect(Task::where('project_id', $this->project->id)->first())
        ->name->toBe('New task')
        ->budget_hours->toBe(8)
        ->done->toBeFalse();
});

test('POST /tasks rejects empty name', function () {
    $this->post('/tasks', ['project_id' => $this->project->id, 'name' => ''])
        ->assertSessionHasErrors('name');
});

test('PATCH /tasks/{id} toggles done', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 't', 'sort_order' => 0, 'done' => false]);

    $this->patch("/tasks/{$task->id}", ['done' => true])->assertRedirect();
    expect($task->fresh()->done)->toBeTrue();

    $this->patch("/tasks/{$task->id}", ['done' => false])->assertRedirect();
    expect($task->fresh()->done)->toBeFalse();
});

test('PATCH /tasks/{id} renames + updates budget', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 'old', 'sort_order' => 0]);

    $this->patch("/tasks/{$task->id}", ['name' => 'new', 'budget_hours' => 12])->assertRedirect();
    $t = $task->fresh();
    expect($t->name)->toBe('new');
    expect($t->budget_hours)->toBe(12);
});

test('PATCH /tasks/reorder updates sort_order for many tasks atomically', function () {
    $a = Task::create(['project_id' => $this->project->id, 'name' => 'A', 'sort_order' => 0]);
    $b = Task::create(['project_id' => $this->project->id, 'name' => 'B', 'sort_order' => 1]);
    $c = Task::create(['project_id' => $this->project->id, 'name' => 'C', 'sort_order' => 2]);

    $this->patch('/tasks/reorder', [
        'order' => [$c->id, $a->id, $b->id],
    ])->assertRedirect();

    expect($a->fresh()->sort_order)->toBe(1);
    expect($b->fresh()->sort_order)->toBe(2);
    expect($c->fresh()->sort_order)->toBe(0);
});

test('DELETE /tasks/{id} deletes the task', function () {
    $task = Task::create(['project_id' => $this->project->id, 'name' => 'gone', 'sort_order' => 0]);

    $this->delete("/tasks/{$task->id}")->assertRedirect();
    expect(Task::find($task->id))->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=TaskControllerTest
```
Expected: all 6 fail — no routes.

- [ ] **Step 3: `StoreTaskRequest` + `UpdateTaskRequest`**

`app/Http/Requests/StoreTaskRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'budget_hours' => 'nullable|integer|min:0',
        ];
    }
}
```

`app/Http/Requests/UpdateTaskRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'budget_hours' => 'sometimes|nullable|integer|min:0',
            'done' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 4: `TaskController`**

`app/Http/Controllers/TaskController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $next = (int) Task::where('project_id', $request->integer('project_id'))->max('sort_order');
        Task::create([
            ...$request->validated(),
            'sort_order' => $next + 1,
            'done' => false,
        ]);
        return back();
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $task->update($request->validated());
        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();
        return back();
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|integer|exists:tasks,id',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $i => $taskId) {
                Task::where('id', $taskId)->update(['sort_order' => $i]);
            }
        });

        return back();
    }
}
```

- [ ] **Step 5: Wire routes**

Append to `routes/web.php` inside the `auth` group:
```php
use App\Http\Controllers\TaskController;

Route::post  ('/tasks',          [TaskController::class, 'store'])->name('tasks.store');
Route::patch ('/tasks/reorder',  [TaskController::class, 'reorder'])->name('tasks.reorder');
Route::patch ('/tasks/{task}',   [TaskController::class, 'update'])->name('tasks.update');
Route::delete('/tasks/{task}',   [TaskController::class, 'destroy'])->name('tasks.destroy');
```
**Order matters:** `/tasks/reorder` must come before `/tasks/{task}` so it doesn't get matched as a task id.

- [ ] **Step 6: Run — expect PASS**

```
host$ ddev artisan test --filter=TaskControllerTest
```
Expected: 6/6 pass.

- [ ] **Step 7: Full suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 8: Commit**

```
host$ git add app/Http/Controllers/TaskController.php \
              app/Http/Requests/StoreTaskRequest.php app/Http/Requests/UpdateTaskRequest.php \
              routes/web.php \
              tests/Feature/Http/TaskControllerTest.php
host$ git commit -m "feat(tasks): TaskController CRUD + reorder"
```

---

## Task 8: TimerController + Timer/Today page

Wires the four `POST /timer/*` endpoints to `TimerService` (already built in Phase 1) and ships the `Timer/Today` page (hero timer, today's entries, by-project breakdown, quick-start, shortcuts).

**Files:**
- Create: `app/Http/Controllers/TimerController.php`
- Create: `app/Http/Requests/StartTimerRequest.php`
- Create: `app/Support/TimerToday.php`
- Modify: `routes/web.php`
- Create: `resources/js/Components/TimerHero.vue`
- Replace: `resources/js/Pages/Timer/Today.vue`
- Create: `tests/Feature/Http/TimerControllerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Http/TimerControllerTest.php`:
```php
<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
    $this->actingAs($this->user);
});

test('GET /timer renders Timer/Today with today payload', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'morning task',
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
        'billable' => true,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('entries', 1)
            ->has('totals', fn (Assert $t) => $t
                ->has('total_seconds')
                ->has('billable_seconds')
                ->has('earnings_amount')
            )
            ->has('by_project', 1)
            ->has('quick_start', fn (Assert $q) => $q->etc())
        );
});

test('POST /timer/start creates a running entry', function () {
    $this->post('/timer/start', [
        'project_id' => $this->project->id,
        'description' => 'kick off',
    ])->assertRedirect();

    expect(TimeEntry::running()->count())->toBe(1);
    $e = TimeEntry::running()->first();
    expect($e->description)->toBe('kick off');
    expect($e->project_id)->toBe($this->project->id);
});

test('POST /timer/start auto-stops the previous running entry', function () {
    $other = Project::factory()->create();

    $this->post('/timer/start', ['project_id' => $other->id]);
    $first = TimeEntry::running()->first();
    expect($first)->not->toBeNull();

    $this->post('/timer/start', ['project_id' => $this->project->id]);

    expect(TimeEntry::running()->count())->toBe(1);
    expect($first->fresh()->ended_at)->not->toBeNull();
});

test('POST /timer/stop ends the running entry', function () {
    $this->post('/timer/start', ['project_id' => $this->project->id]);
    expect(TimeEntry::running()->count())->toBe(1);

    $this->post('/timer/stop')->assertRedirect();
    expect(TimeEntry::running()->count())->toBe(0);
});

test('POST /timer/switch behaves like start', function () {
    $other = Project::factory()->create();
    $this->post('/timer/start', ['project_id' => $other->id]);

    $this->post('/timer/switch', ['project_id' => $this->project->id, 'description' => 'new context'])
        ->assertRedirect();

    expect(TimeEntry::running()->first()->project_id)->toBe($this->project->id);
});

test('POST /timer/discard removes the running entry without keeping a row', function () {
    $this->post('/timer/start', ['project_id' => $this->project->id]);
    $id = TimeEntry::running()->first()->id;

    $this->post('/timer/discard')->assertRedirect();
    expect(TimeEntry::find($id))->toBeNull();
});

test('POST /timer/start with task_id requires the task to belong to the project', function () {
    $otherProject = Project::factory()->create();
    $task = Task::create(['project_id' => $otherProject->id, 'name' => 'x', 'sort_order' => 0]);

    $this->post('/timer/start', [
        'project_id' => $this->project->id,
        'task_id' => $task->id,
    ])->assertSessionHasErrors('task_id');
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=TimerControllerTest
```
Expected: all fail — no controller.

- [ ] **Step 3: `StartTimerRequest`**

`app/Http/Requests/StartTimerRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartTimerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where(fn ($q) => $q->where('project_id', $this->integer('project_id'))),
            ],
            'description' => 'nullable|string|max:500',
        ];
    }
}
```

- [ ] **Step 4: `TimerToday` aggregator**

`app/Support/TimerToday.php`:
```php
<?php

namespace App\Support;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

class TimerToday
{
    public static function payload(User $user): array
    {
        $start = Carbon::today();
        $end = $start->copy()->addDay();

        $entries = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$start, $end])
            ->with(['project:id,name,code,glyph,rate_rappen', 'task:id,name'])
            ->orderBy('started_at')
            ->get();

        $totalSecs = 0;
        $billableSecs = 0;
        $earningsRappen = 0;

        $serialized = $entries->map(function (TimeEntry $e) use (&$totalSecs, &$billableSecs, &$earningsRappen) {
            $dur = $e->duration_seconds;
            $totalSecs += $dur;
            if ($e->billable) {
                $billableSecs += $dur;
                $earningsRappen += (int) round(($dur / 3600) * (int) $e->project->rate_rappen);
            }
            return [
                'id' => $e->id,
                'description' => $e->description,
                'task_name' => $e->task?->name,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_seconds' => $dur,
                'billable' => (bool) $e->billable,
                'running' => $e->ended_at === null,
                'project' => [
                    'id' => $e->project->id,
                    'name' => $e->project->name,
                    'code' => $e->project->code,
                    'glyph' => $e->project->glyph,
                ],
            ];
        });

        $byProject = $entries->groupBy('project_id')->map(function ($bucket) {
            $first = $bucket->first();
            $secs = $bucket->sum(fn (TimeEntry $e) => $e->duration_seconds);
            return [
                'project_id' => $first->project_id,
                'name' => $first->project->name,
                'code' => $first->project->code,
                'seconds' => (int) $secs,
            ];
        })->values()->all();

        $quickStart = Project::active()
            ->select('id', 'name', 'code', 'glyph')
            ->orderByDesc('updated_at')
            ->limit(4)
            ->get()
            ->all();

        return [
            'entries' => $serialized->all(),
            'totals' => [
                'total_seconds' => $totalSecs,
                'billable_seconds' => $billableSecs,
                'earnings_amount' => round($earningsRappen / 100, 2),
            ],
            'by_project' => $byProject,
            'quick_start' => $quickStart,
        ];
    }
}
```

- [ ] **Step 5: `TimerController`**

`app/Http/Controllers/TimerController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartTimerRequest;
use App\Models\Project;
use App\Models\Task;
use App\Services\Timer\TimerService;
use App\Support\TimerToday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimerController extends Controller
{
    public function __construct(private TimerService $timer) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Timer/Today', TimerToday::payload($request->user()));
    }

    public function start(StartTimerRequest $request): RedirectResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->start($request->user(), $project, $task, (string) $request->input('description', ''));

        return back();
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->timer->stop($request->user());
        return back();
    }

    public function switch(StartTimerRequest $request): RedirectResponse
    {
        return $this->start($request);
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->timer->discard($request->user());
        return back();
    }
}
```

- [ ] **Step 6: Wire routes**

Replace the placeholder `Route::get('/timer', ...)` in `routes/web.php` with:
```php
use App\Http\Controllers\TimerController;

Route::get ('/timer',          [TimerController::class, 'show'])->name('timer.show');
Route::post('/timer/start',    [TimerController::class, 'start'])->name('timer.start');
Route::post('/timer/stop',     [TimerController::class, 'stop'])->name('timer.stop');
Route::post('/timer/switch',   [TimerController::class, 'switch'])->name('timer.switch');
Route::post('/timer/discard',  [TimerController::class, 'discard'])->name('timer.discard');
```

- [ ] **Step 7: Run timer tests — expect PASS**

```
host$ ddev artisan test --filter=TimerControllerTest
```
Expected: 7/7 pass.

- [ ] **Step 8: `TimerHero.vue`**

`resources/js/Components/TimerHero.vue` — port `design/ernte/project/views.jsx` lines 386-427 (the timer-hero block). Receives no props; pulls everything from `useTimer()`:

```vue
<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTimer, fmtHMS } from '@/composables/useTimer.js';

const { running, elapsedSeconds, stop, discard } = useTimer();

const earnings = computed(() => {
  if (!running.value) return 0;
  const rate = running.value.project.rate_rappen / 100;
  return ((elapsedSeconds.value / 3600) * rate).toFixed(2);
});

const startedAtLocal = computed(() => {
  if (!running.value) return '';
  return new Date(running.value.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
});
</script>

<template>
  <div class="timer-hero" v-if="running">
    <div style="display: flex; align-items: flex-start; justify-content: space-between">
      <div>
        <div style="font-size: var(--fs-xs); letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3)">
          Running · {{ running.project.name }}
        </div>
        <div style="margin-top: 12px; color: var(--ink); font-size: var(--fs-md)">
          {{ running.description || running.task?.name || 'untitled' }}
        </div>
      </div>
      <div style="display: flex; align-items: center; gap: 6px; color: var(--rust); font-size: var(--fs-sm)">
        <span class="pulse" style="width: 6px; height: 6px; border-radius: 50%; background: var(--rust)" />
        started {{ startedAtLocal }}
      </div>
    </div>

    <div class="timer-display" style="margin-top: 18px">
      {{ fmtHMS(elapsedSeconds).slice(0, 5) }}<span class="ms">:{{ fmtHMS(elapsedSeconds).slice(6) }}</span>
    </div>

    <div class="timer-meta" v-if="running.billable">
      <span>billable · €{{ earnings }}</span>
      <span class="ascii-dot">·</span>
      <span>rate €{{ (running.project.rate_rappen / 100).toFixed(0) }}/h</span>
      <span class="ascii-dot">·</span>
      <span>{{ running.project.code }}</span>
    </div>

    <div style="margin-top: 20px; display: flex; gap: 8px">
      <button class="btn primary" style="min-width: 120px" @click="stop">■ stop</button>
      <button class="btn ghost" @click="discard">discard</button>
    </div>
  </div>

  <div v-else class="timer-hero" style="text-align: center; color: var(--ink-3); padding: 40px 0">
    No timer running. Pick a project below to start.
  </div>
</template>
```

- [ ] **Step 9: Replace `Timer/Today.vue`**

`resources/js/Pages/Timer/Today.vue` — port `design/ernte/project/views.jsx` lines 362-506:

```vue
<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TimerHero from '@/Components/TimerHero.vue';
import EntryRow from '@/Components/EntryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  entries:     { type: Array,  required: true },
  totals:      { type: Object, required: true },
  by_project:  { type: Array,  required: true },
  quick_start: { type: Array,  required: true },
});

function fmtHM(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

const today = new Date().toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

function startProject(projectId) {
  router.post('/timer/start', { project_id: projectId }, { preserveScroll: true });
}

const totalShare = (sec) => props.totals.total_seconds ? (sec / props.totals.total_seconds) * 100 : 0;
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / timer</div>
      <h1 class="page-title">
        Today
        <span class="meta">{{ today }}<span class="ascii-dot">·</span>{{ fmtHM(totals.total_seconds) }} logged</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn" disabled title="Manual entry — wired in EntryController">+ Manual entry</button>
    </div>
  </div>

  <div class="timer-stage">
    <div>
      <TimerHero />

      <div class="divider-row">Today's entries · {{ entries.length }}</div>
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" />
      <div v-if="entries.length === 0" class="muted" style="padding: 12px">No entries today yet</div>
    </div>

    <aside>
      <h3 class="section-title">Today summary</h3>
      <div style="border: 1px solid var(--border); padding: 16px; margin-bottom: 18px">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
          <span class="muted" style="font-size: var(--fs-xs)">TOTAL</span>
          <span style="font-size: var(--fs-xl); font-weight: 700">{{ fmtHM(totals.total_seconds) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 8px">
          <span class="muted" style="font-size: var(--fs-xs)">BILLABLE</span>
          <span style="font-size: var(--fs-md); color: var(--forest)">{{ fmtHM(totals.billable_seconds) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 4px">
          <span class="muted" style="font-size: var(--fs-xs)">EARNINGS</span>
          <span style="font-size: var(--fs-md); color: var(--ink)">€{{ totals.earnings_amount.toFixed(0) }}</span>
        </div>
      </div>

      <h3 class="section-title">By project</h3>
      <div v-for="p in by_project" :key="p.project_id" style="margin-bottom: 12px">
        <div style="display: flex; justify-content: space-between; font-size: var(--fs-sm)">
          <span>{{ p.name }}</span>
          <span>{{ fmtHM(p.seconds) }}</span>
        </div>
        <div class="budget-bar" style="margin-top: 4px">
          <div class="budget-fill" :style="{ width: `${totalShare(p.seconds)}%`, background: 'var(--accent)' }" />
        </div>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Quick start</h3>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <button
          v-for="p in quick_start" :key="p.id"
          class="btn ghost"
          style="justify-content: flex-start; padding: 6px 8px"
          @click="startProject(p.id)"
        >
          <span class="proj-glyph" :class="p.glyph" style="width: 12px; height: 12px; font-size: 8px">{{ p.code[0] }}</span>
          <span style="font-size: var(--fs-sm)">{{ p.name }}</span>
        </button>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Shortcuts</h3>
      <div style="display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; font-size: var(--fs-xs); color: var(--ink-3)">
        <span>Start / stop timer</span><span class="kbd">space</span>
        <span>New entry</span><span class="kbd">n</span>
        <span class="dim" style="grid-column: span 2; font-size: 10px">(shortcuts ship in Phase 2b)</span>
      </div>
    </aside>
  </div>
</template>
```

- [ ] **Step 10: Browser smoke**

```
host$ ddev npm run dev
```
Visit https://ernte.ddev.site/timer. Expect: "No timer running. Pick a project below to start." Click any Quick start project → timer chip lights up in topbar, hero shows the timer ticking. Click stop → goes back to idle.

- [ ] **Step 11: Full suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 12: Commit**

```
host$ git add app/Http/Controllers/TimerController.php app/Http/Requests/StartTimerRequest.php \
              app/Support/TimerToday.php routes/web.php \
              resources/js/Components/TimerHero.vue resources/js/Pages/Timer/Today.vue \
              tests/Feature/Http/TimerControllerTest.php
host$ git commit -m "feat(timer): TimerController + Timer/Today page + TimerHero component"
```

---

## Task 9: EntryController — manual entries CRUD

Lets the user log time without using the running timer (the spec says manual entries are a separate `POST /entries`). The Phase 2a UI for manual entry is minimal — the form ships as an inline form in `Timer/Today.vue` exposed via the previously-disabled "+ Manual entry" button. Phase 2b can elevate it to a modal.

**Files:**
- Create: `app/Http/Controllers/EntryController.php`
- Create: `app/Http/Requests/StoreEntryRequest.php`
- Create: `app/Http/Requests/UpdateEntryRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Timer/Today.vue` (enable the "+ Manual entry" button with an inline form)
- Create: `tests/Feature/Http/EntryControllerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Http/EntryControllerTest.php`:
```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['billable' => true]);
    $this->actingAs($this->user);
});

test('POST /entries creates a finished entry', function () {
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'description' => 'Yesterday session',
        'started_at' => '2026-05-27T09:00:00Z',
        'ended_at'   => '2026-05-27T10:30:00Z',
        'billable'   => true,
    ])->assertRedirect();

    $entry = TimeEntry::first();
    expect($entry->description)->toBe('Yesterday session');
    expect($entry->duration_seconds)->toBe(5400);
    expect($entry->ended_at)->not->toBeNull();
});

test('POST /entries rejects ended_at before started_at', function () {
    $this->post('/entries', [
        'project_id' => $this->project->id,
        'started_at' => '2026-05-27T10:00:00Z',
        'ended_at'   => '2026-05-27T09:00:00Z',
        'billable'   => true,
    ])->assertSessionHasErrors('ended_at');
});

test('POST /entries with no ended_at would create a second running entry — rejected', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now(), 'ended_at' => null, 'billable' => true, 'description' => 'already running',
    ]);

    $this->post('/entries', [
        'project_id' => $this->project->id,
        'started_at' => now()->toIso8601String(),
        'billable'   => true,
    ])->assertSessionHasErrors('ended_at');
});

test('PATCH /entries/{id} updates fields', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true, 'description' => 'old',
    ]);

    $this->patch("/entries/{$e->id}", ['description' => 'updated'])
        ->assertRedirect();

    expect($e->fresh()->description)->toBe('updated');
});

test('DELETE /entries/{id} removes the entry', function () {
    $e = TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'started_at' => now()->subHour(), 'ended_at' => now(),
        'billable' => true,
    ]);

    $this->delete("/entries/{$e->id}")->assertRedirect();
    expect(TimeEntry::find($e->id))->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=EntryControllerTest
```
Expected: all fail — no controller.

- [ ] **Step 3: `StoreEntryRequest` + `UpdateEntryRequest`**

`app/Http/Requests/StoreEntryRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'description' => 'nullable|string|max:500',
            'started_at' => 'required|date',
            'ended_at' => 'required|date|after:started_at',  // manual entries are always finished
            'billable' => 'required|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Defensive: even though we require ended_at, double-check no other running entry exists for this user.
            if (! $this->filled('ended_at')) {
                $running = TimeEntry::running()->where('user_id', $this->user()->id)->exists();
                if ($running) {
                    $v->errors()->add('ended_at', 'Cannot create a second running entry — stop the existing timer first.');
                }
            }
        });
    }
}
```

`app/Http/Requests/UpdateEntryRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'project_id' => 'sometimes|exists:projects,id',
            'task_id' => 'sometimes|nullable|exists:tasks,id',
            'description' => 'sometimes|nullable|string|max:500',
            'started_at' => 'sometimes|date',
            'ended_at' => 'sometimes|nullable|date|after:started_at',
            'billable' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 4: `EntryController`**

`app/Http/Controllers/EntryController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntryRequest;
use App\Http\Requests\UpdateEntryRequest;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;

class EntryController extends Controller
{
    public function store(StoreEntryRequest $request): RedirectResponse
    {
        TimeEntry::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);
        return back();
    }

    public function update(UpdateEntryRequest $request, TimeEntry $entry): RedirectResponse
    {
        $entry->update($request->validated());
        return back();
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        $entry->delete();
        return back();
    }
}
```

- [ ] **Step 5: Wire routes**

Append to `routes/web.php` inside the `auth` group:
```php
use App\Http\Controllers\EntryController;

Route::post  ('/entries',          [EntryController::class, 'store'])->name('entries.store');
Route::patch ('/entries/{entry}',  [EntryController::class, 'update'])->name('entries.update');
Route::delete('/entries/{entry}',  [EntryController::class, 'destroy'])->name('entries.destroy');
```

- [ ] **Step 6: Run — expect PASS**

```
host$ ddev artisan test --filter=EntryControllerTest
```
Expected: 5/5 pass.

- [ ] **Step 7: Add the inline manual-entry form to `Timer/Today.vue`**

In `resources/js/Pages/Timer/Today.vue`, replace the disabled `+ Manual entry` button with a toggle that reveals an inline form. Inside `<script setup>`, after the existing imports add:

```javascript
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const showManual = ref(false);

function isoLocal(d) {
  // 'YYYY-MM-DDTHH:MM' suitable for <input type="datetime-local">
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const now = new Date();
const oneHourAgo = new Date(now.getTime() - 60 * 60 * 1000);

const manualForm = useForm({
  project_id: '',
  description: '',
  started_at: isoLocal(oneHourAgo),
  ended_at: isoLocal(now),
  billable: true,
});

function submitManual() {
  manualForm.transform((data) => ({
    ...data,
    started_at: new Date(data.started_at).toISOString(),
    ended_at: new Date(data.ended_at).toISOString(),
  })).post('/entries', {
    onSuccess: () => { showManual.value = false; manualForm.reset(); },
    preserveScroll: true,
  });
}
```

Replace the `+ Manual entry` button with:
```vue
<button class="btn" @click="showManual = !showManual">{{ showManual ? '× cancel' : '+ Manual entry' }}</button>
```

Insert this form block above `<div class="timer-stage">`:
```vue
<form v-if="showManual" @submit.prevent="submitManual" style="border: 1px solid var(--border-strong); padding: 16px; margin: 12px 0; display: grid; grid-template-columns: 200px 1fr 160px 160px auto auto; gap: 10px; align-items: center">
  <select v-model="manualForm.project_id" required class="select">
    <option value="" disabled>project…</option>
    <option v-for="p in quick_start" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
  </select>
  <input v-model="manualForm.description" placeholder="what did you do?" class="input" />
  <input type="datetime-local" v-model="manualForm.started_at" required class="input" />
  <input type="datetime-local" v-model="manualForm.ended_at" required class="input" />
  <label style="display: flex; align-items: center; gap: 4px; font-size: var(--fs-sm)">
    <input type="checkbox" v-model="manualForm.billable" /> billable
  </label>
  <button type="submit" class="btn primary" :disabled="manualForm.processing">save</button>
</form>
<div v-if="showManual && Object.keys(manualForm.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-bottom: 8px">
  {{ Object.values(manualForm.errors).join(' · ') }}
</div>
```

- [ ] **Step 8: Browser smoke**

Visit https://ernte.ddev.site/timer. Click "+ Manual entry" → form appears. Pick a project, leave the default times, click save → redirect; the new entry appears in "Today's entries".

- [ ] **Step 9: Full suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 10: Commit**

```
host$ git add app/Http/Controllers/EntryController.php \
              app/Http/Requests/StoreEntryRequest.php app/Http/Requests/UpdateEntryRequest.php \
              routes/web.php \
              resources/js/Pages/Timer/Today.vue \
              tests/Feature/Http/EntryControllerTest.php
host$ git commit -m "feat(entries): EntryController + inline manual-entry form"
```

---

## Task 10: ClientController + Clients pages

Full CRUD for clients. Three pages: `Clients/Index` (table), `Clients/Create` (form), `Clients/Edit` (form).

**Files:**
- Create: `app/Http/Controllers/ClientController.php`
- Create: `app/Http/Requests/StoreClientRequest.php`
- Create: `app/Http/Requests/UpdateClientRequest.php`
- Create: `app/Support/ClientProjections.php`
- Modify: `routes/web.php`
- Replace: `resources/js/Pages/Clients/Index.vue`
- Create: `resources/js/Pages/Clients/Create.vue`
- Create: `resources/js/Pages/Clients/Edit.vue`
- Create: `tests/Feature/Http/ClientControllerTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Http/ClientControllerTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('GET /clients renders Clients/Index with projection rows', function () {
    $c = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR', 'default_rate_rappen' => 14500]);
    $p = Project::factory()->create(['client_id' => $c->id, 'rate_rappen' => 14500]);
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $p->id,
        'description' => 'work', 'started_at' => now()->startOfYear()->addDays(10),
        'ended_at' => now()->startOfYear()->addDays(10)->addHours(2),
        'billable' => true,
    ]);

    $this->get('/clients')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')
            ->has('clients', 1, fn (Assert $row) => $row
                ->where('name', 'Atlas Robotics')
                ->where('short_code', 'AR')
                ->where('default_rate', 145)
                ->where('projects_count', 1)
                ->has('hours_ytd')
                ->etc()
            )
        );
});

test('GET /clients/create renders Clients/Create', function () {
    $this->get('/clients/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Clients/Create'));
});

test('POST /clients creates a client', function () {
    $this->post('/clients', [
        'name' => 'New Co', 'short_code' => 'NC', 'country' => 'CH',
    ])->assertRedirect('/clients');
    expect(Client::where('name', 'New Co')->exists())->toBeTrue();
});

test('POST /clients rejects duplicate short_code', function () {
    Client::factory()->create(['short_code' => 'DUP']);
    $this->post('/clients', ['name' => 'X', 'short_code' => 'DUP', 'country' => 'CH'])
        ->assertSessionHasErrors('short_code');
});

test('GET /clients/{id}/edit renders Clients/Edit', function () {
    $c = Client::factory()->create();
    $this->get("/clients/{$c->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Edit')
            ->where('client.id', $c->id)
        );
});

test('PATCH /clients/{id} updates the client', function () {
    $c = Client::factory()->create(['name' => 'Old']);
    $this->patch("/clients/{$c->id}", ['name' => 'Renamed'])->assertRedirect();
    expect($c->fresh()->name)->toBe('Renamed');
});

test('DELETE /clients/{id} archives instead of deleting', function () {
    $c = Client::factory()->create();
    $this->delete("/clients/{$c->id}")->assertRedirect();
    expect($c->fresh()->archived_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=ClientControllerTest
```
Expected: 7 fail (no controller).

- [ ] **Step 3: `ClientProjections`**

`app/Support/ClientProjections.php`:
```php
<?php

namespace App\Support;

use App\Models\Client;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClientProjections
{
    public static function index(): Collection
    {
        $clients = Client::query()
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        $yearStart = Carbon::now()->startOfYear();

        $hoursYtd = TimeEntry::query()
            ->where('started_at', '>=', $yearStart)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->selectRaw('
                projects.client_id,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS secs
            ')
            ->groupBy('projects.client_id')
            ->pluck('secs', 'projects.client_id');

        return $clients->map(fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'short_code' => $c->short_code,
            'contact_name' => $c->contact_name,
            'email' => $c->email,
            'default_rate' => $c->default_rate_rappen ? (int) round($c->default_rate_rappen / 100) : null,
            'projects_count' => (int) $c->projects_count,
            'hours_ytd' => round(((int) ($hoursYtd[$c->id] ?? 0)) / 3600, 1),
            'outstanding' => 0,                             // Phase 2b
            'archived' => $c->archived_at !== null,
        ]);
    }
}
```

- [ ] **Step 4: `StoreClientRequest` + `UpdateClientRequest`**

`app/Http/Requests/StoreClientRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'short_code' => 'required|string|max:4|unique:clients,short_code',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'required|string|size:2',
            'vat_id' => 'nullable|string|max:64',
            'default_rate_rappen' => 'nullable|integer|min:0',
        ];
    }
}
```

`app/Http/Requests/UpdateClientRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('client')->id;
        return [
            'name' => 'sometimes|string|max:255',
            'short_code' => ['sometimes', 'string', 'max:4', Rule::unique('clients', 'short_code')->ignore($id)],
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'sometimes|string|size:2',
            'vat_id' => 'nullable|string|max:64',
            'default_rate_rappen' => 'nullable|integer|min:0',
        ];
    }
}
```

- [ ] **Step 5: `ClientController`**

`app/Http/Controllers/ClientController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Support\ClientProjections;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Clients/Index', [
            'clients' => ClientProjections::index()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        Client::create($request->validated());
        return redirect('/clients');
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('Clients/Edit', [
            'client' => $client->only([
                'id', 'name', 'short_code', 'contact_name', 'email',
                'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                'vat_id', 'default_rate_rappen',
            ]),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());
        return back();
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Soft-archive instead of delete (preserves FK integrity for projects/invoices).
        $client->update(['archived_at' => now()]);
        return redirect('/clients');
    }
}
```

- [ ] **Step 6: Wire routes**

Replace the placeholder `Route::get('/clients', ...)` in `routes/web.php` with a resource route. Inside the `auth` group:
```php
use App\Http\Controllers\ClientController;

Route::resource('clients', ClientController::class)->except(['show']);
```

- [ ] **Step 7: Run — expect PASS**

```
host$ ddev artisan test --filter=ClientControllerTest
```
Expected: 7/7 pass.

- [ ] **Step 8: `Clients/Index.vue`**

`resources/js/Pages/Clients/Index.vue` — port `design/ernte/project/views.jsx` lines 509-581 (`function ClientsView`):

```vue
<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sparkline from '@/Components/Sparkline.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, required: true },
});

const filter = ref('all');
const search = ref('');

const filtered = computed(() => {
  let list = props.clients;
  if (filter.value === 'with_balance') list = list.filter((c) => c.outstanding > 0);
  if (filter.value === 'archived')     list = list.filter((c) => c.archived);
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter((c) =>
      c.name.toLowerCase().includes(q) ||
      (c.contact_name ?? '').toLowerCase().includes(q));
  }
  return list;
});

const totalOutstanding = computed(() => props.clients.reduce((a, c) => a + c.outstanding, 0));
const glyphFor = (i) => ['alt-0', 'alt-1', 'alt-2', 'alt-3', 'alt-4'][i % 5];
function fmtMoneyShort(v) { return '€' + Math.round(v).toLocaleString('en-US'); }
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / clients</div>
      <h1 class="page-title">
        Clients
        <span class="meta">{{ clients.length }} accounts<span v-if="totalOutstanding" class="ascii-dot">·</span><span v-if="totalOutstanding">{{ fmtMoneyShort(totalOutstanding) }} outstanding</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/clients/create" class="btn primary">+ New client</Link>
    </div>
  </div>

  <div class="filter-row">
    <button class="chip" :aria-pressed="filter === 'all'" @click="filter = 'all'">
      All <span class="dim" style="margin-left: 4px">{{ clients.length }}</span>
    </button>
    <button class="chip" :aria-pressed="filter === 'with_balance'" @click="filter = 'with_balance'">
      With balance <span class="dim" style="margin-left: 4px">{{ clients.filter((c) => c.outstanding > 0).length }}</span>
    </button>
    <button class="chip" :aria-pressed="filter === 'archived'" @click="filter = 'archived'">
      Archived <span class="dim" style="margin-left: 4px">{{ clients.filter((c) => c.archived).length }}</span>
    </button>
    <div class="search">
      <span style="color: var(--ink-4)">⌕</span>
      <input v-model="search" placeholder="filter…" />
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l" style="width: 280px">Client</th>
          <th>Contact</th>
          <th class="num" style="width: 90px">Default rate</th>
          <th class="num" style="width: 90px">Projects</th>
          <th class="num" style="width: 110px">Hours YTD</th>
          <th class="num" style="width: 130px">Outstanding</th>
          <th class="pad-r" style="width: 150px">Activity</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(c, i) in filtered" :key="c.id">
          <td class="pad-l strong">
            <Link :href="`/clients/${c.id}/edit`" class="proj-cell" style="color: inherit">
              <span class="proj-glyph" :class="glyphFor(i)">{{ c.short_code[0] }}</span>
              <span>{{ c.name }}</span>
            </Link>
          </td>
          <td>
            <template v-if="c.contact_name">
              {{ c.contact_name }} <span v-if="c.email" class="dim" style="margin-left: 4px">{{ c.email }}</span>
            </template>
            <span v-else class="dim">—</span>
          </td>
          <td class="num">
            <template v-if="c.default_rate">€{{ c.default_rate }}/h</template>
            <span v-else class="dim">—</span>
          </td>
          <td class="num">{{ c.projects_count }}</td>
          <td class="num">{{ c.hours_ytd }}h</td>
          <td class="num strong" :style="{ color: c.outstanding > 0 ? 'var(--rust)' : 'var(--ink-3)' }">
            <template v-if="c.outstanding > 0">{{ fmtMoneyShort(c.outstanding) }}</template>
            <span v-else class="dim">—</span>
          </td>
          <td class="pad-r">
            <Sparkline :data="[2,3,1,4,5,2,3,4,5,6,5,4,3,5]" :w="110" :h="20" color="var(--ink-3)" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

- [ ] **Step 9: `Clients/Create.vue`**

`resources/js/Pages/Clients/Create.vue`:
```vue
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const form = useForm({
  name: '', short_code: '', contact_name: '', email: '',
  address_line_1: '', address_line_2: '', postal_code: '', city: '',
  country: 'CH', vat_id: '', default_rate_rappen: null,
});

function submit() {
  form.transform((d) => ({ ...d, default_rate_rappen: d.default_rate_rappen ? Number(d.default_rate_rappen) : null }))
      .post('/clients');
}
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">~ / clients</Link>
        <span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New client</h1>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Short code (≤4 chars)</span>
      <input v-model="form.short_code" required maxlength="4" style="text-transform: uppercase" />
      <small v-if="form.errors.short_code" class="err">{{ form.errors.short_code }}</small>
    </label>
    <label class="field">
      <span>Country</span>
      <input v-model="form.country" required maxlength="2" style="text-transform: uppercase" />
    </label>
    <label class="field">
      <span>Contact name</span>
      <input v-model="form.contact_name" />
    </label>
    <label class="field">
      <span>Email</span>
      <input type="email" v-model="form.email" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 1</span>
      <input v-model="form.address_line_1" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 2</span>
      <input v-model="form.address_line_2" />
    </label>
    <label class="field">
      <span>Postal code</span>
      <input v-model="form.postal_code" />
    </label>
    <label class="field">
      <span>City</span>
      <input v-model="form.city" />
    </label>
    <label class="field">
      <span>VAT ID</span>
      <input v-model="form.vat_id" />
    </label>
    <label class="field">
      <span>Default rate (rappen)</span>
      <input type="number" v-model="form.default_rate_rappen" min="0" />
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Create</button>
      <Link href="/clients" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
```

- [ ] **Step 10: `Clients/Edit.vue`**

`resources/js/Pages/Clients/Edit.vue`:
```vue
<script setup>
import { onMounted } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { pushRecent } from '@/composables/useRecent.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, required: true },
});

const form = useForm({ ...props.client });

onMounted(() => {
  pushRecent({ url: `/clients/${props.client.id}/edit`, label: props.client.name });
});

function submit() {
  form.transform((d) => ({ ...d, default_rate_rappen: d.default_rate_rappen ? Number(d.default_rate_rappen) : null }))
      .patch(`/clients/${props.client.id}`);
}

function archive() {
  if (!confirm(`Archive ${props.client.name}? Projects remain but the client is hidden from active lists.`)) return;
  router.delete(`/clients/${props.client.id}`);
}
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/clients">~ / clients</Link>
        <span class="ascii-dot">/</span><span>{{ client.short_code }}</span>
      </div>
      <h1 class="page-title">{{ client.name }}</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button class="btn ghost" @click="archive">Archive</button>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Short code</span>
      <input v-model="form.short_code" maxlength="4" style="text-transform: uppercase" />
      <small v-if="form.errors.short_code" class="err">{{ form.errors.short_code }}</small>
    </label>
    <label class="field">
      <span>Country</span>
      <input v-model="form.country" maxlength="2" style="text-transform: uppercase" />
    </label>
    <label class="field">
      <span>Contact name</span>
      <input v-model="form.contact_name" />
    </label>
    <label class="field">
      <span>Email</span>
      <input type="email" v-model="form.email" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 1</span>
      <input v-model="form.address_line_1" />
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Address line 2</span>
      <input v-model="form.address_line_2" />
    </label>
    <label class="field">
      <span>Postal code</span>
      <input v-model="form.postal_code" />
    </label>
    <label class="field">
      <span>City</span>
      <input v-model="form.city" />
    </label>
    <label class="field">
      <span>VAT ID</span>
      <input v-model="form.vat_id" />
    </label>
    <label class="field">
      <span>Default rate (rappen)</span>
      <input type="number" v-model="form.default_rate_rappen" min="0" />
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Save</button>
      <Link href="/clients" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
```

- [ ] **Step 11: Browser smoke**

Visit https://ernte.ddev.site/clients → see all demo clients in the table. Click "+ New client" → fill the form → save → land back on `/clients` with the new row. Click an existing client → land on edit → tweak the name → save → success.

- [ ] **Step 12: Full suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 13: Commit**

```
host$ git add app/Http/Controllers/ClientController.php \
              app/Http/Requests/StoreClientRequest.php app/Http/Requests/UpdateClientRequest.php \
              app/Support/ClientProjections.php \
              routes/web.php \
              resources/js/Pages/Clients/Index.vue \
              resources/js/Pages/Clients/Create.vue \
              resources/js/Pages/Clients/Edit.vue \
              tests/Feature/Http/ClientControllerTest.php
host$ git commit -m "feat(clients): resource controller + Index/Create/Edit pages"
```

---

## Task 11: Statusbar — real backup time + db size

The statusbar currently shows only `connected`, port, version, db driver/version, and uptime. Add **backup last-seen** (from the `backups` table) and **db size**.

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/Components/Statusbar.vue`
- Modify: `tests/Feature/InertiaPropsTest.php`

- [ ] **Step 1: Extend the failing test**

Append to `tests/Feature/InertiaPropsTest.php`:
```php
test('system shared prop includes db_size_bytes and backup_last_at', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('system.db_size_bytes')
            ->has('system.backup_last_at')   // nullable, but key present
        );
});

test('backup_last_at reflects the latest backup row', function () {
    $user = User::factory()->create();
    \App\Models\Backup::create([
        'path' => '/tmp/x.tgz',
        'size_bytes' => 1024,
        'created_at' => now()->subHours(3),
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('system.backup_last_at', fn ($v) => $v !== null)
        );
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InertiaProps
```
Expected: 2 new fail.

- [ ] **Step 3: Extend `HandleInertiaRequests`**

In `app/Http/Middleware/HandleInertiaRequests.php`, replace the `'system'` closure with:
```php
'system' => fn () => [
    'db_driver'      => DB::connection()->getDriverName(),
    'db_version'     => $this->dbVersion(),
    'db_size_bytes'  => $this->dbSizeBytes(),
    'backup_last_at' => \App\Models\Backup::latest()?->created_at?->toIso8601String(),
    'uptime_seconds' => $this->uptimeSeconds(),
],
```

And add the new helper alongside `dbVersion()`:
```php
private function dbSizeBytes(): int
{
    try {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ");
        return (int) ($row->bytes ?? 0);
    } catch (\Throwable) {
        return 0;
    }
}
```

Cache it for 60s so we don't hammer information_schema on every request:
```php
'db_size_bytes' => \Illuminate\Support\Facades\Cache::remember(
    'system:db_size_bytes', now()->addSeconds(60), fn () => $this->dbSizeBytes()
),
```
(Replace the simple call above with this cached form.)

- [ ] **Step 4: Render in `Statusbar.vue`**

Replace `resources/js/Components/Statusbar.vue`:
```vue
<script setup>
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

const dbSize = computed(() => {
  const b = sys.value?.db_size_bytes ?? 0;
  if (b < 1024) return `${b}B`;
  if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)}KB`;
  if (b < 1024 ** 3) return `${(b / 1024 / 1024).toFixed(0)}MB`;
  return `${(b / 1024 / 1024 / 1024).toFixed(1)}GB`;
});

const backupAgo = computed(() => {
  const iso = sys.value?.backup_last_at;
  if (!iso) return 'never';
  const sec = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (sec < 60)        return `${sec}s ago`;
  if (sec < 3600)      return `${Math.floor(sec / 60)}m ago`;
  if (sec < 86400)     return `${Math.floor(sec / 3600)}h ago`;
  return `${Math.floor(sec / 86400)}d ago`;
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
    <span>db <span class="muted">{{ sys?.db_driver }} {{ sys?.db_version }} · {{ dbSize }}</span></span>
    <span class="sep">│</span>
    <span>backup <span class="muted">{{ backupAgo }}</span></span>
    <span class="spacer" />
    <span class="muted">uptime {{ uptime }}</span>
  </footer>
</template>
```

- [ ] **Step 5: Run — expect PASS**

```
host$ ddev artisan test --filter=InertiaProps
```
Expected: all pass.

- [ ] **Step 6: Browser smoke**

Reload any page. Footer should now read `db mariadb 11.4 · N MB` and `backup never` (or a time if you've inserted a backup row).

- [ ] **Step 7: Full suite**

```
host$ ddev artisan test
```
Expected: green.

- [ ] **Step 8: Commit**

```
host$ git add app/Http/Middleware/HandleInertiaRequests.php \
              resources/js/Components/Statusbar.vue \
              tests/Feature/InertiaPropsTest.php
host$ git commit -m "feat(statusbar): db size + last-backup readout"
```

---

## Task 12: Phase 2a end-to-end verification

No new code — just a thorough check before opening the PR. Catches regressions, missing wiring, and any cosmetic gaps before Phase 2b builds on top.

- [ ] **Step 1: Fresh seed + green tests**

```
host$ ddev artisan migrate:fresh --seed
host$ ddev artisan db:seed --class=DemoFixturesSeeder
host$ ddev artisan test
```
Expected: clean DB, demo fixtures loaded, all tests green.

- [ ] **Step 2: Production-style build**

```
host$ ddev npm ci
host$ ddev npm run build
```
Expected: no warnings, no missing imports, no Vue compiler errors.

- [ ] **Step 3: Click-through script**

In the browser (https://ernte.ddev.site), walk this script and confirm each step works. **If anything is broken, file it as a task in this checklist before opening the PR.**

1. `/projects` — see the table, filter chips work, search debounces, sparklines render.
2. Click a project → land on `/projects/{code}`, burn-down + tasks + heatmap visible, recent entries listed.
3. Toggle a task done → check stays after reload.
4. `/timer` — "+ Manual entry" reveals form; submit a 30-min entry → appears in today's list; today summary updates.
5. Click a "Quick start" project → timer chip in topbar starts ticking; hero shows clock; "stop" returns to idle.
6. Open another tab on `/projects` while timer runs — chip is visible there too.
7. `/clients` — table renders, "+ New client" form saves, edit form updates, archive button hides client from `All`.
8. Sidebar pinned section reflects the most recently active projects after creating a timer entry.
9. Footer shows `db mariadb 11.4 · NMB` and `backup never` (or a time).

- [ ] **Step 4: Lint pass**

```
host$ ddev exec php artisan route:list --columns=method,uri,name
```
Spot-check: every controller method in this plan is reachable; no name collisions.

- [ ] **Step 5: Tag the phase**

```
host$ git tag phase-2a
host$ git log --oneline phase-1..phase-2a
```
Expected: roughly 11 commits (one per task).

- [ ] **Step 6: Optional — open the PR**

```
host$ gh pr create --base main --head phase-2a-views \
  --title "Phase 2a — Projects + Timer + Clients views" \
  --body "$(cat <<'EOF'
## Summary
- Wires the time-tracking half of the app end-to-end: Projects, Tasks, Timer, Manual entries, Clients
- Chrome wired to live data: running-timer chip, sidebar pinned/recent/week-bars, statusbar db-size + backup readout
- Five custom SVG chart components (Sparkline, BudgetBar, WeekBars, BurnDown, Heatmap), no chart library
- One Phase-1 carryover landed: `Client::projects()` relationship
- Phase 2b (Invoices + PDF + email + scheduler + ⌘K + keyboard shortcuts + Settings/Profile + Reports + backup command) ships next

## Test plan
- [x] All Pest tests green (`ddev artisan test`)
- [x] Fresh `migrate:fresh --seed --class=DemoFixturesSeeder` produces a usable demo
- [x] Click-through script from `docs/superpowers/plans/2026-05-27-ernte-phase-2a-views.md` Task 12 Step 3 passes
EOF
)"
```

---

## Phase 2a is done when:

- All tests pass (Phase 1 count + ~30 new HTTP + shared-props tests).
- Tag `phase-2a` exists.
- The click-through script in Task 12 Step 3 passes without console errors.
- The next plan to write is **Phase 2b** — Invoices CRUD/Create/Show, PDF rendering via Browsershot, Swiss QR-bill, SMTP send, reminder scheduler, Settings/Profile, Reports placeholder, ⌘K palette, keyboard shortcuts, backup command. The remaining Phase-1 carryover items (`Invoice::void()` semantics, `Client::invoices()` relationship, the `vat_exempt` line editor, `InvoiceFactory` number format note) all land there.

