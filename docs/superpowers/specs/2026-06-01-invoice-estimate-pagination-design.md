# Pagination for the invoices & estimates index pages

**Date:** 2026-06-01
**Status:** Approved, ready to implement

## Problem

The invoices and estimates index pages load the entire (filtered) result set in
one query and ship every row to the client. With a few years of history that is
hundreds of rows per page load. Both pages need pagination.

## Decision

Server-side pagination using Laravel's `LengthAwarePaginator`, **50 rows per
page**, rendered as numbered page links with prev/next and a
`Showing {from}–{to} of {total}` line. (Rejected: client-side paging — still
loads every row; cursor pagination — no page numbers/total, contradicts the
chosen style.)

The same pattern applies identically to both resources and establishes the
codebase's first pagination convention (reusable later for Clients).

## Backend

In `InvoiceProjections::index()` and `EstimateProjections::index()`, replace the
terminal `->get()->map(...)` with:

```php
return $q->orderByRaw('COALESCE(issued_on, created_at) DESC')
    ->orderByDesc('id')
    ->paginate(50)
    ->withQueryString()                       // preserves ?filter=&q= on page links
    ->through(fn (Invoice $i) => [ /* same row mapping */ ]);
```

- Return type becomes `LengthAwarePaginator`.
- The existing `id DESC` tiebreaker keeps ordering stable across pages.
- `stats()` and the controllers' `counts` queries are **unchanged** — they remain
  global aggregates, so the summary numbers and tab badges reflect the whole
  table, not the current page.
- Controllers (`InvoiceController::index`, `EstimateController::index`) drop the
  now-redundant `->values()` and pass the paginator straight to Inertia.

## Frontend

- `Invoices/Index.vue` and `Estimates/Index.vue`: the `invoices` / `estimates`
  prop changes from `Array` to `Object`; the `v-for` iterates `…​.data`, and the
  empty-state check becomes `….data.length === 0`.
- New shared **`resources/js/Components/Pagination.vue`**: takes the paginator
  object, renders its `links` array (`‹ Prev 1 2 3 … Next ›`) plus a
  `Showing {from}–{to} of {total}` line, navigating via
  `router.get(link.url, {}, { preserveState: true, preserveScroll: true })`.
  Renders nothing when `last_page === 1`.
- Filter and search visits already omit `page`, so changing a tab or the search
  term resets to page 1 automatically.

## Testing

- Update both controller tests: `->has('invoices', 1, …)` → `->has('invoices.data',
  1, …)`, and assert paginator meta (`invoices.total`, `invoices.per_page` = 50).
- Add one pagination test per resource: seed 51 rows, assert page 1 returns 50
  and `total` = 51, page 2 returns 1.
- TDD throughout (red → green). Per the Inertia manifest gotcha, rebuild assets
  before running the feature tests that assert a page component.

## Blast radius

`app/Support/InvoiceProjections.php`, `app/Support/EstimateProjections.php`,
`app/Http/Controllers/InvoiceController.php`,
`app/Http/Controllers/EstimateController.php`,
`resources/js/Pages/Invoices/Index.vue`,
`resources/js/Pages/Estimates/Index.vue`,
new `resources/js/Components/Pagination.vue`, and the two controller tests. No
schema/migration changes.
