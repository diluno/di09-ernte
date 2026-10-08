# Project Notes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the operator keep a dated, markdown-formatted log of notes on each project, written and read on the project page and findable through ⌘K.

**Architecture:** A `project_notes` table (`project_id`, `body`, timestamps) with a `ProjectNote` model. A small `ProjectNoteController` (store / update / destroy, all `back()`) mirrors `TaskController`. `ProjectDetail::payload()` ships the notes with server-rendered HTML from `App\Support\Markdown`. A `ProjectNotes.vue` component on `Projects/Show.vue` holds the composer and the list. `SearchController` gains a notes source that deep-links to `/projects/{code}#note-{id}`.

**Tech Stack:** Laravel, Inertia v2, Vue 3, MariaDB (DDEV), Pest. Run tests with `ddev artisan test`; build assets with `ddev npm run build`.

**Spec:** `docs/superpowers/specs/2026-10-08-project-notes-design.md`

**Branch:** `project-notes` off `main`.

---

## File Structure

- **Create** `database/migrations/<timestamp>_create_project_notes.php`
- **Create** `app/Models/ProjectNote.php`, `database/factories/ProjectNoteFactory.php`
- **Modify** `app/Models/Project.php` — `notes()` relation
- **Create** `app/Http/Requests/ProjectNoteRequest.php`
- **Create** `app/Http/Controllers/ProjectNoteController.php`
- **Modify** `routes/web.php` — three routes after the task routes (line ~36)
- **Modify** `app/Support/ProjectDetail.php` — `notes` + `counts.notes`
- **Create** `resources/js/Components/ProjectNotes.vue`
- **Modify** `resources/js/Pages/Projects/Show.vue` — new prop + section
- **Modify** `resources/css/base.css` — `.note*` rules after `.task-list` (line ~802)
- **Modify** `app/Http/Controllers/SearchController.php`, `resources/js/Components/CommandPalette.vue`
- **Tests:** `tests/Feature/Schema/ProjectNoteTest.php` (new), `tests/Feature/Http/ProjectNoteControllerTest.php` (new), `tests/Feature/Http/ProjectControllerTest.php`, `tests/Feature/Http/SearchControllerTest.php`

---

## Task 1: Table, model, factory, relation

**Files:** migration, `app/Models/ProjectNote.php`, `database/factories/ProjectNoteFactory.php`, `app/Models/Project.php`, `tests/Feature/Schema/ProjectNoteTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Feature/Schema/ProjectNoteTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\ProjectNote;

test('a project has notes, newest first', function () {
    $project = Project::factory()->create();
    $old = ProjectNote::factory()->create(['project_id' => $project->id, 'created_at' => now()->subDay()]);
    $new = ProjectNote::factory()->create(['project_id' => $project->id]);

    expect($project->notes->pluck('id')->all())->toBe([$new->id, $old->id]);
    expect($new->project->is($project))->toBeTrue();
});

test('deleting a project deletes its notes', function () {
    $note = ProjectNote::factory()->create();

    $note->project->delete();

    expect(ProjectNote::find($note->id))->toBeNull();
});
```

- [ ] **Step 2: Run it, expect failure** — `ddev artisan test --filter=ProjectNoteTest` (class not found).

- [ ] **Step 3: Migration** — `ddev artisan make:migration create_project_notes`, then:

```php
public function up(): void
{
    Schema::create('project_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
        $table->text('body');
        $table->timestamps();

        $table->index(['project_id', 'created_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('project_notes');
}
```

- [ ] **Step 4: Model** — `app/Models/ProjectNote.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectNote extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'body'];

    public function project() { return $this->belongsTo(Project::class); }
}
```

- [ ] **Step 5: Factory** — `database/factories/ProjectNoteFactory.php`, same shape as `TaskFactory`:

```php
public function definition(): array
{
    return [
        'project_id' => Project::factory(),
        'body' => $this->faker->sentence(),
    ];
}
```

- [ ] **Step 6: Relation** — in `app/Models/Project.php`, next to `tasks()`:

```php
public function notes() { return $this->hasMany(ProjectNote::class)->orderByDesc('created_at')->orderByDesc('id'); }
```

- [ ] **Step 7: Migrate and run** — `ddev artisan migrate && ddev artisan test --filter=ProjectNoteTest` → green.

- [ ] **Step 8: Commit** — `feat(projects): project_notes table and model`

---

## Task 2: Store / update / delete endpoints

**Files:** `app/Http/Requests/ProjectNoteRequest.php`, `app/Http/Controllers/ProjectNoteController.php`, `routes/web.php`, `tests/Feature/Http/ProjectNoteControllerTest.php`

- [ ] **Step 1: Write the failing tests** — create `tests/Feature/Http/ProjectNoteControllerTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\User;

beforeEach(function () {
    $this->project = Project::factory()->create(['code' => 'ATLS-FLT']);
    $this->actingAs(User::factory()->create());
});

test('POST /projects/{code}/notes adds a note to the project', function () {
    $this->post('/projects/ATLS-FLT/notes', ['body' => "Call with **Mara**\n- staging by Friday"])
        ->assertRedirect();

    expect($this->project->notes()->sole()->body)->toBe("Call with **Mara**\n- staging by Friday");
});

test('POST /projects/{code}/notes rejects an empty body', function () {
    $this->post('/projects/ATLS-FLT/notes', ['body' => ''])->assertSessionHasErrors('body');
    $this->post('/projects/ATLS-FLT/notes', ['body' => "  \n "])->assertSessionHasErrors('body');

    expect(ProjectNote::count())->toBe(0);
});

test('PATCH /project-notes/{id} changes the body and keeps the date', function () {
    $note = ProjectNote::factory()->create(['project_id' => $this->project->id, 'created_at' => '2026-09-01 10:00:00']);

    $this->patch("/project-notes/{$note->id}", ['body' => 'Rewritten'])->assertRedirect();

    expect($note->fresh())
        ->body->toBe('Rewritten')
        ->created_at->toDateTimeString()->toBe('2026-09-01 10:00:00');
});

test('PATCH /project-notes/{id} rejects an empty body', function () {
    $note = ProjectNote::factory()->create(['body' => 'keep me']);

    $this->patch("/project-notes/{$note->id}", ['body' => ''])->assertSessionHasErrors('body');

    expect($note->fresh()->body)->toBe('keep me');
});

test('DELETE /project-notes/{id} deletes the note', function () {
    $note = ProjectNote::factory()->create();

    $this->delete("/project-notes/{$note->id}")->assertRedirect();

    expect(ProjectNote::find($note->id))->toBeNull();
});

test('note routes require login', function () {
    auth()->logout();

    $this->post('/projects/ATLS-FLT/notes', ['body' => 'x'])->assertRedirect('/login');
});
```

- [ ] **Step 2: Run, expect 404s** — `ddev artisan test --filter=ProjectNoteControllerTest`.

- [ ] **Step 3: Form request** — `app/Http/Requests/ProjectNoteRequest.php` (one request for store and update; `TrimStrings` + `ConvertEmptyStringsToNull` turn a whitespace-only body into `null`, which `required` rejects):

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }   // single-user app

    public function rules(): array
    {
        return [
            'body' => 'required|string|max:20000',
        ];
    }
}
```

- [ ] **Step 4: Controller** — `app/Http/Controllers/ProjectNoteController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectNoteRequest;
use App\Models\Project;
use App\Models\ProjectNote;
use Illuminate\Http\RedirectResponse;

class ProjectNoteController extends Controller
{
    public function store(ProjectNoteRequest $request, Project $project): RedirectResponse
    {
        $project->notes()->create($request->validated());

        return back();
    }

    public function update(ProjectNoteRequest $request, ProjectNote $projectNote): RedirectResponse
    {
        $projectNote->update($request->validated());

        return back();
    }

    public function destroy(ProjectNote $projectNote): RedirectResponse
    {
        $projectNote->delete();

        return back();
    }
}
```

- [ ] **Step 5: Routes** — in `routes/web.php`, after the `tasks.destroy` line, plus the `use` import:

```php
Route::post('/projects/{project:code}/notes', [ProjectNoteController::class, 'store'])->name('project-notes.store');
Route::patch('/project-notes/{projectNote}', [ProjectNoteController::class, 'update'])->name('project-notes.update');
Route::delete('/project-notes/{projectNote}', [ProjectNoteController::class, 'destroy'])->name('project-notes.destroy');
```

- [ ] **Step 6: Run** — `ddev artisan test --filter=ProjectNoteControllerTest` → green. If the whitespace-only case passes validation, check that `TrimStrings` is still in the middleware stack before changing the rule.

- [ ] **Step 7: Commit** — `feat(projects): add, edit and delete project notes`

---

## Task 3: Notes in the project page payload

**Files:** `app/Support/ProjectDetail.php`, `tests/Feature/Http/ProjectControllerTest.php`

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Http/ProjectControllerTest.php` (`User`, `Project`, `Assert` are already imported there; add `use App\Models\ProjectNote;`):

```php
test('Projects/Show payload lists notes newest first with rendered markdown', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    ProjectNote::factory()->create(['project_id' => $project->id, 'body' => 'older', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    $edited = ProjectNote::factory()->create(['project_id' => $project->id, 'body' => 'Agreed **fixed price**', 'created_at' => now()->subDay(), 'updated_at' => now()]);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.notes', 2)
            ->has('notes', 2)
            ->where('notes.0.id', $edited->id)
            ->where('notes.0.body', 'Agreed **fixed price**')
            ->where('notes.0.body_html', fn ($html) => str_contains($html, '<strong>fixed price</strong>'))
            ->where('notes.0.edited', true)
            ->has('notes.0.created_at')
            ->where('notes.1.body', 'older')
            ->where('notes.1.edited', false)
        );
});

test('note html escapes raw html in the body', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    ProjectNote::factory()->create(['project_id' => $project->id, 'body' => '<script>alert(1)</script>']);

    $this->actingAs($user)->get("/projects/{$project->code}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('notes.0.body_html', fn ($html) => ! str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'))
        );
});
```

- [ ] **Step 2: Run, expect failure** — `ddev artisan test --filter=ProjectControllerTest`.

- [ ] **Step 3: Implement** — in `app/Support/ProjectDetail.php`: add `use App\Models\ProjectNote;`, add `'notes' => self::notes($project),` after `'recent_entries'`, add `'notes' => ProjectNote::where('project_id', $project->id)->count(),` to `counts`, and the method:

```php
private static function notes(Project $project): array
{
    return $project->notes()
        ->get()
        ->map(fn (ProjectNote $n) => [
            'id' => $n->id,
            'body' => $n->body,
            'body_html' => Markdown::toHtml($n->body),
            'created_at' => $n->created_at->toIso8601String(),
            'edited' => $n->updated_at->gt($n->created_at),
        ])
        ->all();
}
```

(`Markdown` is in the same `App\Support` namespace, no import needed.)

- [ ] **Step 4: Run** — `ddev artisan test --filter=ProjectControllerTest` → green. The existing `Projects/Show` tests must stay green; if any uses a strict `has('counts', 2)`-style assertion, update it to the new shape.

- [ ] **Step 5: Commit** — `feat(projects): ship notes with the project page payload`

---

## Task 4: Notes section on the project page

**Files:** `resources/js/Components/ProjectNotes.vue`, `resources/js/Pages/Projects/Show.vue`, `resources/css/base.css`

- [ ] **Step 1: Component** — create `resources/js/Components/ProjectNotes.vue`:

```vue
<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AutoTextarea from '@/Components/AutoTextarea.vue';

const props = defineProps({
  projectCode: { type: String, required: true },
  notes: { type: Array, required: true },
});

const addForm = useForm({ body: '' });
const editForm = useForm({ body: '' });
const editingId = ref(null);
const highlightId = ref(null);

const dateFmt = new Intl.DateTimeFormat('de-CH', { dateStyle: 'medium', timeStyle: 'short' });
function fmtDate(iso) { return dateFmt.format(new Date(iso)); }

function add() {
  if (!addForm.body.trim()) return;
  addForm.post(`/projects/${props.projectCode}/notes`, {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
  });
}

function startEdit(note) {
  editingId.value = note.id;
  editForm.clearErrors();
  editForm.body = note.body;
}

function cancelEdit() { editingId.value = null; }

function saveEdit(note) {
  editForm.patch(`/project-notes/${note.id}`, {
    preserveScroll: true,
    onSuccess: cancelEdit,
  });
}

function remove(note) {
  if (window.confirm('Delete this note?')) {
    router.delete(`/project-notes/${note.id}`, { preserveScroll: true });
  }
}

// ⌘K results link to #note-{id}: bring that note into view and flash it.
onMounted(async () => {
  const match = window.location.hash.match(/^#note-(\d+)$/);
  if (!match) return;
  highlightId.value = Number(match[1]);
  await nextTick();
  document.getElementById(`note-${match[1]}`)?.scrollIntoView({ block: 'start' });
});
</script>

<template>
  <div class="notes">
    <form class="note-compose" @submit.prevent="add">
      <AutoTextarea
        v-model="addForm.body"
        class="note-input"
        placeholder="Add a note… (markdown)"
        @keydown.meta.enter.prevent="add"
        @keydown.ctrl.enter.prevent="add"
      />
      <button type="submit" class="btn primary" :disabled="addForm.processing || !addForm.body.trim()">Add note</button>
    </form>
    <div v-if="addForm.errors.body" class="note-err">{{ addForm.errors.body }}</div>

    <article
      v-for="note in notes"
      :id="`note-${note.id}`"
      :key="note.id"
      class="note"
      :class="{ 'is-target': note.id === highlightId }"
    >
      <header class="note-meta">
        <time :datetime="note.created_at">{{ fmtDate(note.created_at) }}</time>
        <span v-if="note.edited">· edited</span>
        <span class="note-actions">
          <button type="button" class="note-action" @click="startEdit(note)">Edit</button>
          <button type="button" class="note-action" @click="remove(note)">Delete</button>
        </span>
      </header>

      <form v-if="editingId === note.id" class="note-compose" @submit.prevent="saveEdit(note)">
        <AutoTextarea
          v-model="editForm.body"
          class="note-input"
          @keydown.meta.enter.prevent="saveEdit(note)"
          @keydown.ctrl.enter.prevent="saveEdit(note)"
          @keydown.esc.prevent="cancelEdit"
        />
        <div style="display: flex; gap: 8px">
          <button type="submit" class="btn primary" :disabled="editForm.processing">Save</button>
          <button type="button" class="btn ghost" @click="cancelEdit">Cancel</button>
        </div>
        <div v-if="editForm.errors.body" class="note-err">{{ editForm.errors.body }}</div>
      </form>
      <!-- body_html comes from App\Support\Markdown, which escapes raw HTML. -->
      <div v-else class="note-body" v-html="note.body_html" />
    </article>

    <div v-if="notes.length === 0" class="muted" style="padding: 12px">No notes yet</div>
  </div>
</template>
```

- [ ] **Step 2: Mount it** — in `resources/js/Pages/Projects/Show.vue`: import `ProjectNotes`, add `notes: { type: Array, required: true },` to `defineProps`, and insert between the Tasks block and the "Recent entries" heading:

```vue
<h3 class="section-title" style="margin-top: 28px">Notes <span class="muted">{{ counts.notes || '' }}</span></h3>
<ProjectNotes :project-code="project.code" :notes="notes" />
```

- [ ] **Step 3: Styles** — in `resources/css/base.css`, after the `.task-*` rules:

```css
/* Project notes */
.notes { display: flex; flex-direction: column; }
.note-compose { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.note-input {
  width: 100%; min-height: 34px;
  border: 1px solid var(--border-strong); background: var(--paper);
  padding: 6px 8px; font-family: inherit; font-size: var(--fs-sm); color: var(--ink);
}
.note-input:focus { outline: none; border-color: var(--accent); }
.note-err { color: var(--red); font-size: var(--fs-sm); margin-top: 6px; }
.note { padding: 14px 0; border-bottom: 1px solid var(--rule); scroll-margin-top: 120px; }
.note.is-target { animation: note-flash 1.6s ease-out; }
@keyframes note-flash { from { background: var(--bg-hover); } to { background: transparent; } }
.note-meta { display: flex; gap: 6px; align-items: baseline; font-size: var(--fs-xs); color: var(--ink-3); }
.note-actions { margin-left: auto; display: flex; gap: 10px; opacity: 0; }
.note:hover .note-actions, .note:focus-within .note-actions { opacity: 1; }
.note-action { background: none; border: 0; padding: 0; font: inherit; color: var(--ink-3); cursor: pointer; }
.note-action:hover { color: var(--ink); text-decoration: underline; }
.note-body { margin-top: 6px; font-size: var(--fs-sm); line-height: 1.6; color: var(--ink-2); overflow-wrap: anywhere; }
.note-body > :first-child { margin-top: 0; }
.note-body > :last-child { margin-bottom: 0; }
.note-body p, .note-body ul, .note-body ol, .note-body pre, .note-body table { margin: 0 0 8px; }
.note-body ul, .note-body ol { padding-left: 20px; }
.note-body h1, .note-body h2, .note-body h3 { font-size: var(--fs-sm); font-weight: 600; margin: 12px 0 4px; color: var(--ink); }
.note-body a { color: var(--ink); text-decoration: underline; }
.note-body code { font-family: var(--font-mono); font-size: .92em; background: var(--bg-hover); padding: 1px 4px; }
.note-body pre { background: var(--bg-hover); padding: 8px 10px; overflow-x: auto; }
.note-body pre code { background: none; padding: 0; }
.note-body th, .note-body td { border-bottom: 1px solid var(--rule); padding: 4px 10px 4px 0; text-align: left; }
```

Before saving, confirm each token exists (`grep -n -- "--rule\|--bg-hover\|--border-strong\|--paper\|--font-mono" resources/css/tokens.css resources/css/base.css`) and swap any that does not. Measure the sticky `.page-head` height in the browser and set `scroll-margin-top` to match.

- [ ] **Step 4: Build** — `ddev npm run build` (the Inertia feature tests read the Vite manifest).

- [ ] **Step 5: Verify in the browser** on the DDEV site, on a real project page:
  - add a note with a list, a link, `inline code` and a blank-line paragraph break → renders, field clears, page does not jump
  - ⌘+Enter submits; empty field keeps the button disabled
  - Edit → Save changes the text and shows "· edited"; Escape cancels
  - Delete asks, then removes
  - a body of `<b>x</b>` shows as literal text
  - long unbroken URL does not widen the column
  - open `/projects/{code}#note-{id}` directly → note is in view below the sticky header and flashes

- [ ] **Step 6: Full suite** — `ddev artisan test` → green.

- [ ] **Step 7: Commit** — `feat(projects): notes section on the project page`

---

## Task 5: ⌘K search

**Files:** `app/Http/Controllers/SearchController.php`, `resources/js/Components/CommandPalette.vue`, `tests/Feature/Http/SearchControllerTest.php`

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Http/SearchControllerTest.php` (add `use App\Models\ProjectNote;`):

```php
test('search finds a project note by its text and links to it', function () {
    $project = Project::factory()->create(['name' => 'Fleet Dashboard', 'code' => 'ATLS-FLT']);
    $note = ProjectNote::factory()->create([
        'project_id' => $project->id,
        'body' => "Staging login lives in the vault.\nAsk Mara for the **kumquat** token before go-live.",
    ]);

    $hit = collect($this->actingAs($this->user)->getJson('/api/search?q=kumquat')->assertOk()->json())
        ->firstWhere('type', 'note');

    expect($hit)
        ->id->toBe($note->id)
        ->label->toBe('Fleet Dashboard')
        ->url->toBe("/projects/ATLS-FLT#note-{$note->id}");
    expect($hit['sublabel'])->toContain('kumquat')->not->toContain("\n");
});

test('notes stay visible when other results would fill the list', function () {
    $client = Client::factory()->create(['name' => 'Kumquat AG']);
    Project::factory()->count(5)->create(['client_id' => $client->id]);
    Invoice::factory()->count(5)->create(['client_id' => $client->id]);
    ProjectNote::factory()->create(['body' => 'kumquat follow-up']);

    $results = collect($this->actingAs($this->user)->getJson('/api/search?q=kumquat')->json());

    expect($results)->toHaveCount(8);
    expect($results->where('type', 'note'))->toHaveCount(1);
});

test('notes are left out of empty and type-filtered searches', function () {
    ProjectNote::factory()->create(['body' => 'kumquat']);

    $empty = collect($this->actingAs($this->user)->getJson('/api/search')->json());
    $typed = collect($this->actingAs($this->user)->getJson('/api/search?q=kumquat&type=project')->json());

    expect($empty->where('type', 'note'))->toBeEmpty();
    expect($typed->where('type', 'note'))->toBeEmpty();
});
```

- [ ] **Step 2: Run, expect failure** — `ddev artisan test --filter=SearchControllerTest`.

- [ ] **Step 3: Implement** — in `SearchController::__invoke`, replace the final `return` with:

```php
$notes = (! $type && $query !== '') ? $this->notes($query, 2) : [];

return response()->json($results->take(8 - count($notes))->merge($notes)->values());
```

and add (imports: `App\Models\ProjectNote`, `Illuminate\Support\Str`):

```php
private function notes(string $query, int $limit): array
{
    return ProjectNote::query()
        ->with('project:id,name,code')
        ->where('body', 'like', "%{$query}%")
        ->orderByDesc('created_at')
        ->limit($limit)
        ->get()
        ->map(fn (ProjectNote $note) => [
            'type' => 'note',
            'id' => $note->id,
            'label' => $note->project->name,
            'sublabel' => Str::excerpt(Str::squish($note->body), $query, ['radius' => 40]) ?? Str::limit(Str::squish($note->body), 80),
            'url' => "/projects/{$note->project->code}#note-{$note->id}",
        ])
        ->all();
}
```

- [ ] **Step 4: Palette copy** — in `CommandPalette.vue` change the placeholder to `'project, client, invoice, note…'`. `choose()` already does `router.visit(result.url)`; the hash is handled by `ProjectNotes.vue`.

- [ ] **Step 5: Run** — `ddev artisan test` → green; `ddev npm run build`.

- [ ] **Step 6: Verify in the browser** — ⌘K, type a word that only occurs in a note → a `note` row with the project name and excerpt; Enter lands on the project page with that note in view. Repeat while already on that project's page (same-page visit) and confirm the note still scrolls into view; if it does not, switch the `onMounted` hook in `ProjectNotes.vue` to a `watch` on `usePage().url`.

- [ ] **Step 7: Commit** — `feat(search): find project notes from the command palette`

---

## Deploy

One additive migration, no backfill, no config. Normal Forge deploy runs `migrate --force`.

## Deferred (not in this plan)

- Notes on `GET /api/projects/{code}` for the iOS app; MCP tools to add or list notes.
- Notes on clients.
- Collapsing long logs ("show all n") if a project grows past what is comfortable to scroll.
