# Project create / edit / archive UI — design

**Date:** 2026-05-28
**Status:** Approved, ready for implementation plan

## Problem

Projects can be listed and viewed, but a user cannot create, edit, or archive
them through the UI. The backend mostly exists — `ProjectController@store`,
`@update`, and `@archive` are implemented and routed — but nothing reaches them:

- `Projects/Index.vue` has a "+ New project" button that is `disabled` ("Phase 2b").
- There is no `Projects/Create.vue` or `Projects/Edit.vue`.
- `Projects/Show.vue` has no Edit or Archive button; its Settings tab is a
  disabled placeholder.

Clients, by contrast, has the full, working pattern (Create page, Edit page with
an archive button). This feature wires Projects up by mirroring that pattern,
and adds an unarchive path so archiving is reversible.

## Decisions

- **Edit lives on a dedicated `/projects/{code}/edit` page** (mirror Clients),
  not in the Show page's Settings tab. This keeps consistency with the existing
  codebase and avoids building the currently-inert Show tab system.
- **Create lives on a dedicated `/projects/create` page** (mirror Clients).
- **Archive + unarchive:** the edit page shows an "Archive" button when the
  project is active and an "Unarchive" button when archived (each with a
  confirm). The Projects index already has an "Archived" filter, so unarchive
  closes the loop.
- **Core fields only:** the form omits the retainer fields (`retainer`,
  `retainer_hours`, `retainer_resets_monthly`), which keep their defaults.
  `StoreProjectRequest` treats them as `sometimes`/`nullable`, so omitting them
  is safe.
- **No hard delete** — projects archive only, like clients.

## Backend

`ProjectController` already has `store()`, `update()`, `archive()`, `pin()`,
`unpin()`. Add:

- **`create(): Response`** — `Inertia::render('Projects/Create', ['clients' => …])`
  where `clients` is the active clients as `{id, name}` for the picker.
- **`edit(Project $project): Response`** — `Inertia::render('Projects/Edit', […])`
  passing the project's editable fields with money converted rappen→CHF
  (`rate` = `rate_rappen/100`, `budget_amount` = `budget_amount_rappen/100`) and
  the active-clients list. Mirrors how `ProjectDetail::payload()` exposes CHF.
- **`unarchive(Project $project): RedirectResponse`** — `update(['status' => 'active'])`,
  `return back()`.
- **`update()`** — change the redirect from `back()` to the project Show page
  (`redirect("/projects/{$project->code}")`) using the post-update code, so a
  changed code does not strand the user on a stale edit URL. The existing test
  only asserts `assertRedirect()` (any redirect), so it remains green.

### Routes (`routes/web.php`)

Add to the existing project route block:

- `GET  /projects/create`                  → `create`   (name `projects.create`)
- `GET  /projects/{project:code}/edit`     → `edit`     (name `projects.edit`)
- `POST /projects/{project:code}/unarchive`→ `unarchive`(name `projects.unarchive`)

**Ordering constraint:** `GET /projects/create` MUST be declared before
`GET /projects/{project:code}`, otherwise the literal segment "create" is bound
as a project code and 404s.

Existing routes unchanged: `index`, `store`, `show`, `update` (PATCH by `{project}`),
`archive`, `pin`, `unpin`.

## Frontend

### `Projects/Index.vue`
Enable the "+ New project" button: replace the disabled `<button>` with
`<Link href="/projects/create" class="btn primary">+ New project</Link>`.

### `Projects/Create.vue` (new)
Form mirroring `Clients/Create.vue` structure/styling. Fields:

- **Client** — `<select>` over the `clients` prop (`client_id`), required.
- **Name** — text, required.
- **Code** — text, required, `maxlength=32`.
- **Glyph** — `<select>` with options `alt-0`…`alt-4`, required.
- **Description** — textarea, optional.
- **Billable** — checkbox (default true).
- **Budget hours** — number, required.
- **Budget amount (CHF)** — number, required.
- **Rate (CHF/h)** — number, required.
- **Started on** / **Deadline on** — date inputs, optional.

On submit, transform money CHF→rappen (`rate_rappen = Math.round(rate*100)`,
`budget_amount_rappen = Math.round(budget_amount*100)`) and `budget_hours` to a
Number, then `form.post('/projects')`. Show `form.errors.*` per field.

### `Projects/Edit.vue` (new)
Same form, prefilled from the `project` prop (money already in CHF from `edit()`).
Submits via `form.patch('/projects/' + project.id)`. Below the form, an
archive/unarchive control:

- If `project.status === 'active'`: an "Archive" button that, after
  `confirm(...)`, POSTs to `/projects/{code}/archive`.
- If `project.status === 'archived'`: an "Unarchive" button that POSTs to
  `/projects/{code}/unarchive`.

Use Inertia `router.post(...)` with the project `code`, mirroring how
`Clients/Edit.vue` performs its archive action.

### `Projects/Show.vue`
Add an "Edit" button to the header button group:
`<Link :href="`/projects/${project.code}/edit`" class="btn">Edit</Link>`.

## Testing (TDD, `ddev artisan test`)

- `GET /projects/create` renders `Projects/Create` with a `clients` array of
  `{id, name}`.
- `GET /projects/{code}/edit` renders `Projects/Edit` with the project (assert
  `rate` and `budget_amount` are in CHF, not rappen) and a `clients` array.
- `PATCH /projects/{id}` with a changed `code` redirects to the new
  `/projects/{newcode}` and persists the change; unique-ignoring-self still
  allows saving without changing the code.
- `POST /projects/{code}/unarchive` flips an archived project's `status` to
  `active` and redirects.
- Existing `store`, `archive`, and `update` tests remain green.

(Vue pages have no automated component tests in this codebase — verified via the
Inertia prop assertions above plus a clean `ddev npm run build` and manual QA.)

## Out of scope

- The Show page's other tabs (Entries / Tasks / Team / Settings) — unchanged.
- Hard delete.
- The mock's header "Import" button — stays disabled.
- Retainer fields in the form.
