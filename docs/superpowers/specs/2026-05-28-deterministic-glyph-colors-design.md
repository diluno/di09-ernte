# Deterministic glyph colors — design

**Date:** 2026-05-28
**Status:** Approved, ready for implementation plan

## Problem

The colored badge ("glyph") shown next to each client and project is chosen
inconsistently:

- **Projects** store a `glyph` column (`alt-0`…`alt-4`) chosen via a manual
  dropdown in the create/edit forms.
- **Clients** have no stored glyph; `Clients/Index.vue` colors each row by its
  position in the list (`glyphFor(i) = [...][i % 5]`), so a client's color is
  unstable — it changes when the list is filtered/sorted or rows are added or
  archived. `Clients/Show.vue` hardcodes the client badge to `alt-0`, so it
  rarely matches the list.

Goal: make the badge color **deterministic and stable** for both clients and
projects, derived from the record's immutable `id`, computed on the frontend.
Remove the manual picker and the entire stored-glyph backend.

## Decisions

- **Color key:** the immutable database `id`. Stable for the record's lifetime;
  unaffected by renames or code changes.
- **Cleanup scope:** full removal — drop the `projects.glyph` column and all
  glyph plumbing. Color is purely computed on the frontend.
- **Picker:** removed from BOTH `Projects/Create.vue` and `Projects/Edit.vue`
  (a deterministic color makes a manual choice meaningless).

## Shared util

New `resources/js/glyph.js`:

```js
export function glyphClass(id) {
  return `alt-${Math.abs(Number(id)) % 5}`;  // alt-0 … alt-4
}
```

`alt-0` is the base `.proj-glyph` color (accent); `alt-1`…`alt-4` are CSS
overrides that already exist in `resources/css/base.css`. The function is pure
and deterministic.

## Frontend — render sites

All six `.proj-glyph` render sites import `glyphClass` and key off `id`:

| File | Element | New binding |
|------|---------|-------------|
| `Clients/Index.vue:89` | client rows | `glyphClass(c.id)` (delete `glyphFor`) |
| `Clients/Show.vue:47` | client badge | `glyphClass(client.id)` (was `alt-0`) |
| `Clients/Show.vue:100` | project rows | `glyphClass(project.id)` |
| `Projects/Index.vue:123` | project rows | `glyphClass(p.id)` |
| `Projects/Show.vue:66` | project badge | `glyphClass(project.id)` |
| `Timer/Today.vue:153` | project | `glyphClass(p.id)` |

Each record already exposes `id` at its render site (used elsewhere on the same
row/page). The implementation must verify this per site and add `id` to any
payload that lacks it.

## Frontend — remove the picker

In `Projects/Create.vue` and `Projects/Edit.vue`:
- Remove the glyph `<select>` markup.
- Remove `glyph` from the `useForm({...})` initial state.
- Remove the `glyph` line from the submit `transform`.

## Backend — full removal of stored glyph

- **Migration:** drop the `glyph` column from `projects`.
- **`app/Models/Project.php`:** remove `'glyph'` from `$fillable`.
- **`StoreProjectRequest` / `UpdateProjectRequest`:** remove the `glyph` rule.
- **Payloads:** remove the `glyph` key from `app/Support/ProjectDetail.php`,
  `app/Support/DashboardProjections.php`, `app/Support/SidebarProps.php`
  (running-entry block + pinned `get([...])` column list and map),
  `app/Support/ClientDetail.php` (both project-row and running-entry blocks),
  `app/Support/TimerToday.php` (map entry + `select(...)` column list), and
  `app/Http/Controllers/ProjectController.php@edit`.
- **`app/Services/Harvest/ProjectImporter.php`:** remove the `'glyph' => '▦'`
  assignment.
- **`database/factories/ProjectFactory.php`** and any seeder that sets `glyph`:
  remove the `glyph` attribute so factory-created records don't reference a
  dropped column.

The implementation must grep `app/`, `database/`, and `tests/` for every
remaining `glyph` reference (excluding the unrelated `.nav-item .glyph` nav
icons) and remove each.

## Testing (TDD, `ddev artisan test`)

- A migration/schema test asserting the `projects` table has **no** `glyph`
  column (`Schema::hasColumn('projects', 'glyph')` is false).
- The existing project create/store/edit/factory tests pass after the column,
  validation, and factory `glyph` are removed (the store test no longer posts
  `glyph`).
- No Inertia payload test asserts `glyph` (grep-confirm; fix any that do).
- Frontend: this codebase has no JS test runner, so `glyphClass` and the render
  changes are verified by a clean `ddev npm run build` plus manual QA — the same
  approach used for the prior project-CRUD frontend work.

## Out of scope

- The nav-item icons (`.nav-item .glyph`) — unrelated hardcoded nav glyphs.
- Changing the 5-color palette or the `.proj-glyph` CSS.
- Storing a per-record color (the approved approach is purely computed).
