# Invoice & estimate lists: newest-first + year — design

**Date:** 2026-05-28
**Status:** Approved, ready for implementation plan

## Problem

The Invoices and Estimates list pages:

1. Sort by `orderByDesc('id')` (database insertion order). After the one-time
   Harvest import, id order does not match document chronology, so historical
   documents appear out of date order.
2. Render the Issued / Due / Valid-until date cells as `"28 May"` — no year —
   via an inline `fmtDate` duplicated in each page. With multi-year imported
   data, the year is not visible in the date columns.

Goal: sort both lists newest-first by document date, and show the year in the
date cells.

## Decisions

- **Sort key:** `COALESCE(issued_on, created_at) DESC`, with `id DESC` as a
  stable tiebreaker. Issued documents sort by their issue date; drafts (no
  `issued_on`) fall back to `created_at` and bubble to the top.
- **Date format:** `"28 May 2026"` — day, short month, full 4-digit year.
- **Shared formatter:** extract the duplicated inline `fmtDate` into one shared
  `resources/js/formatters/date.js` (alongside the existing `money.js` /
  `glyph.js`), used by both list pages.

## Backend

In `app/Support/InvoiceProjections.php` and `app/Support/EstimateProjections.php`,
the `index()` query currently ends with `->orderByDesc('id')->get()`. Change the
ordering to:

```php
->orderByRaw('COALESCE(issued_on, created_at) DESC')
->orderByDesc('id')
->get()
```

No row fields change. `created_at` already exists on both tables. (MariaDB
`COALESCE` over a `date` and a `datetime` column compares correctly for
ordering.)

## Frontend

Create `resources/js/formatters/date.js`:

```js
export function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}
```

In `resources/js/Pages/Invoices/Index.vue` and
`resources/js/Pages/Estimates/Index.vue`:

- Remove the local `function fmtDate(d) { ... }` (the day/month-only version).
- Import the shared one: `import { fmtDate } from '@/formatters/date.js';`.
- Bump the date column header widths from `100px` to `120px` (the Issued / Due
  columns on invoices; Issued / Valid-until on estimates) so `"28 May 2026"`
  does not wrap.

The number columns already display the year (e.g. `#2026-001`,
`#OF-2026-001`) and are unchanged.

## Testing (TDD, `ddev artisan test`)

Add one ordering test to each of `tests/Feature/Support/InvoiceProjectionsTest.php`
and `tests/Feature/Support/EstimateProjectionsTest.php`:

- Create three documents for one client where issue-date order differs from
  insertion (id) order — e.g. insert an invoice issued 2025, then one issued
  2026, then a **draft** (no `issued_on`) created now.
- Assert `index()` returns them ordered: draft first (newest `created_at`), then
  the 2026-issued, then the 2025-issued — confirming
  `COALESCE(issued_on, created_at) DESC`.

Frontend: no JS test runner exists, so the shared `fmtDate` and the column-width
tweaks are verified by a clean `ddev npm run build` plus manual QA (consistent
with prior frontend work).

## Out of scope

- Show pages and any other date displays — unchanged (the shared formatter is
  available for them to adopt later).
- Adding user-selectable sort controls — the order is fixed newest-first.
- Changing the number format (already contains the year).
