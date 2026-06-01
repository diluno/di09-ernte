# Duration-based Time Entries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Timer "Today" manual-entry/edit form take a duration and the entry rows show a duration, while keeping `started_at`/`ended_at` and the live timer under the hood.

**Architecture:** Frontend-only. A new pure `formatters/duration.js` parses/formats durations. `Today.vue` swaps its From/To inputs for one duration field and synthesizes `started_at`/`ended_at` before POST/PATCH (unchanged backend contract). `EntryRow.vue` drops the time-range cell and shows the duration. No backend, schema, timer-service, invoicing, API, or test changes.

**Tech Stack:** Vue 3 (`<script setup>`, Inertia `useForm`), pure ESM helper, hand-rolled. `npm run build` via `ddev`; backend guard tests via `ddev exec php artisan test`. The repo is `"type": "module"`, so the helper is sanity-checked with `node`.

---

## Context the engineer needs

- **No backend change.** The form still sends ISO `started_at`/`ended_at`; `StoreEntryRequest`/`UpdateEntryRequest` (which convert UTC→app tz) and `duration_seconds` (computed from the timestamps) are unchanged. The live timer (`TimerHero`/`useTimer`/`TimerService`) is untouched.
- **Synthesis rules** (times are invisible, so only the date placement matters):
  - **Create:** `started_at` = the selected `date` at the **current wall-clock time**; `ended_at` = `started_at` + duration.
  - **Edit:** keep the entry's **original `started_at`**; `ended_at` = `started_at` + new duration (entry keeps its day position).
- Existing `Today.vue` helpers to reuse: `pad`, `dateStr(d)`, `timeStr(d)`. Being removed: `composeRange`, `durationLabel`, the `start_time`/`end_time` form fields, `oneHourAgo`.
- Inertia `useForm` has `.setError(key, msg)` and `.clearErrors()`; the form's error block renders `Object.values(manualForm.errors).join(' · ')`.
- `EntryRow.vue` currently shows a `.time` cell (`09:00 – 10:30 / now`) and a `.dur` cell (`fmtHM`). The `.entry-row` grid in `base.css:770` is `12px 1fr 90px 80px 80px 56px` (bar, desc, time, dur, billable, actions).

## File structure

- **Create** `resources/js/formatters/duration.js` — `parseDuration` / `formatDuration` (pure).
- **Modify** `resources/js/Components/EntryRow.vue` — drop time cell, show duration.
- **Modify** `resources/css/base.css` — `.entry-row` grid (remove time column) + remove the `.entry-row .time` rule.
- **Modify** `resources/js/Pages/Timer/Today.vue` — duration field, synthesis, validation, prefill, scoped CSS.

---

## Task 1: Duration parse/format helper

**Files:**
- Create: `resources/js/formatters/duration.js`

- [ ] **Step 1: Create the helper**

Create `resources/js/formatters/duration.js`:

```js
// Parse a flexible duration string into total MINUTES, or null if it can't be
// read or is <= 0. Accepts:
//   "1:30"            -> 90   (H:MM)
//   "1h 30m" / "1h30m" / "1h30" / "1h" / "30m" / "90m"
//   "1.5" / "1.5h" / "2" / "2h"  -> a bare number is decimal HOURS (1.5 = 90, 2 = 120)
export function parseDuration(input) {
  if (input == null) return null;
  const s = String(input).trim().toLowerCase().replace(/\s+/g, '');
  if (s === '') return null;

  let minutes = null;

  // H:MM
  let m = s.match(/^(\d+):([0-5]?\d)$/);
  if (m) {
    minutes = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  // unit form with an h and/or m: 1h30m, 1h, 30m, 90m, 1h30
  if (minutes === null && /[hm]/.test(s)) {
    m = s.match(/^(?:(\d+)h)?(?:(\d+)m?)?$/);
    if (m && (m[1] !== undefined || m[2] !== undefined)) {
      minutes = (m[1] ? parseInt(m[1], 10) : 0) * 60 + (m[2] ? parseInt(m[2], 10) : 0);
    }
  }

  // bare decimal hours, optional trailing h: 1.5, 1.5h, 2
  if (minutes === null) {
    m = s.match(/^(\d+(?:\.\d+)?)h?$/);
    if (m) minutes = Math.round(parseFloat(m[1]) * 60);
  }

  if (minutes === null || !Number.isFinite(minutes) || minutes <= 0) return null;
  return minutes;
}

// Render minutes as "1h 30m" / "2h" / "45m" / "0m".
export function formatDuration(minutes) {
  const total = Math.max(0, Math.round(minutes));
  const h = Math.floor(total / 60);
  const m = total % 60;
  if (h && m) return `${h}h ${m}m`;
  if (h) return `${h}h`;
  return `${m}m`;
}
```

- [ ] **Step 2: Sanity-check the pure functions with node**

Run from the repo root (no `ddev` needed — pure ESM, and `package.json` is `"type": "module"`):

```bash
node -e "import('./resources/js/formatters/duration.js').then(({parseDuration:p,formatDuration:f})=>{const eq=(a,b,l)=>{if(JSON.stringify(a)!==JSON.stringify(b)){console.error('FAIL',l,'got',a,'want',b);process.exit(1)}};eq(p('1:30'),90,'1:30');eq(p('1h 30m'),90,'1h 30m');eq(p('1h30'),90,'1h30');eq(p('90m'),90,'90m');eq(p('2h'),120,'2h');eq(p('1.5'),90,'1.5');eq(p('1.5h'),90,'1.5h');eq(p('2'),120,'2');eq(p('0'),null,'0');eq(p('0:00'),null,'0:00');eq(p('abc'),null,'abc');eq(p(''),null,'empty');eq(f(90),'1h 30m','f90');eq(f(120),'2h','f120');eq(f(45),'45m','f45');console.log('OK');});"
```

Expected: prints `OK` and exits 0. If any line prints `FAIL …`, fix `parseDuration`/`formatDuration` and re-run.

- [ ] **Step 3: Commit**

```bash
git add resources/js/formatters/duration.js
git commit -m "feat(timer): duration parse/format helper"
```

---

## Task 2: Entry row shows a duration

**Files:**
- Modify: `resources/js/Components/EntryRow.vue`
- Modify: `resources/css/base.css:770` and the `.entry-row .time` rule

- [ ] **Step 1: Update `EntryRow.vue`**

Replace the entire contents of `resources/js/Components/EntryRow.vue` with:

```vue
<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import { formatDuration } from '@/formatters/duration.js';

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

const durationLabel = computed(() => formatDuration(Math.round(props.entry.duration_seconds / 60)));
</script>

<template>
  <div class="entry-row">
    <div class="bar-color" :style="{ background: COLORS[colorIndex % COLORS.length] }" />
    <div class="desc">
      <span v-if="label">{{ label }}</span>
      <span v-else class="no-desc">no description</span>
      <span v-if="context" class="sub">{{ context }}</span>
    </div>
    <div class="dur">{{ durationLabel }}</div>
    <div class="billable" :class="{ no: !entry.billable }">{{ entry.billable ? 'billable' : '—' }}</div>
    <div class="actions">
      <template v-if="!entry.running">
        <button type="button" class="row-action" title="Edit entry" aria-label="Edit entry" @click="emit('edit', entry)"><Icon name="edit" /></button>
        <button type="button" class="row-action row-action--danger" title="Delete entry" aria-label="Delete entry" @click="emit('delete', entry)"><Icon name="trash" /></button>
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
  font-size: 16px; /* crisp pixel-art icons render best at a clean 16px */
  line-height: 1;
  padding: 2px 4px;
}
.row-action:hover { color: var(--ink); }
.row-action--danger:hover { color: var(--red); }
</style>
```

(This removes the `.time` cell and the `fmtTime`/`fmtHM` helpers, and renders the duration via `formatDuration`. The running row now shows its current elapsed snapshot; the live ticking clock stays in `TimerHero`.)

- [ ] **Step 2: Update the grid in `base.css`**

In `resources/css/base.css`, change line 770 from:

```css
  grid-template-columns: 12px 1fr 90px 80px 80px 56px;
```

to (remove the 90px time column):

```css
  grid-template-columns: 12px 1fr 80px 80px 56px;
```

- [ ] **Step 3: Remove the now-unused `.time` rule**

In `resources/css/base.css`, delete this line (around line 783):

```css
.entry-row .time { color: var(--ink-2); font-variant-numeric: tabular-nums; text-align: right; }
```

- [ ] **Step 4: Build**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/EntryRow.vue resources/css/base.css
git commit -m "feat(timer): show entry duration instead of the time range"
```

---

## Task 3: Duration field in the manual-entry / edit form

**Files:**
- Modify: `resources/js/Pages/Timer/Today.vue`

- [ ] **Step 1: Import the helper**

Change the import line:

```js
import { formatChf } from '@/formatters/money.js';
```

to:

```js
import { formatChf } from '@/formatters/money.js';
import { parseDuration, formatDuration } from '@/formatters/duration.js';
```

- [ ] **Step 2: Add edit/duration state**

Find:

```js
const showManual = ref(false);
const editingId = ref(null);
const originalProjectId = ref(null);
const formEl = ref(null);
```

and replace with:

```js
const showManual = ref(false);
const editingId = ref(null);
const originalProjectId = ref(null);
const originalStartedAt = ref(null);
const durationText = ref('1:00');
const formEl = ref(null);
```

- [ ] **Step 3: Drop the time fields from the form and the old time helpers**

Replace:

```js
const nowDate = new Date();
const oneHourAgo = new Date(nowDate.getTime() - 60 * 60 * 1000);

const manualForm = useForm({
  project_id: '',
  description: '',
  date: dateStr(nowDate),
  start_time: timeStr(oneHourAgo),
  end_time: timeStr(nowDate),
  billable: true,
});

// Compose start/end Dates from the form's date + HH:MM times; an end at or before
// the start is treated as crossing midnight.
function composeRange() {
  const start = new Date(`${manualForm.date}T${manualForm.start_time}`);
  let end = new Date(`${manualForm.date}T${manualForm.end_time}`);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
  if (end <= start) end = new Date(end.getTime() + 24 * 60 * 60 * 1000);
  return { start, end };
}

const durationLabel = computed(() => {
  const r = composeRange();
  if (!r) return '—';
  const mins = Math.round((r.end - r.start) / 60000);
  return `${Math.floor(mins / 60)}h ${pad(mins % 60)}m`;
});
```

with:

```js
const nowDate = new Date();

const manualForm = useForm({
  project_id: '',
  description: '',
  date: dateStr(nowDate),
  billable: true,
});

const durationMinutes = computed(() => parseDuration(durationText.value));
const durationPreview = computed(() =>
  durationMinutes.value ? `= ${formatDuration(durationMinutes.value)}` : 'e.g. 1:30, 90m, 1.5');
```

- [ ] **Step 4: Rework `submitManual` to synthesize timestamps from the duration**

Replace the whole `submitManual` function:

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

with:

```js
function submitManual() {
  const mins = durationMinutes.value;
  if (!mins) {
    manualForm.setError('duration', 'Enter a valid duration, e.g. 1:30, 90m, or 1.5');
    return;
  }
  manualForm.clearErrors();

  const editing = editingId.value;
  manualForm.transform((data) => {
    // Create: anchor the start on the chosen date at the current time-of-day.
    // Edit: keep the entry's original start so it holds its place in the day.
    const start = (editing && originalStartedAt.value)
      ? new Date(originalStartedAt.value)
      : new Date(`${data.date}T${timeStr(new Date())}`);
    const end = new Date(start.getTime() + mins * 60000);

    const payload = {
      project_id: data.project_id,
      description: data.description,
      billable: data.billable,
      started_at: start.toISOString(),
      ended_at: end.toISOString(),
    };
    if (editing && data.project_id !== originalProjectId.value) {
      payload.task_id = null;
    }
    return payload;
  });

  const opts = {
    onSuccess: () => {
      showManual.value = false;
      editingId.value = null;
      manualForm.reset();
      durationText.value = '1:00';
    },
    preserveScroll: true,
  };

  if (editing) {
    manualForm.patch(`/entries/${editing}`, opts);
  } else {
    manualForm.post('/entries', opts);
  }
}
```

- [ ] **Step 5: Prefill the duration on edit; reset it on cancel**

Replace the `startEdit` function:

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
  // Scroll the page region fully to the top: the form sits just under the sticky
  // page-head, so scrollIntoView would tuck it behind that header. Scrolling the
  // .content scroller to 0 reveals the header and the whole form.
  nextTick(() => formEl.value?.closest('.content')?.scrollTo({ top: 0, behavior: 'smooth' }));
}
```

with:

```js
function startEdit(entry) {
  manualForm.clearErrors();
  manualForm.project_id = entry.project.id;
  manualForm.description = entry.description || '';
  manualForm.date = dateStr(new Date(entry.started_at));
  manualForm.billable = entry.billable;
  durationText.value = formatDuration(Math.round(entry.duration_seconds / 60));
  editingId.value = entry.id;
  originalProjectId.value = entry.project.id;
  originalStartedAt.value = entry.started_at;
  showManual.value = true;
  // Scroll the page region fully to the top: the form sits just under the sticky
  // page-head, so scrollIntoView would tuck it behind that header. Scrolling the
  // .content scroller to 0 reveals the header and the whole form.
  nextTick(() => formEl.value?.closest('.content')?.scrollTo({ top: 0, behavior: 'smooth' }));
}
```

And replace `cancelManual`:

```js
function cancelManual() {
  showManual.value = false;
  editingId.value = null;
  manualForm.clearErrors();
  manualForm.reset();
}
```

with:

```js
function cancelManual() {
  showManual.value = false;
  editingId.value = null;
  manualForm.clearErrors();
  manualForm.reset();
  durationText.value = '1:00';
}
```

- [ ] **Step 6: Swap the From/To inputs for the Duration field in the template**

Replace the whole `<div class="me-grid me-grid--time"> … </div>` block:

```html
    <div class="me-grid me-grid--time">
      <label class="field">
        <span>Date</span>
        <input type="date" v-model="manualForm.date" required class="input" />
      </label>
      <label class="field">
        <span>From</span>
        <input type="time" v-model="manualForm.start_time" required class="input" />
      </label>
      <label class="field">
        <span>To</span>
        <input type="time" v-model="manualForm.end_time" required class="input" />
      </label>
      <label class="field">
        <span>Duration</span>
        <output class="me-dur">{{ durationLabel }}</output>
      </label>
    </div>
```

with:

```html
    <div class="me-grid me-grid--time">
      <label class="field">
        <span>Date</span>
        <input type="date" v-model="manualForm.date" required class="input" />
      </label>
      <label class="field">
        <span>Duration</span>
        <input v-model="durationText" placeholder="1:30" class="input" />
        <span class="me-dur-hint" :class="{ ok: durationMinutes }">{{ durationPreview }}</span>
      </label>
    </div>
```

- [ ] **Step 7: Update the scoped styles**

In the `<style scoped>` block, replace the `.me-grid--time` rule and the `.me-dur` rule:

```css
.me-grid--time {
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  align-items: end;
  margin-top: 16px;
}
/* Live duration readout — echoes the big tabular timer numerals. */
.me-dur {
  min-height: var(--row-h);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  font-size: var(--fs-lg);
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
```

with:

```css
.me-grid--time {
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  align-items: start;
  margin-top: 16px;
}
.me-dur-hint {
  display: block;
  margin-top: 4px;
  font-size: var(--fs-xs);
  color: var(--ink-4);
}
.me-dur-hint.ok { color: var(--ink-3); }
```

And in the `@media (max-width: 760px)` block, replace:

```css
  .me-grid--time { grid-template-columns: 1fr 1fr; }
  .me-dur { justify-content: flex-start; }
```

with:

```css
  .me-grid--time { grid-template-columns: 1fr 1fr; }
```

- [ ] **Step 8: Build**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors; the timer `Today-*.js` bundle is rebuilt.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Timer/Today.vue
git commit -m "feat(timer): manual entry takes a duration instead of from/to"
```

---

## Task 4: Verification

**Files:** none (verification only)

- [ ] **Step 1: Backend guard suites (should be untouched/green)**

Run: `ddev exec php artisan test tests/Feature/Http/EntryControllerTest.php tests/Feature/Http/TimerControllerTest.php tests/Feature/Services/TimerServiceTest.php`
Expected: all PASS (no backend code changed).

- [ ] **Step 2: Manual check** (`ddev launch /timer`)

  - "+ Manual entry": the form shows **Project, Description** then **Date, Duration** (no From/To). Typing `1:30` shows `= 1h 30m`; `90m` → `= 1h 30m`; `1h30` → `= 1h 30m`; `1.5` → `= 1h 30m`. `abc`/empty shows the hint and Save is blocked with the inline error.
  - Saving creates an entry on the chosen date whose row shows the duration (e.g. `1h 30m`) and no time range.
  - Edit a finished entry: the Duration field pre-fills (e.g. `1h 30m`); changing it and saving updates the row's duration and the entry keeps its day position.
  - The live start/stop timer still ticks in the hero and, on stop, the resulting row shows the duration; the running row shows no edit/delete controls.

- [ ] **Step 3: No commit** (verification only). If issues are found, return to the relevant task.

---

## Self-review notes

- **Spec coverage:** duration helper with the specified formats (Task 1) ✓; row shows `1h 30m`, time range removed, grid updated (Task 2) ✓; single flexible duration field + live preview, create/edit synthesis rules, invalid-input inline error, edit prefill (Task 3) ✓; backend untouched, verified by guard suites + build + manual (Task 4) ✓.
- **Naming consistency:** `durationText` (ref) / `durationMinutes` (computed) / `durationPreview` / `originalStartedAt` used consistently across Task 3 steps; `parseDuration`/`formatDuration` signatures match Task 1; `.me-dur-hint`(`.ok`) class matches the template binding.
- **No backend change:** the form still POSTs/PATCHes ISO `started_at`/`ended_at`; controllers, requests, model, services, API, and the ~70 entry tests are unchanged.
