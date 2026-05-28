# Pinned projects — design

**Date:** 2026-05-28
**Status:** Approved, ready for implementation plan

## Problem

The sidebar has a "Pinned" section, but it is not pinning. It auto-fills with the
4 active projects that have the most recent time-entry activity, computed live on
every request (`app/Support/SidebarProps.php::pinnedProjects()`). There is no
`pinned` storage, no UI control, and no way for the user to choose what appears.
The label promises user intent; the behavior is automatic recency.

This feature replaces the heuristic with explicit, user-controlled pinning.

## Decisions

- **Section behavior:** "Pinned" shows *only* projects the user has explicitly
  pinned. Empty state until the user pins something. Recency is already covered
  by the separate "Recent" section (a localStorage list of the last visited
  project/client pages in `Sidebar.vue`), which is unchanged.
- **Pin control location:** the Projects/Show page header only — a single,
  discoverable source, next to the existing archive action.
- **Cap:** none. Any number of projects may be pinned.
- **Ordering:** alphabetical by project name.
- **Archive:** archiving a project auto-unpins it (clears the pin).

## Data model

Add a nullable `pinned_at` timestamp to the `projects` table. A project is
"pinned" iff `pinned_at IS NOT NULL`. A timestamp (rather than a boolean) costs
nothing extra and records *when* a project was pinned, leaving room for
pin-order semantics later without a second migration.

- Migration: `add_pinned_at_to_projects` — `timestamp('pinned_at')->nullable()`.
- `Project` model: cast `pinned_at => datetime`; add `scopePinned()`
  (`whereNotNull('pinned_at')`).

## Backend

### `SidebarProps::pinnedProjects()`

Replace the current leftJoin + groupBy recency query with:

```php
Project::active()
    ->whereNotNull('pinned_at')
    ->orderBy('name')
    ->select('projects.id', 'projects.name', 'projects.code', 'projects.glyph')
    ->get()
    ->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'code' => $p->code,
        'glyph' => $p->glyph,
    ])
    ->all();
```

This also retires the `ONLY_FULL_GROUP_BY` footgun flagged in the Phase-2b
carryover (the old query ordered by a column not in the GROUP BY).

### Routes

Mirror the existing `POST /projects/{project:code}/archive` pattern:

- `POST /projects/{project:code}/pin`   → `ProjectController@pin`
- `POST /projects/{project:code}/unpin` → `ProjectController@unpin`

### Controller

- `pin()` — set `pinned_at = now()`, redirect back.
- `unpin()` — set `pinned_at = null`, redirect back.
- `archive()` — additionally clear `pinned_at` (auto-unpin on archive).
- `show()` — include `is_pinned` (boolean derived from `pinned_at`) in the
  project payload so the header toggle can render the correct state.

## Frontend

### `Projects/Show.vue`

Add a Pin/Unpin toggle button to the page header, next to the archive action.
It posts via Inertia `router.post` to the `pin`/`unpin` route depending on
current state. Label reflects state — e.g. "Pin project" when unpinned,
"Pinned ✓" (acting as unpin) when pinned. Consumes the `is_pinned` prop.

### `Sidebar.vue`

- Empty-state copy: "No projects yet" → "No pinned projects".
- Keep the colored-dot styling. Since the pin count is now arbitrary, cycle the
  existing 4-color palette by index using `i % 4` so any number of pins renders
  cleanly (the current code indexes the palette directly, which would yield
  `undefined` past index 3).

## Edge cases

- Archiving auto-unpins, so the sidebar can never reference an archived project.
  The sidebar query still scopes to `active()` as belt-and-suspenders.
- Unpinned and archived projects never appear in "Pinned".
- Zero pins → "No pinned projects" empty state.

## Testing (TDD, `ddev artisan test`)

- `SidebarProps`: only pinned **and** active projects appear; ordered
  alphabetically; unpinned projects excluded; archived projects excluded.
- `pin` route sets `pinned_at` and redirects back; `unpin` clears it.
- `archive` clears `pinned_at`.
- `Projects/Show` payload exposes `is_pinned` reflecting the stored state.

## Out of scope

- Pin reordering / drag-and-drop.
- Pinning from the Projects index, sidebar hover, or command palette.
- A cap on the number of pins.
- Pinning clients or any entity other than projects.
