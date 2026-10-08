# Project notes — design

**Date:** 2026-10-08
**Status:** Draft, awaiting review

## Problem

A project has one short `description` field and nothing else to write in. There
is nowhere to keep what accumulates over a project's life: what was agreed on a
call, why a decision went the way it did, where the staging login lives, what to
bring up at the next invoice. That ends up in other apps, detached from the
project it belongs to.

## Decisions

- **Shape:** a dated log. Many notes per project, each with its own timestamp,
  newest first. Not a single scratchpad field.
- **Formatting:** Markdown, rendered server-side by the existing
  `App\Support\Markdown::toHtml()` (escapes raw HTML, keeps single line breaks,
  blocks unsafe links). No new JS dependency.
- **Surfaces:** the web project page and ⌘K search. No `/api` (iOS) exposure and
  no MCP tools in this round.
- **Date:** a note's date is its `created_at`. No backdating, no manual date
  field.
- **`description` stays** as it is: the one-line summary in the sidebar of the
  project page. Notes do not replace it.

## Data model

New table `project_notes`:

| column       | type                                             |
|--------------|--------------------------------------------------|
| `id`         | bigint PK                                        |
| `project_id` | FK → `projects.id`, `cascadeOnDelete`            |
| `body`       | `text`, not null                                 |
| timestamps   | `created_at`, `updated_at`                       |

Index on `(project_id, created_at)`.

`App\Models\ProjectNote` (`fillable`: `project_id`, `body`) with
`project()`; `Project::notes()` is a `hasMany` ordered newest first.

## Routes

Inside the existing `auth` group in `routes/web.php`:

- `POST   /projects/{project:code}/notes` → `ProjectNoteController@store`
- `PATCH  /project-notes/{projectNote}` → `ProjectNoteController@update`
- `DELETE /project-notes/{projectNote}` → `ProjectNoteController@destroy`

All three `return back()`, like `TaskController`. Validation (one shared rule):
`body` is `required|string|max:20000`. An empty textarea arrives as `null`
(`ConvertEmptyStringsToNull`) and fails `required`, which is the wanted result.

## Project page payload

`ProjectDetail::payload()` gains:

```php
'notes' => [
    ['id' => 7, 'body' => '…raw markdown…', 'body_html' => '<p>…</p>',
     'created_at' => '2026-10-08T14:02:11+02:00', 'edited' => false],
    …
],
```

Newest first, all of them (no pagination: one operator, tens of notes per
project at most). `edited` is true when `updated_at` is later than `created_at`.
`counts.notes` is added next to `counts.entries` and `counts.tasks`.

## UI

A new `resources/js/Components/ProjectNotes.vue`, placed in the main column of
`Projects/Show.vue` between **Tasks** and **Recent entries**.

- **Composer** on top: an `AutoTextarea` with placeholder "Add a note… (markdown)"
  and an "Add note" button. ⌘/Ctrl+Enter submits. On success the field clears;
  scroll position is preserved.
- **List** below: one block per note with a meta line (date and time, "edited"
  when it applies, then Edit and Delete as quiet text buttons) and the rendered
  markdown body via `v-html`.
- **Edit** swaps that note's body for an `AutoTextarea` with Save / Cancel
  (Escape cancels). One note is in edit mode at a time.
- **Delete** asks `window.confirm('Delete this note?')`, as entry deletion does.
- **Empty state:** "No notes yet" in the muted style used by Recent entries.
- Each note block has `id="note-{id}"`. When the page opens with a `#note-{id}`
  hash, that note is scrolled into view and briefly highlighted.

Markdown typography (`.note-body` paragraphs, lists, links, inline code, tables)
is added to `resources/css/base.css` using the existing tokens. The Projects
pages are still on pre-Ledger markup; this section follows what is there and
gets reworked with the rest of the page when Projects gets its Ledger slice.

## ⌘K search

`SearchController` gets a `notes()` source:

- Only when the query is non-empty and no `type` filter is set (so the timer's
  project switcher, which sends `type=project`, never sees notes).
- Matches `project_notes.body LIKE %q%`, newest first, at most 2.
- Result: `type: 'note'`, `label` = project name, `sublabel` = a one-line excerpt
  around the match (`Str::excerpt`, whitespace collapsed), `url` =
  `/projects/{code}#note-{id}`.
- The other sources already produce up to 10 rows for 8 slots, so notes are
  given room: the mixed list is cut to `8 - count(notes)` before notes are
  appended.

The palette placeholder becomes "project, client, invoice, note…".

## Out of scope

- Notes on clients, invoices or estimates.
- `/api` exposure for the iOS app; MCP tools.
- Attachments, tags, pinning a note, backdating.
- Full-text indexing (LIKE is enough at this size).

## Risks

- `v-html` is only as safe as `Markdown::toHtml()`. A test pins that a
  `<script>` in a note body comes back escaped in `body_html`.
