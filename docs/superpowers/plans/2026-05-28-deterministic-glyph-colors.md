# Deterministic Glyph Colors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the client/project badge color deterministic from the record's immutable `id`, computed on the frontend, and remove the manual glyph picker and the entire stored-`glyph` backend.

**Architecture:** A pure JS helper `glyphClass(id)` maps an id to one of five existing CSS color classes (`alt-0`…`alt-4`). All six `.proj-glyph` render sites call it keyed by `id`. The `projects.glyph` column, its validation, its payload fields, and the create/edit picker are all removed.

**Tech Stack:** Laravel 12, Inertia v2, Vue 3, MariaDB (DDEV), Pest. Tests: `ddev artisan test`. Build: `ddev npm run build`.

**Spec:** `docs/superpowers/specs/2026-05-28-deterministic-glyph-colors-design.md`

**Ordering note (important):** Tasks run in this order so every intermediate state stays working: (1) add the util, (2) switch render sites to it — colors are now deterministic and the payload `glyph` becomes unused by the frontend; (3) remove the backend glyph plumbing — safe because nothing reads it anymore; (4) remove the form picker — safe because step 3 removed the `required` validation rule.

---

## File Structure

- **Create** `resources/js/glyph.js` — the `glyphClass(id)` helper.
- **Modify** 6 Vue render sites — `Clients/Index.vue`, `Clients/Show.vue` (×2), `Projects/Index.vue`, `Projects/Show.vue`, `Timer/Today.vue`.
- **Modify** `Projects/Create.vue`, `Projects/Edit.vue` — remove the glyph picker.
- **Create** a migration dropping `projects.glyph`.
- **Modify** backend glyph references: `Project` model, two form requests, `ProjectController@edit`, `ProjectDetail`, `DashboardProjections`, `SidebarProps`, `ClientDetail`, `TimerToday`, `Harvest/ProjectImporter`, `DemoFixturesSeeder`, `ProjectFactory`.
- **Modify** tests: `ProjectControllerTest` (stop posting glyph), `SharedPropsTest` (comment), `Schema/ProjectTest` (new no-column assertion).

---

## Task 1: Add the `glyphClass` util

**Files:**
- Create: `resources/js/glyph.js`

This codebase has no JS test runner, so there is no automated test for this pure function; it is exercised by the render sites and verified via build + manual QA in later tasks.

- [ ] **Step 1: Create the file**

Create `resources/js/glyph.js` with exactly:

```js
// Deterministic project/client badge color from an immutable id.
// Maps to one of the five `.proj-glyph` color classes (alt-0 = base accent).
export function glyphClass(id) {
  return `alt-${Math.abs(Number(id)) % 5}`;
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/glyph.js
git commit -m "feat(glyph): add deterministic glyphClass(id) helper"
```

---

## Task 2: Switch all render sites to `glyphClass(id)`

**Files:**
- Modify: `resources/js/Pages/Clients/Index.vue`
- Modify: `resources/js/Pages/Clients/Show.vue`
- Modify: `resources/js/Pages/Projects/Index.vue`
- Modify: `resources/js/Pages/Projects/Show.vue`
- Modify: `resources/js/Pages/Timer/Today.vue`

All five files use the `@` alias (= `resources/js`), as in their existing `@/Layouts/AppLayout.vue` imports.

- [ ] **Step 1: `Clients/Index.vue`**

Add the import to `<script setup>` (next to the other `@/` imports):

```js
import { glyphClass } from '@/glyph.js';
```

Delete the position-based helper (line ~32):

```js
const glyphFor = (i) => ['alt-0', 'alt-1', 'alt-2', 'alt-3', 'alt-4'][i % 5];
```

Change the row loop so the now-unused index is dropped — replace:

```html
        <tr v-for="(c, i) in filtered" :key="c.id">
```

with:

```html
        <tr v-for="c in filtered" :key="c.id">
```

Change the badge binding — replace:

```html
              <span class="proj-glyph" :class="glyphFor(i)">{{ c.short_code[0] }}</span>
```

with:

```html
              <span class="proj-glyph" :class="glyphClass(c.id)">{{ c.short_code[0] }}</span>
```

- [ ] **Step 2: `Clients/Show.vue`**

Add the import:

```js
import { glyphClass } from '@/glyph.js';
```

Replace the hardcoded client badge (line ~47):

```html
        <span class="proj-glyph alt-0" style="width: 28px; height: 28px; font-size: 14px">{{ client.short_code[0] }}</span>
```

with:

```html
        <span class="proj-glyph" :class="glyphClass(client.id)" style="width: 28px; height: 28px; font-size: 14px">{{ client.short_code[0] }}</span>
```

Replace the project-row badge (line ~100):

```html
                  <span class="proj-glyph" :class="project.glyph">{{ project.code[0] }}</span>
```

with:

```html
                  <span class="proj-glyph" :class="glyphClass(project.id)">{{ project.code[0] }}</span>
```

- [ ] **Step 3: `Projects/Index.vue`**

Add the import:

```js
import { glyphClass } from '@/glyph.js';
```

Replace (line ~123):

```html
              <span class="proj-glyph" :class="p.glyph">{{ p.code[0] }}</span>
```

with:

```html
              <span class="proj-glyph" :class="glyphClass(p.id)">{{ p.code[0] }}</span>
```

- [ ] **Step 4: `Projects/Show.vue`**

Add the import:

```js
import { glyphClass } from '@/glyph.js';
```

Replace (line ~66):

```html
        <span class="proj-glyph" :class="project.glyph" style="width: 28px; height: 28px; font-size: 14px">{{ project.code[0] }}</span>
```

with:

```html
        <span class="proj-glyph" :class="glyphClass(project.id)" style="width: 28px; height: 28px; font-size: 14px">{{ project.code[0] }}</span>
```

- [ ] **Step 5: `Timer/Today.vue`**

Add the import:

```js
import { glyphClass } from '@/glyph.js';
```

Replace (line ~153):

```html
          <span class="proj-glyph" :class="p.glyph" style="width: 12px; height: 12px; font-size: 8px">{{ p.code[0] }}</span>
```

with:

```html
          <span class="proj-glyph" :class="glyphClass(p.id)" style="width: 12px; height: 12px; font-size: 8px">{{ p.code[0] }}</span>
```

- [ ] **Step 6: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Clients/Index.vue resources/js/Pages/Clients/Show.vue resources/js/Pages/Projects/Index.vue resources/js/Pages/Projects/Show.vue resources/js/Pages/Timer/Today.vue
git commit -m "feat(glyph): color all badges deterministically by id"
```

---

## Task 3: Remove the stored-glyph backend

**Files:**
- Create: `database/migrations/<timestamp>_drop_glyph_from_projects.php`
- Modify: `app/Models/Project.php`, `app/Http/Requests/StoreProjectRequest.php`, `app/Http/Requests/UpdateProjectRequest.php`, `app/Http/Controllers/ProjectController.php`, `app/Support/ProjectDetail.php`, `app/Support/DashboardProjections.php`, `app/Support/SidebarProps.php`, `app/Support/ClientDetail.php`, `app/Support/TimerToday.php`, `app/Services/Harvest/ProjectImporter.php`, `database/seeders/DemoFixturesSeeder.php`, `database/factories/ProjectFactory.php`
- Modify tests: `tests/Feature/Http/ProjectControllerTest.php`, `tests/Feature/SharedPropsTest.php`, `tests/Feature/Schema/ProjectTest.php`

- [ ] **Step 1: Write the failing schema test**

Append to `tests/Feature/Schema/ProjectTest.php`:

```php
test('the projects table has no glyph column', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('projects', 'glyph'))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter="has no glyph column"`
Expected: FAIL — the `glyph` column still exists.

- [ ] **Step 3: Create the migration**

Run: `ddev artisan make:migration drop_glyph_from_projects`

Replace the generated file body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('glyph');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('glyph')->default('alt-0')->after('status');
        });
    }
};
```

- [ ] **Step 4: Remove `glyph` from the model**

In `app/Models/Project.php`, change the `$fillable` line that reads:

```php
        'client_id', 'name', 'code', 'description', 'glyph', 'status', 'pinned_at',
```

to:

```php
        'client_id', 'name', 'code', 'description', 'status', 'pinned_at',
```

- [ ] **Step 5: Remove the validation rules**

In `app/Http/Requests/StoreProjectRequest.php`, delete the line:

```php
            'glyph' => 'required|in:alt-0,alt-1,alt-2,alt-3,alt-4',
```

In `app/Http/Requests/UpdateProjectRequest.php`, delete the line:

```php
            'glyph' => 'sometimes|in:alt-0,alt-1,alt-2,alt-3,alt-4',
```

- [ ] **Step 6: Remove `glyph` from `ProjectController@edit`**

In `app/Http/Controllers/ProjectController.php`, delete the line in the `edit()` payload:

```php
                'glyph' => $project->glyph,
```

- [ ] **Step 7: Remove `glyph` from `ProjectDetail`**

In `app/Support/ProjectDetail.php`, delete the line:

```php
                'glyph' => $project->glyph,
```

- [ ] **Step 8: Remove `glyph` from `DashboardProjections`**

In `app/Support/DashboardProjections.php`, delete the line:

```php
                'glyph' => $p->glyph,
```

- [ ] **Step 9: Remove `glyph` from `SidebarProps` (3 places)**

In `app/Support/SidebarProps.php`:

Change the eager-load constraint:

```php
            ->with(['project:id,name,code,glyph,rate_rappen', 'task:id,name'])
```

to:

```php
            ->with(['project:id,name,code,rate_rappen', 'task:id,name'])
```

Delete the running-entry map line:

```php
                'glyph' => $entry->project->glyph,
```

Change the pinned-projects column list:

```php
            ->get(['id', 'name', 'code', 'glyph'])
```

to:

```php
            ->get(['id', 'name', 'code'])
```

Delete the pinned-projects map line:

```php
                'glyph' => $p->glyph,
```

- [ ] **Step 10: Remove `glyph` from `ClientDetail` (3 places)**

In `app/Support/ClientDetail.php`:

Delete the project-row map line:

```php
                    'glyph' => $project->glyph,
```

Change the recent-entries eager-load:

```php
            ->with(['project:id,name,code,glyph', 'task:id,name'])
```

to:

```php
            ->with(['project:id,name,code', 'task:id,name'])
```

Delete the recent-entries project map line:

```php
                    'glyph' => $entry->project->glyph,
```

- [ ] **Step 11: Remove `glyph` from `TimerToday` (3 places)**

In `app/Support/TimerToday.php`:

Change the eager-load:

```php
            ->with(['project:id,name,code,glyph,rate_rappen', 'task:id,name'])
```

to:

```php
            ->with(['project:id,name,code,rate_rappen', 'task:id,name'])
```

Delete the entry-project map line:

```php
                    'glyph' => $e->project->glyph,
```

Change the quick-start select:

```php
            ->select('id', 'name', 'code', 'glyph')
```

to:

```php
            ->select('id', 'name', 'code')
```

- [ ] **Step 12: Remove `glyph` from the Harvest importer**

In `app/Services/Harvest/ProjectImporter.php`, delete the line:

```php
                'glyph' => '▦',
```

- [ ] **Step 13: Remove `glyph` from the factory**

In `database/factories/ProjectFactory.php`, delete the line:

```php
            'glyph' => 'alt-' . $this->faker->numberBetween(0, 4),
```

- [ ] **Step 14: Remove `glyph` from the demo seeder**

In `database/seeders/DemoFixturesSeeder.php`, remove the `'glyph' => 'alt-N',` key from each of the eight project array literals (the lines defining `ATLS-FLT`, `KS-ERP`, `ATLS-DOC`, `NL-WEB`, `KF-BRD`, `HS-IOS`, `KS-SUP`, `ERNTE`), and delete the line in the create call:

```php
                        'glyph' => $d['glyph'],
```

- [ ] **Step 15: Stop posting `glyph` in the controller tests**

In `tests/Feature/Http/ProjectControllerTest.php`:

In the "POST /projects creates a new project" test, delete the line:

```php
        'glyph' => 'alt-0',
```

In the "POST /projects rejects a duplicate code" test, change:

```php
        'glyph' => 'alt-0', 'rate_rappen' => 0,
```

to:

```php
        'rate_rappen' => 0,
```

- [ ] **Step 16: Update the stale comment in `SharedPropsTest`**

In `tests/Feature/SharedPropsTest.php`, change the comment:

```php
                ->has('pinned')                  // array of {code, name, glyph}
```

to:

```php
                ->has('pinned')                  // array of {id, code, name}
```

- [ ] **Step 17: Run the migration**

Run: `ddev artisan migrate`
Expected: `drop_glyph_from_projects` runs successfully.

- [ ] **Step 18: Confirm no `glyph` references remain in app/database**

Run: `grep -rn "glyph" app/ database/ | grep -vi "nav-item"`
Expected: no output (every backend/seed reference removed).

- [ ] **Step 19: Run the full suite**

Run: `ddev artisan test`
Expected: PASS, including the new "has no glyph column" test. (Baseline before this work was 294; this task adds 1 test and removes none.)

- [ ] **Step 20: Commit**

```bash
git add -A
git commit -m "refactor(glyph): drop projects.glyph column and all stored-glyph plumbing"
```

---

## Task 4: Remove the glyph picker from the project forms

**Files:**
- Modify: `resources/js/Pages/Projects/Create.vue`
- Modify: `resources/js/Pages/Projects/Edit.vue`

This runs after Task 3 so the `required` glyph rule is already gone — the forms can stop sending `glyph` without store/update failing validation.

- [ ] **Step 1: `Projects/Create.vue` — remove form state**

In the `useForm({...})` call, change:

```js
  name: '', code: '', glyph: 'alt-0', description: '',
```

to:

```js
  name: '', code: '', description: '',
```

- [ ] **Step 2: `Projects/Create.vue` — remove transform line**

In the `submit()` transform object, delete the line:

```js
    glyph: d.glyph,
```

- [ ] **Step 3: `Projects/Create.vue` — remove the select markup**

Delete this entire block from the template:

```html
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
```

- [ ] **Step 4: `Projects/Edit.vue` — remove form state**

In the `useForm({...})` call, delete the line:

```js
  glyph: props.project.glyph,
```

- [ ] **Step 5: `Projects/Edit.vue` — remove transform line**

In the `submit()` transform object, delete the line:

```js
    glyph: d.glyph,
```

- [ ] **Step 6: `Projects/Edit.vue` — remove the select markup**

Delete this entire block from the template:

```html
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
```

- [ ] **Step 7: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Projects/Create.vue resources/js/Pages/Projects/Edit.vue
git commit -m "feat(glyph): remove the manual glyph picker from project forms"
```

---

## Task 5: Full verification

- [ ] **Step 1: Run the full suite**

Run: `ddev artisan test`
Expected: all pass.

- [ ] **Step 2: Build assets**

Run: `ddev npm run build`
Expected: clean build.

- [ ] **Step 3: Confirm no stray `glyph` anywhere (excluding nav icons)**

Run: `grep -rn "glyph" app/ database/ resources/js/Pages resources/js/Components | grep -vi "nav-item"`
Expected: only `glyphClass` references in the render sites — no `.glyph` property reads, no `glyphFor`, no form select.

- [ ] **Step 4: Manual end-to-end check**

- Open Clients index and a client's detail page → the client's badge color is the same in both, and stable across search/filter.
- Open Projects index, a project's Show page, and the Today/timer view → the same project shows the same color everywhere.
- Create a new project → it gets a color automatically; the form has no glyph picker. Edit it → still no picker, color unchanged.

---

## Self-Review Notes

- **Spec coverage:** util (Task 1), all 6 render sites + delete `glyphFor` (Task 2), full backend removal incl. column/model/requests/payloads/eager-loads/importer/seeder/factory/tests (Task 3), picker removal from both forms (Task 4), verification (Task 5). All spec sections mapped.
- **Critical eager-load fix:** Task 3 removes `glyph` from the `with(['project:...'])` and `select(...)`/`get(...)` column lists (SidebarProps, ClientDetail, TimerToday) — leaving it would throw "Unknown column 'glyph'" after the drop.
- **Ordering dependency:** Task 3 (removes `required` glyph rule) precedes Task 4 (forms stop sending glyph). Task 2 (render by id) precedes Task 3 so colors never depend on the removed payload field.
- **Name consistency:** `glyphClass(id)` defined in Task 1 is the exact name imported/called in every Task 2 site and the Task 5 grep.
- **No placeholders:** every step shows concrete code/commands.
