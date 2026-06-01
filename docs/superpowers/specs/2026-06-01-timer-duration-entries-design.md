# Duration-based time entries — design

**Date:** 2026-06-01
**Status:** Approved, ready for planning

## Problem

Time entries are entered and shown as From/Until clock times (`09:00 – 10:30`). The
user wants them to be expressed as a **duration / length of time** (`1h 30m`) instead.
The live start/stop timer stays.

## Key finding: this is a frontend-only change

Duration is already *computed* from `started_at`/`ended_at` (`TimeEntry::getDurationSecondsAttribute`),
and the live timer needs a start timestamp to tick. So we **keep `started_at`/`ended_at`
as the stored source of truth** and the live timer untouched; only the UI changes:

- The manual-entry/edit form takes a duration instead of From/To.
- The entry row shows a duration instead of the time range.

The form still POSTs `started_at`/`ended_at` (synthesized from the duration), so
`StoreEntryRequest`/`UpdateEntryRequest`, the controllers, `TimerService`, the live
timer (`TimerHero`/`useTimer`), `TimerToday`/`SidebarProps` aggregation, invoicing,
the API, and all existing tests are **unchanged**.

## Scope

Change the manual-entry/edit form and the row display on the Timer "Today" page to be
duration-based. Out of scope: schema changes, removing `started_at`/`ended_at`, the live
timer, dropping the start/stop flow, any backend change.

## Decisions (from brainstorming)

- **Keep the live start/stop timer** and keep timestamps stored under the hood (invisible).
- **Duration input:** a single flexible text field with a live parsed preview (`= 1h 30m`).
- **Row display:** show duration as `1h 30m` (no time range).

## Architecture

### New helper: `resources/js/formatters/duration.js`

- `parseDuration(str): number | null` — total **minutes**, or `null` if unparseable or ≤ 0.
  Accepts: `"1:30"` (h:mm), `"1h 30m"` / `"1h30"` / `"1h"` / `"30m"` / `"90m"`,
  decimal hours `"1.5"` / `"1.5h"`, and a bare integer treated as minutes is **not**
  desired — a bare number is treated as **hours** (so `"2"` = 2h, `"1.5"` = 1h30m), matching
  the decimal-hours intuition. Whitespace-insensitive; case-insensitive.
- `formatDuration(minutes): string` — `"1h 30m"`, `"2h"`, `"45m"`, `"0m"` for 0.

These are pure functions, unit-testable in isolation (though the repo has no JS test
harness; see Testing).

### Manual-entry & edit form (`resources/js/Pages/Timer/Today.vue`)

- Remove the **From** (`start_time`) and **To** (`end_time`) inputs and the read-only
  duration `<output>`. Keep **Project**, **Description**, **Date**, **Billable**.
- Add a single **Duration** text input bound to a `durationText` ref, with a live preview
  computed via `parseDuration` → `formatDuration` (e.g. `= 1h 30m`), or a muted hint when
  it doesn't parse.
- Replace `composeRange()` with duration-based synthesis:
  - **Create:** `started_at` = the selected `date` at the **current wall-clock time**;
    `ended_at` = `started_at + durationMinutes`. (Times are invisible; this keeps the entry
    on its date for the today/week buckets, and `duration_seconds` = the entered duration.)
  - **Edit:** preserve the entry's **existing `started_at`**; set `ended_at` =
    `started_at + durationMinutes` so the entry keeps its place in the day. `startEdit`
    pre-fills `durationText` from the entry's `duration_seconds`.
  - Both still produce ISO `started_at`/`ended_at` (via `Date` → `toISOString()`) and POST/
    PATCH them exactly as today — no backend change.
- **Validation:** if `parseDuration` returns null, block submit and show an inline message
  in the existing `manual-entry__err` block (e.g. "Enter a valid duration, e.g. 1:30 or 90m").
- Drop the now-unused `start_time`/`end_time` form fields and the `durationLabel`/`composeRange`
  helpers; add `durationText`.

### Row display (`resources/js/Components/EntryRow.vue` + `resources/css/base.css`)

- Remove the `.time` cell (the `09:00 – 10:30 / now` range).
- Show the duration formatted as `1h 30m` via `formatDuration(Math.round(entry.duration_seconds / 60))`
  (replacing the `fmtHM` `HH:MM` rendering). The running entry shows its current elapsed
  snapshot; the live ticking clock remains in `TimerHero`.
- Update the `.entry-row` grid in `base.css`: remove the 90px time column —
  `12px 1fr 90px 80px 80px 56px` → `12px 1fr 80px 80px 56px`.

## Error handling / edge cases

- **Unparseable duration:** submit blocked, inline error; the preview shows the hint.
- **Duration crossing midnight on create** (late-day start + long duration): `ended_at`
  rolls into the next day; `duration_seconds` (= ended − started) stays correct and the
  today bucket keys on `started_at`, so the entry stays on its date. Acceptable.
- **Running entry:** unaffected — still created by `TimerService` with `started_at=now`,
  `ended_at=null`; it shows no edit/delete controls and its row shows the elapsed snapshot.

## Testing

- Backend is untouched → existing suites stay green (run the timer/entry suites as a guard).
- No JS test harness exists, so `parseDuration`/`formatDuration` and the form are verified
  by `npm run build` + manual checks:
  - Enter `1:30`, `90m`, `1h30`, `1.5` → saved entry has the right `duration_seconds` and
    lands on the chosen date.
  - Edit an entry's duration → its duration updates and it keeps its day position.
  - The live start/stop timer still ticks and, on stop, the row shows the resulting duration.
  - Invalid input (`abc`, empty) is rejected with the inline message.
