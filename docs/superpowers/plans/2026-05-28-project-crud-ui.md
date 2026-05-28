# Project Create / Edit / Archive UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make project create, edit, and archive/unarchive reachable from the UI by mirroring the existing Clients pattern.

**Architecture:** Add `create()`, `edit()`, and `unarchive()` to `ProjectController` (the `store`/`update`/`archive` backends already exist), wire three new routes, and build `Projects/Create.vue` + `Projects/Edit.vue` plus the buttons to reach them (enable the Index "+ New project" button and add an "Edit" button to Show). Money is entered in CHF and transformed to rappen on submit, matching how `Invoices/Create` and the project payload already convert.

**Tech Stack:** Laravel 12, Inertia v2, Vue 3, MariaDB (DDEV), Pest. Tests: `ddev artisan test`. Build: `ddev npm run build`.

**Spec:** `docs/superpowers/specs/2026-05-28-project-crud-ui-design.md`

---

## File Structure

- **Modify** `routes/web.php` — add `projects.create` (GET, declared BEFORE the `show` route), `projects.edit` (GET), `projects.unarchive` (POST).
- **Modify** `app/Http/Controllers/ProjectController.php` — add `create()`, `edit()`, `unarchive()`, a private `activeClients()` helper; change `update()` to redirect to the Show page.
- **Modify** `resources/js/Pages/Projects/Index.vue` — enable the "+ New project" button as a link.
- **Create** `resources/js/Pages/Projects/Create.vue` — new-project form.
- **Create** `resources/js/Pages/Projects/Edit.vue` — edit form + archive/unarchive.
- **Modify** `resources/js/Pages/Projects/Show.vue` — add an "Edit" header button.
- **Tests** in `tests/Feature/Http/ProjectControllerTest.php`.

The existing `StoreProjectRequest` / `UpdateProjectRequest` already validate every field the forms post — no changes needed there.

---

## Task 1: Backend — `create()` action + `GET /projects/create` route

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ProjectController.php`
- Test: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/ProjectControllerTest.php` (the file already imports `Client`, `Project`, `User`, and `AssertableInertia as Assert`):

```php
test('GET /projects/create renders Projects/Create with the active clients list', function () {
    $user = User::factory()->create();
    $active = Client::factory()->create(['name' => 'Atlas Robotics']);
    Client::factory()->create(['name' => 'Archived Co', 'archived_at' => now()]);

    $this->actingAs($user)->get('/projects/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Create')
            ->has('clients', 1, fn (Assert $c) => $c
                ->where('id', $active->id)
                ->where('name', 'Atlas Robotics')
            )
        );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="renders Projects/Create"`
Expected: FAIL — no `/projects/create` route (the `{project:code}` route tries to bind "create" and 404s, or the component is wrong).

- [ ] **Step 3: Add the route (BEFORE the show route)**

In `routes/web.php`, in the projects block, add the create route immediately after the `projects.index` line and BEFORE `projects.show`:

```php
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
```

Order matters: `/projects/create` must be declared before `GET /projects/{project:code}`, otherwise "create" is bound as a project code and 404s.

- [ ] **Step 4: Add the controller code**

In `app/Http/Controllers/ProjectController.php`, add the `Client` import at the top with the other model imports:

```php
use App\Models\Client;
```

Add a private helper and the `create()` action (place `create()` near the top, after `index()`):

```php
    private function activeClients(): \Illuminate\Support\Collection
    {
        return Client::active()->orderBy('name')->get(['id', 'name']);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Create', [
            'clients' => $this->activeClients(),
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter="renders Projects/Create"`
Expected: PASS. (The `Projects/Create` Vue page does not exist yet, but Inertia testing asserts the component NAME without rendering it, so this passes.)

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProjectController.php tests/Feature/Http/ProjectControllerTest.php
git commit -m "feat(projects): add create() action and /projects/create route"
```

---

## Task 2: Backend — `edit()` action + `GET /projects/{code}/edit` route

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ProjectController.php`
- Test: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/ProjectControllerTest.php`:

```php
test('GET /projects/{code}/edit renders Projects/Edit with project money in CHF and clients', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id, 'code' => 'ATLS-FLT',
        'rate_rappen' => 14500, 'budget_amount_rappen' => 31900_00, 'budget_hours' => 220,
    ]);

    $this->actingAs($user)->get("/projects/{$project->code}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Edit')
            ->where('project.code', 'ATLS-FLT')
            ->where('project.rate', 145)            // CHF, not rappen
            ->where('project.budget_amount', 31900) // CHF, not rappen
            ->where('project.budget_hours', 220)
            ->where('project.client_id', $client->id)
            ->has('clients')
        );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="renders Projects/Edit"`
Expected: FAIL — no `/projects/{code}/edit` route.

- [ ] **Step 3: Add the route**

In `routes/web.php`, add after the `projects.show` line:

```php
    Route::get('/projects/{project:code}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/ProjectController.php`, add the `edit()` action after `show()`:

```php
    public function edit(Project $project): Response
    {
        return Inertia::render('Projects/Edit', [
            'project' => [
                'id' => $project->id,
                'client_id' => $project->client_id,
                'name' => $project->name,
                'code' => $project->code,
                'glyph' => $project->glyph,
                'description' => $project->description,
                'billable' => (bool) $project->billable,
                'budget_hours' => (int) $project->budget_hours,
                'budget_amount' => (int) round($project->budget_amount_rappen / 100),
                'rate' => (int) round($project->rate_rappen / 100),
                'started_on' => $project->started_on?->toDateString(),
                'deadline_on' => $project->deadline_on?->toDateString(),
                'status' => $project->status,
            ],
            'clients' => $this->activeClients(),
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter="renders Projects/Edit"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProjectController.php tests/Feature/Http/ProjectControllerTest.php
git commit -m "feat(projects): add edit() action and /projects/{code}/edit route"
```

---

## Task 3: Backend — `update()` redirects to the Show page

**Files:**
- Modify: `app/Http/Controllers/ProjectController.php` (`update()`)
- Test: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/ProjectControllerTest.php`:

```php
test('PATCH /projects/{id} with a changed code redirects to the new show URL and persists', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['code' => 'OLD-1']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['code' => 'NEW-1'])
        ->assertRedirect('/projects/NEW-1');

    expect($project->fresh()->code)->toBe('NEW-1');
});

test('PATCH /projects/{id} saving the same code is allowed (unique ignores self) and redirects to show', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['code' => 'KEEP-1', 'name' => 'Old']);

    $this->actingAs($user)->patch("/projects/{$project->id}", ['code' => 'KEEP-1', 'name' => 'New'])
        ->assertRedirect('/projects/KEEP-1');

    expect($project->fresh()->name)->toBe('New');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev artisan test --filter="redirects to the new show URL|saving the same code"`
Expected: FAIL — `update()` currently returns `back()`, so the redirect target is the referer (empty in tests), not `/projects/{code}`.

- [ ] **Step 3: Update the `update()` redirect**

In `app/Http/Controllers/ProjectController.php`, change the `update()` method body:

```php
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());
        return redirect("/projects/{$project->code}");
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `ddev artisan test --filter="redirects to the new show URL|saving the same code"`
Expected: PASS.

- [ ] **Step 5: Confirm the existing update test still passes**

Run: `ddev artisan test --filter="PATCH /projects/{p} updates fields"`
Expected: PASS — it only asserts `assertRedirect()` (any redirect), which still holds.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ProjectController.php tests/Feature/Http/ProjectControllerTest.php
git commit -m "feat(projects): update() redirects to the project show page"
```

---

## Task 4: Backend — `unarchive()` action + route

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ProjectController.php`
- Test: `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/ProjectControllerTest.php`:

```php
test('POST /projects/{code}/unarchive flips an archived project back to active', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['status' => 'archived']);

    $this->actingAs($user)->post("/projects/{$project->code}/unarchive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe('active');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="unarchive flips"`
Expected: FAIL — no `/projects/{code}/unarchive` route.

- [ ] **Step 3: Add the route**

In `routes/web.php`, add immediately after the `projects.archive` line:

```php
    Route::post('/projects/{project:code}/unarchive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/ProjectController.php`, add after `archive()`:

```php
    public function unarchive(Project $project): RedirectResponse
    {
        $project->update(['status' => 'active']);
        return back();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter="unarchive flips"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProjectController.php tests/Feature/Http/ProjectControllerTest.php
git commit -m "feat(projects): add unarchive() action and route"
```

---

## Task 5: Frontend — enable the "+ New project" button on Index

**Files:**
- Modify: `resources/js/Pages/Projects/Index.vue`

No automated component test exists; verify with a build.

- [ ] **Step 1: Confirm `Link` is imported**

Open `resources/js/Pages/Projects/Index.vue`. Check the `@inertiajs/vue3` import line includes `Link`. If it does not, add it (e.g. `import { Head, Link, router } from '@inertiajs/vue3';` — keep whatever else is already imported).

- [ ] **Step 2: Replace the disabled button with a link**

In the header button group, replace this line:

```html
      <button class="btn primary" disabled title="Phase 2b">+ New project</button>
```

with:

```html
      <Link href="/projects/create" class="btn primary">+ New project</Link>
```

(Leave the disabled "Import" button as-is — it's out of scope.)

- [ ] **Step 3: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Projects/Index.vue
git commit -m "feat(projects): enable + New project button on the index"
```

---

## Task 6: Frontend — `Projects/Create.vue`

**Files:**
- Create: `resources/js/Pages/Projects/Create.vue`

- [ ] **Step 1: Create the page**

Create `resources/js/Pages/Projects/Create.vue` with exactly:

```vue
<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, required: true },
});

const form = useForm({
  client_id: props.clients[0]?.id ?? null,
  name: '', code: '', glyph: 'alt-0', description: '',
  billable: true, budget_hours: null, budget_amount: null, rate: null,
  started_on: '', deadline_on: '',
});

function submit() {
  form.transform((d) => ({
    client_id: d.client_id,
    name: d.name,
    code: d.code,
    glyph: d.glyph,
    description: d.description,
    billable: d.billable,
    budget_hours: d.budget_hours ? Number(d.budget_hours) : 0,
    budget_amount_rappen: Math.round((Number(d.budget_amount) || 0) * 100),
    rate_rappen: Math.round((Number(d.rate) || 0) * 100),
    started_on: d.started_on || null,
    deadline_on: d.deadline_on || null,
  })).post('/projects');
}
</script>

<template>
  <Head title="New project" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">~ / projects</Link>
        <span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New project</h1>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Client</span>
      <select v-model="form.client_id" required>
        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <small v-if="form.errors.client_id" class="err">{{ form.errors.client_id }}</small>
    </label>
    <label class="field">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Code (≤32 chars)</span>
      <input v-model="form.code" required maxlength="32" style="text-transform: uppercase" />
      <small v-if="form.errors.code" class="err">{{ form.errors.code }}</small>
    </label>
    <label class="field">
      <span>Glyph</span>
      <select v-model="form.glyph" required>
        <option value="alt-0">alt-0</option>
        <option value="alt-1">alt-1</option>
        <option value="alt-2">alt-2</option>
        <option value="alt-3">alt-3</option>
        <option value="alt-4">alt-4</option>
      </select>
    </label>
    <label class="field" style="flex-direction: row; align-items: center; gap: 8px">
      <input type="checkbox" v-model="form.billable" />
      <span>Billable</span>
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Description</span>
      <textarea v-model="form.description" rows="2" />
    </label>
    <label class="field">
      <span>Budget hours</span>
      <input type="number" v-model="form.budget_hours" min="0" required />
      <small v-if="form.errors.budget_hours" class="err">{{ form.errors.budget_hours }}</small>
    </label>
    <label class="field">
      <span>Budget amount (CHF)</span>
      <input type="number" v-model="form.budget_amount" min="0" required />
      <small v-if="form.errors.budget_amount_rappen" class="err">{{ form.errors.budget_amount_rappen }}</small>
    </label>
    <label class="field">
      <span>Rate (CHF/h)</span>
      <input type="number" v-model="form.rate" min="0" required />
      <small v-if="form.errors.rate_rappen" class="err">{{ form.errors.rate_rappen }}</small>
    </label>
    <label class="field">
      <span>Started on</span>
      <input type="date" v-model="form.started_on" />
    </label>
    <label class="field">
      <span>Deadline on</span>
      <input type="date" v-model="form.deadline_on" />
      <small v-if="form.errors.deadline_on" class="err">{{ form.errors.deadline_on }}</small>
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Create</button>
      <Link href="/projects" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input, .field select, .field textarea {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
```

- [ ] **Step 2: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 3: Manual browser check**

Click "+ New project" on the projects index. Fill the form (pick a client, name, code, glyph, budget hours, budget amount, rate), submit. Confirm it lands on the new project's Show page and the project appears in the projects list. Submit with a blank name/code and confirm field error messages appear.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Projects/Create.vue
git commit -m "feat(projects): add Create page (new-project form)"
```

---

## Task 7: Frontend — `Projects/Edit.vue` (with archive/unarchive)

**Files:**
- Create: `resources/js/Pages/Projects/Edit.vue`

- [ ] **Step 1: Create the page**

Create `resources/js/Pages/Projects/Edit.vue` with exactly:

```vue
<script setup>
import { onMounted } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { pushRecent } from '@/composables/useRecent.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
  project: { type: Object, required: true },
  clients: { type: Array, required: true },
});

const form = useForm({
  client_id: props.project.client_id,
  name: props.project.name,
  code: props.project.code,
  glyph: props.project.glyph,
  description: props.project.description ?? '',
  billable: props.project.billable,
  budget_hours: props.project.budget_hours,
  budget_amount: props.project.budget_amount,
  rate: props.project.rate,
  started_on: props.project.started_on ?? '',
  deadline_on: props.project.deadline_on ?? '',
});

onMounted(() => {
  pushRecent({ url: `/projects/${props.project.code}`, label: props.project.name });
});

function submit() {
  form.transform((d) => ({
    client_id: d.client_id,
    name: d.name,
    code: d.code,
    glyph: d.glyph,
    description: d.description,
    billable: d.billable,
    budget_hours: d.budget_hours ? Number(d.budget_hours) : 0,
    budget_amount_rappen: Math.round((Number(d.budget_amount) || 0) * 100),
    rate_rappen: Math.round((Number(d.rate) || 0) * 100),
    started_on: d.started_on || null,
    deadline_on: d.deadline_on || null,
  })).patch(`/projects/${props.project.id}`);
}

function archive() {
  if (!confirm(`Archive ${props.project.name}? It will be hidden from active lists.`)) return;
  router.post(`/projects/${props.project.code}/archive`);
}

function unarchive() {
  router.post(`/projects/${props.project.code}/unarchive`);
}
</script>

<template>
  <Head :title="`Edit ${project.name}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/projects">~ / projects</Link>
        <span class="ascii-dot">/</span>
        <Link :href="`/projects/${project.code}`">{{ project.code }}</Link>
        <span class="ascii-dot">/</span><span>edit</span>
      </div>
      <h1 class="page-title">{{ project.name }}</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <button v-if="project.status === 'active'" class="btn ghost" @click="archive">Archive</button>
      <button v-else class="btn ghost" @click="unarchive">Unarchive</button>
    </div>
  </div>

  <form @submit.prevent="submit" style="max-width: 720px; padding: 0 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px">
    <label class="field" style="grid-column: span 2">
      <span>Client</span>
      <select v-model="form.client_id" required>
        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <small v-if="form.errors.client_id" class="err">{{ form.errors.client_id }}</small>
    </label>
    <label class="field">
      <span>Name</span>
      <input v-model="form.name" required />
      <small v-if="form.errors.name" class="err">{{ form.errors.name }}</small>
    </label>
    <label class="field">
      <span>Code (≤32 chars)</span>
      <input v-model="form.code" required maxlength="32" style="text-transform: uppercase" />
      <small v-if="form.errors.code" class="err">{{ form.errors.code }}</small>
    </label>
    <label class="field">
      <span>Glyph</span>
      <select v-model="form.glyph" required>
        <option value="alt-0">alt-0</option>
        <option value="alt-1">alt-1</option>
        <option value="alt-2">alt-2</option>
        <option value="alt-3">alt-3</option>
        <option value="alt-4">alt-4</option>
      </select>
    </label>
    <label class="field" style="flex-direction: row; align-items: center; gap: 8px">
      <input type="checkbox" v-model="form.billable" />
      <span>Billable</span>
    </label>
    <label class="field" style="grid-column: span 2">
      <span>Description</span>
      <textarea v-model="form.description" rows="2" />
    </label>
    <label class="field">
      <span>Budget hours</span>
      <input type="number" v-model="form.budget_hours" min="0" required />
      <small v-if="form.errors.budget_hours" class="err">{{ form.errors.budget_hours }}</small>
    </label>
    <label class="field">
      <span>Budget amount (CHF)</span>
      <input type="number" v-model="form.budget_amount" min="0" required />
      <small v-if="form.errors.budget_amount_rappen" class="err">{{ form.errors.budget_amount_rappen }}</small>
    </label>
    <label class="field">
      <span>Rate (CHF/h)</span>
      <input type="number" v-model="form.rate" min="0" required />
      <small v-if="form.errors.rate_rappen" class="err">{{ form.errors.rate_rappen }}</small>
    </label>
    <label class="field">
      <span>Started on</span>
      <input type="date" v-model="form.started_on" />
    </label>
    <label class="field">
      <span>Deadline on</span>
      <input type="date" v-model="form.deadline_on" />
      <small v-if="form.errors.deadline_on" class="err">{{ form.errors.deadline_on }}</small>
    </label>
    <div style="grid-column: span 2; display: flex; gap: 8px; margin-top: 12px">
      <button type="submit" class="btn primary" :disabled="form.processing">Save</button>
      <Link :href="`/projects/${project.code}`" class="btn ghost">Cancel</Link>
    </div>
  </form>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field input, .field select, .field textarea {
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; color: var(--ink);
}
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--accent); }
.err { color: var(--red); font-size: var(--fs-xs); }
</style>
```

- [ ] **Step 2: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 3: Manual browser check**

From a project's Edit page (reachable after Task 8): change the name and rate, Save, confirm you land on the Show page with the new values. Click Archive, confirm the dialog, confirm the project leaves the active list and the button now reads "Unarchive". Use the `?filter=archived` index, open the archived project's edit page, click Unarchive, confirm it returns to active.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Projects/Edit.vue
git commit -m "feat(projects): add Edit page with archive/unarchive"
```

---

## Task 8: Frontend — "Edit" button on the Show header

**Files:**
- Modify: `resources/js/Pages/Projects/Show.vue`

- [ ] **Step 1: Add the Edit link**

In `resources/js/Pages/Projects/Show.vue`, in the header button group (the `<div style="display: flex; gap: 8px">`), add an Edit link immediately before the `+ Invoice` link:

```html
      <Link :href="`/projects/${project.code}/edit`" class="btn">Edit</Link>
```

(`Link` is already imported in this file.)

- [ ] **Step 2: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 3: Manual browser check**

Open a project's Show page, click "Edit", confirm it loads the Edit page prefilled with that project's data.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Projects/Show.vue
git commit -m "feat(projects): add Edit button to the project show header"
```

---

## Task 9: Full suite + build + end-to-end verification

- [ ] **Step 1: Run the full test suite**

Run: `ddev artisan test`
Expected: all tests pass (prior baseline 289; this plan adds 5 backend tests).

- [ ] **Step 2: Build assets**

Run: `ddev npm run build`
Expected: clean build.

- [ ] **Step 3: Manual end-to-end check**

- Index "+ New project" → create a project → lands on its Show page, appears in the list.
- Show "Edit" → change fields → Save → values updated on Show.
- Edit a project's code → Save → redirected to the new `/projects/{newcode}` URL.
- Edit → Archive (confirm) → leaves the active list; appears under the "Archived" filter.
- Open the archived project's Edit page → Unarchive → returns to active.

---

## Self-Review Notes

- **Spec coverage:** create() + route (Task 1), edit() + route (Task 2), update() redirect (Task 3), unarchive() + route (Task 4), Index button (Task 5), Create.vue (Task 6), Edit.vue + archive/unarchive (Task 7), Show Edit button (Task 8), verification (Task 9). All spec sections mapped.
- **Route ordering:** Task 1 explicitly places `GET /projects/create` before the `show` route (the spec's ordering constraint).
- **Money convention:** Create and Edit both post `rate_rappen`/`budget_amount_rappen` (CHF × 100); `edit()` emits `rate`/`budget_amount` in CHF — names are consistent across tasks and match `StoreProjectRequest`/`UpdateProjectRequest`.
- **Field names** posted by the forms (`client_id`, `name`, `code`, `glyph`, `description`, `billable`, `budget_hours`, `budget_amount_rappen`, `rate_rappen`, `started_on`, `deadline_on`) exactly match the validated rules. Retainer fields intentionally omitted (default off; `sometimes`/`nullable` in the request).
- **No placeholders**; every code/test step shows complete code.
