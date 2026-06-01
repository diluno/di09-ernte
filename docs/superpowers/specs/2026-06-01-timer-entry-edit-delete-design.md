# Timer entry edit & delete — design

**Date:** 2026-06-01
**Status:** Approved, ready for planning

## Problem

On the Timer "Today" page, logged time entries are read-only. There is no way to
correct a wrong description/time/project or remove a mistaken entry from the UI.

## Key finding: the backend already exists

The mutation endpoints, validation, ownership checks, and tests are already in
place — this is a **frontend-only** feature.

- `PATCH /entries/{entry}` → `EntryController@update` (uses `UpdateEntryRequest`)
- `DELETE /entries/{entry}` → `EntryController@destroy`
- `UpdateEntryRequest::authorize()` returns 403 unless `entry.user_id === user.id`;
  `destroy` does the same via `abort_if`.
- `UpdateEntryRequest` rules: `project_id` (exists), `task_id` (nullable, must
  belong to the project), `description` (nullable, ≤500), `started_at` (date),
  `ended_at` (nullable, after `started_at`), `billable` (boolean) — all `sometimes`.
- `EntryControllerTest` already covers update, delete, and cross-user 403.

No backend changes are required.

## Scope

Add edit and delete controls to today's entry rows. Out of scope: bulk actions,
editing entries from other days/pages, a task picker in the form, undo.

## UX decisions (from brainstorming)

1. **Edit** reuses the existing "Manual entry" form in an *edit mode* — pre-filled
   with the entry's values; saving sends a PATCH. One form serves create and edit.
2. **Delete** uses the native `window.confirm('Delete this entry?')` dialog, then DELETE.
3. **Running entry**: the in-progress row (`entry.running === true`) shows **no**
   edit/delete controls. It is managed by the timer controls above (stop/discard).
   This sidesteps the conflict that the edit form requires an end time.

## Components & data flow

### `EntryRow.vue` (presentational)

- Adds an actions cell with two icon buttons: `✎` (edit) and `✕` (delete).
- Buttons render only when `!entry.running`; running rows leave the cell empty so
  the grid stays aligned.
- Stays dumb — emits `edit(entry)` and `delete(entry)` to the parent. No routing or
  `confirm()` inside the row.

### `Today.vue` (owns all logic — already owns `manualForm` / `showManual`)

- New `editingId = ref(null)`.
- `startEdit(entry)`:
  - Fills `manualForm` from the entry: `project_id`, `description`, `billable`, and
    `date` / `start_time` / `end_time` derived from `started_at` / `ended_at` using
    the existing `dateStr` / `timeStr` helpers (on local `Date` objects).
  - Sets `editingId = entry.id`, opens the form (`showManual = true`), and scrolls
    the form into view.
- `submitManual` branches on `editingId`:
  - **edit:** `router.patch('/entries/' + editingId.value, payload, { preserveScroll: true })`
  - **create:** existing `POST /entries` behaviour.
  - Same `composeRange()` transform builds `started_at` / `ended_at` ISO strings.
  - On success: clear `editingId`, reset the form, close it.
- `deleteEntry(entry)`:
  `if (window.confirm('Delete this entry?')) router.delete('/entries/' + entry.id, { preserveScroll: true })`
- `cancelManual` also clears `editingId`.
- Form chrome reflects mode: title `Manual entry` → `Edit entry`; submit button
  `Save entry` → `Save changes`; header toggle button hidden or repurposed while editing.

### `task_id` handling on edit

The form has no task picker, but timer-started entries can carry a `task_id`.

- If the project is **unchanged**, do not send `task_id` (PATCH leaves it untouched).
- If the project **changed** from the entry's original project, send `task_id: null`
  so we never leave an entry whose task belongs to a different project.

This requires `startEdit` to remember the entry's original `project_id` (and its
`task_id` is irrelevant beyond the project-change check). The entry payload already
includes `project.id`; `task_id` itself is not currently in the payload but is not
needed client-side under this rule.

### Grid / CSS

Add a right-hand actions column to `.entry-row` in `resources/css/base.css`
(current template: `12px 1fr 90px 80px 80px 40px`). Append a column for the actions
so icons align; running rows render an empty cell there.

## Error handling

- Validation errors from PATCH surface in the same `manual-entry__err` block the
  create flow already uses (`manualForm.errors`).
- 403 (another user's entry) cannot occur through normal UI — entries shown belong
  to the current user — but the server still enforces it.

## Testing & verification

- Backend behaviour is already covered by `EntryControllerTest`; no new backend
  logic is added, so no new backend tests are strictly required. Optionally add a
  guard asserting the today payload exposes `running` per entry (the flag the row
  uses to hide controls) if not already covered.
- No JS/component test harness exists in this repo. Verify the client behaviour by
  building (`npm run build`) and exercising edit + delete in the running app:
  edit a finished entry (description/time/project/billable), confirm the PATCH
  persists after reload; delete an entry via the confirm dialog; confirm the
  running row shows no controls.
