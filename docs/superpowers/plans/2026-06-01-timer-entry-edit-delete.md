# Timer Entry Edit & Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add edit and delete controls to time-entry rows on the Timer "Today" page.

**Architecture:** Frontend-only. The `PATCH /entries/{entry}` and `DELETE /entries/{entry}` endpoints, validation (`UpdateEntryRequest`), ownership checks, and their tests already exist. `EntryRow.vue` gains presentational edit/delete buttons that emit events; `Today.vue` owns the logic, reusing its existing manual-entry form as an "edit mode" (PATCH) and a native `confirm()` for delete.

**Tech Stack:** Laravel + Inertia + Vue 3 (`<script setup>`, `useForm`/`router`), Pest feature tests. Run artisan/npm via `ddev`.

---

## Context the engineer needs

- The entry payload comes from `app/Support/TimerToday.php`. Each entry already includes:
  `id`, `description` (string, never null), `task_name` (nullable), `started_at` (ISO),
  `ended_at` (ISO or null), `duration_seconds`, `billable` (bool), `running` (bool — true
  when `ended_at` is null), and `project` `{ id, name, code }`.
- The running (in-progress) entry appears in this list with `running: true`. It must **not**
  show edit/delete controls — it is managed by the timer controls above the list.
- `Today.vue` already has: a `manualForm = useForm({...})`, a `showManual` ref, a
  `composeRange()` helper that turns `date`+`start_time`+`end_time` into start/end `Date`s,
  and `dateStr(d)` / `timeStr(d)` helpers. Reuse all of these.
- There is **no JS/component test harness** in this repo. Vue changes are verified by
  building (`ddev exec npm run build`) and exercising the app. Backend behaviour is covered
  by `tests/Feature/Http/EntryControllerTest.php` (update, delete, cross-user 403 all green).

## File structure

- **Modify** `app/Support/TimerToday.php` — no change needed (already emits `running`); only
  referenced by the guard test in Task 1.
- **Modify** `tests/Feature/Http/TimerControllerTest.php` — add a guard test for the `running` flag.
- **Modify** `resources/js/Components/EntryRow.vue` — add edit/delete buttons + emits + scoped CSS.
- **Modify** `resources/css/base.css` — add an actions column to the `.entry-row` grid.
- **Modify** `resources/js/Pages/Timer/Today.vue` — edit mode, delete handler, event wiring, form chrome.

---

## Task 1: Guard test — today payload exposes `running` per entry

The UI hides controls on the running row using `entry.running`. Lock that contract with a test.

**Files:**
- Test: `tests/Feature/Http/TimerControllerTest.php`

- [ ] **Step 1: Add the test**

Insert this test after the existing `today entries carry the project name even when description is blank...` test:

```php
test('today payload flags the running entry so the row can hide edit/delete controls', function () {
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'finished', 'billable' => true,
        'started_at' => today()->setHour(9), 'ended_at' => today()->setHour(10),
    ]);
    TimeEntry::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'description' => 'in progress', 'billable' => true,
        'started_at' => today()->setHour(11), 'ended_at' => null,
    ]);

    $this->get('/timer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Timer/Today')
            ->has('entries', 2)
            ->where('entries.0.running', false)
            ->where('entries.1.running', true)
            ->etc()
        );
});
```

- [ ] **Step 2: Run the test**

Run: `ddev exec php artisan test tests/Feature/Http/TimerControllerTest.php`
Expected: PASS (guards the already-implemented `running` contract). If it fails, `TimerToday.php`
is not emitting `running` correctly — fix there before proceeding.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Http/TimerControllerTest.php
git commit -m "test(timer): guard running flag in today payload"
```

---

## Task 2: EntryRow edit/delete buttons

Add an actions cell with `✎` and `✕` buttons, shown only when the entry is not running. The
row stays presentational and emits `edit`/`delete` to its parent.

**Files:**
- Modify: `resources/js/Components/EntryRow.vue`
- Modify: `resources/css/base.css:770`

- [ ] **Step 1: Replace `EntryRow.vue` with the version below**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  entry: { type: Object, required: true },
  colorIndex: { type: Number, default: 0 },
});

const emit = defineEmits(['edit', 'delete']);

const COLORS = ['#2d4a3a', '#c97b3c', '#b8941f', '#1a1a1a', '#7a8c5c', '#b54834'];

// Main label: the description, else the task name. When both are missing the
// template shows a muted "no description" placeholder so the row never collapses
// to an unreadable blank.
const label = computed(() => props.entry.description || props.entry.task_name || '');

// Secondary line: always the project, so every row stays identifiable. When a
// description occupies the main line, append the task for extra context.
const context = computed(() => {
  const project = props.entry.project?.name;
  const task = props.entry.task_name;
  if (task && props.entry.description && task !== props.entry.description) {
    return project ? `${project} · ${task}` : task;
  }
  return project || '';
});

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
      <span v-if="label">{{ label }}</span>
      <span v-else class="no-desc">no description</span>
      <span v-if="context" class="sub">{{ context }}</span>
    </div>
    <div class="time">
      {{ fmtTime(entry.started_at) }} –
      <span v-if="entry.running" style="color: var(--rust)">now</span>
      <span v-else>{{ fmtTime(entry.ended_at) }}</span>
    </div>
    <div class="dur">{{ fmtHM(entry.duration_seconds) }}</div>
    <div class="billable" :class="{ no: !entry.billable }">{{ entry.billable ? 'billable' : '—' }}</div>
    <div class="actions">
      <template v-if="!entry.running">
        <button type="button" class="row-action" title="Edit entry" aria-label="Edit entry" @click="emit('edit', entry)">✎</button>
        <button type="button" class="row-action row-action--danger" title="Delete entry" aria-label="Delete entry" @click="emit('delete', entry)">✕</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.no-desc { color: var(--ink-4); font-style: italic; }
.actions { display: flex; gap: 4px; justify-content: flex-end; }
.row-action {
  border: none;
  background: none;
  cursor: pointer;
  color: var(--ink-3);
  font-size: var(--fs-sm);
  line-height: 1;
  padding: 2px 4px;
}
.row-action:hover { color: var(--ink); }
.row-action--danger:hover { color: var(--red); }
</style>
```

- [ ] **Step 2: Add the actions column to the grid**

In `resources/css/base.css`, change line 770 from:

```css
  grid-template-columns: 12px 1fr 90px 80px 80px 40px;
```

to:

```css
  grid-template-columns: 12px 1fr 90px 80px 80px 40px 52px;
```

- [ ] **Step 3: Build**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors; `Today-*.js` rebuilt.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/EntryRow.vue resources/css/base.css
git commit -m "feat(timer): edit/delete buttons on entry rows (hidden for running)"
```

---

## Task 3: Today.vue — edit mode, delete handler, event wiring

Wire the row events to an edit flow (pre-fill the manual form, PATCH on save) and a delete
flow (confirm, then DELETE).

**Files:**
- Modify: `resources/js/Pages/Timer/Today.vue`

- [ ] **Step 1: Import `nextTick`**

Change the first import line from:

```js
import { computed, onMounted, onUnmounted, ref } from 'vue';
```

to:

```js
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
```

- [ ] **Step 2: Add edit state next to `showManual`**

Find:

```js
const showManual = ref(false);
const page = usePage();
```

Replace with:

```js
const showManual = ref(false);
const editingId = ref(null);
const originalProjectId = ref(null);
const formEl = ref(null);
const page = usePage();
```

- [ ] **Step 3: Add `startEdit` and `deleteEntry`, and clear edit state in `cancelManual`**

Replace the existing `cancelManual` function:

```js
function cancelManual() {
  showManual.value = false;
  manualForm.clearErrors();
  manualForm.reset();
}
```

with:

```js
function startEdit(entry) {
  const start = new Date(entry.started_at);
  const end = entry.ended_at ? new Date(entry.ended_at) : new Date();
  manualForm.clearErrors();
  manualForm.project_id = entry.project.id;
  manualForm.description = entry.description || '';
  manualForm.date = dateStr(start);
  manualForm.start_time = timeStr(start);
  manualForm.end_time = timeStr(end);
  manualForm.billable = entry.billable;
  editingId.value = entry.id;
  originalProjectId.value = entry.project.id;
  showManual.value = true;
  nextTick(() => formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

function deleteEntry(entry) {
  if (window.confirm('Delete this entry?')) {
    router.delete(`/entries/${entry.id}`, { preserveScroll: true });
  }
}

function cancelManual() {
  showManual.value = false;
  editingId.value = null;
  manualForm.clearErrors();
  manualForm.reset();
}
```

- [ ] **Step 4: Branch `submitManual` between create (POST) and edit (PATCH)**

Replace the existing `submitManual` function:

```js
function submitManual() {
  manualForm.transform((data) => {
    const r = composeRange();
    return {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: r ? r.start.toISOString() : null,
      ended_at: r ? r.end.toISOString() : null,
    };
  }).post('/entries', {
    onSuccess: () => { showManual.value = false; manualForm.reset(); },
    preserveScroll: true,
  });
}
```

with:

```js
function submitManual() {
  const editing = editingId.value;
  manualForm.transform((data) => {
    const r = composeRange();
    const payload = {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: r ? r.start.toISOString() : null,
      ended_at: r ? r.end.toISOString() : null,
    };
    // When editing and the project changed, drop the task so we never leave an
    // entry whose task belongs to a different project.
    if (editing && data.project_id !== originalProjectId.value) {
      payload.task_id = null;
    }
    return payload;
  });

  const opts = {
    onSuccess: () => { showManual.value = false; editingId.value = null; manualForm.reset(); },
    preserveScroll: true,
  };

  if (editing) {
    manualForm.patch(`/entries/${editing}`, opts);
  } else {
    manualForm.post('/entries', opts);
  }
}
```

- [ ] **Step 5: Wire the row events and add the form ref + edit-mode chrome**

(a) Attach a ref to the form. Change:

```html
  <form v-if="showManual" @submit.prevent="submitManual" class="manual-entry">
    <div class="manual-entry__title">Manual entry</div>
```

to:

```html
  <form v-if="showManual" ref="formEl" @submit.prevent="submitManual" class="manual-entry">
    <div class="manual-entry__title">{{ editingId ? 'Edit entry' : 'Manual entry' }}</div>
```

(b) Change the submit button label. Change:

```html
        <button type="submit" class="btn primary" :disabled="manualForm.processing">Save entry</button>
```

to:

```html
        <button type="submit" class="btn primary" :disabled="manualForm.processing">{{ editingId ? 'Save changes' : 'Save entry' }}</button>
```

(c) Make the header toggle button cancel cleanly when the form is open. Change:

```html
      <button class="btn" @click="showManual = !showManual">{{ showManual ? '× cancel' : '+ Manual entry' }}</button>
```

to:

```html
      <button class="btn" @click="showManual ? cancelManual() : (showManual = true)">{{ showManual ? '× cancel' : '+ Manual entry' }}</button>
```

(d) Wire the row events. Change:

```html
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" />
```

to:

```html
      <EntryRow v-for="(e, i) in entries" :key="e.id" :entry="e" :color-index="i" @edit="startEdit" @delete="deleteEntry" />
```

- [ ] **Step 6: Build**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Timer/Today.vue
git commit -m "feat(timer): edit (PATCH) and delete entries from the Today page"
```

---

## Task 4: Verification

Confirm the full feature works end-to-end in the running app.

**Files:** none (verification only)

- [ ] **Step 1: Run the relevant feature tests**

Run: `ddev exec php artisan test tests/Feature/Http/EntryControllerTest.php tests/Feature/Http/TimerControllerTest.php`
Expected: all PASS.

- [ ] **Step 2: Manually verify in the app** (`ddev launch /timer`, with at least one finished entry and the timer running)

  - Finished row shows `✎` and `✕`; the running row shows neither.
  - Click `✎`: the form opens titled "Edit entry," pre-filled with that entry's project,
    description, date, from/to, and billable; the page scrolls to the form.
  - Change the description and click "Save changes"; the row updates and the change persists
    after a reload.
  - Change the project and save; the entry moves to the new project (no validation error).
  - Click `✕` and accept the confirm dialog; the row disappears and stays gone after reload.
  - Click `✕` and cancel the dialog; the entry remains.
  - Click "+ Manual entry" after editing; the form opens in create mode (title "Manual entry",
    empty/default fields).

- [ ] **Step 3: No commit** (verification only). If issues are found, return to the relevant task.

---

## Self-review notes

- **Spec coverage:** edit via reused form (Task 3) ✓; delete via `confirm()` (Task 3) ✓;
  running row hides controls (Task 2 template + Task 1 guard) ✓; `task_id` project-change rule
  (Task 3 Step 4) ✓; grid actions column (Task 2 Step 2) ✓; error surfacing reuses
  `manual-entry__err` (unchanged, still bound to `manualForm.errors`) ✓.
- **Naming consistency:** `editingId`, `originalProjectId`, `formEl`, `startEdit`, `deleteEntry`
  used identically across steps; events `edit`/`delete` match `defineEmits` and the `@edit`/`@delete`
  bindings.
- **No backend changes:** all endpoints/validation/authorization pre-exist and are tested.
