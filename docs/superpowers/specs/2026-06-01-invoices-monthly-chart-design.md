# Invoices monthly chart — design

**Date:** 2026-06-01
**Status:** Approved, ready for planning

## Problem

The Invoices index page has no at-a-glance view of how much was invoiced over the
year. Add a stacked monthly bar chart — Open vs Paid amounts per month — with
prev/next year navigation, matching the supplied reference (title "Invoices issued
in {year}", Open/Paid legend, CHF y-axis gridlines, 12 month bars).

## Scope

A monthly issued-amount chart on `Pages/Invoices/Index.vue`, fed by a new
server-side aggregation. Out of scope: changing the invoice table/stats/filters,
exporting the chart, drill-down/click-through, a charting library.

## Decisions (from brainstorming)

- **Year navigation is chart-only.** The ← / → arrows change only the chart's year
  via an Inertia partial reload. The stats strip, filter tabs, search, and table are
  untouched (they behave exactly as today).
- **Placement:** between the stats strip and the filter/tab row.
- **Segments:** **Paid** = `status = 'paid'`; **Open** = `status = 'sent'` (unpaid;
  overdue is just sent-past-due, so it folds into Open). `draft` and `void` excluded.
- **Bucketing:** by `issued_on` month ("invoices issued in {year}"). A paid invoice
  counts in the month it was issued.
- **Amount:** gross `total_rappen` (incl. VAT) ÷ 100, matching the CHF y-axis.
- **Stack order:** Paid (dark) on the bottom, Open (lighter) stacked on top.

## Architecture

### Server: aggregation (`app/Support/InvoiceProjections.php`)

Add a static method:

```php
public static function monthlyIssued(int $year): array
```

Returns:

```php
[
  'year'     => 2026,
  'min_year' => 2024,   // earliest issued-invoice year, or $year if none — back-arrow bound
  'max_year' => 2026,   // current calendar year — forward-arrow bound
  'months'   => [        // always 12 entries, Jan..Dec
    ['label' => 'Jan', 'open' => 0.0, 'paid' => 24000.0],
    // ...
  ],
]
```

Implementation notes:
- One grouped query: `where('status', in ['sent','paid'])`, `whereYear('issued_on', $year)`,
  `selectRaw("MONTH(issued_on) as m, status, SUM(total_rappen) as cents")`,
  `groupBy('m', 'status')`. Build 12 month buckets in PHP, summing `paid` into `paid`
  and `sent` into `open`, converting rappen → CHF (÷ 100, float).
- `min_year`: `min(YEAR(issued_on))` over `status in ['sent','paid']` invoices; if none,
  fall back to `$year`. `max_year`: current calendar year.

### Controller (`app/Http/Controllers/InvoiceController@index`)

- Read `chart_year` from the request: `$chartYear = (int) $request->input('chart_year', now()->year);`
- Add an `invoiceChart` prop: `'invoiceChart' => InvoiceProjections::monthlyIssued($chartYear)`.
- No clamping needed: `monthlyIssued` always returns the global `min_year`/`max_year`
  bounds regardless of the requested year, so an out-of-range `chart_year` simply yields
  12 empty months while the arrows (bounded by min/max) keep the UI in range.
- This prop is additive; existing props (`invoices`, `stats`, `counts`, `filters`)
  are unchanged.

### Data flow for year navigation

The chart emits a year change; `Index.vue` fires an Inertia partial reload:

```js
router.reload({
  only: ['invoiceChart'],
  data: { chart_year: newYear },
  preserveState: true,
  preserveScroll: true,
});
```

`only: ['invoiceChart']` means only the chart prop is re-serialized; the table/filter
state is preserved. No new route or JSON endpoint — the existing `/invoices` GET handles it.

### Components

**New `resources/js/Components/InvoiceBarChart.vue`** — presentational, hand-rolled
inline SVG (follows the `Sparkline.vue` pattern; no charting library).

- Props: `year` (Number), `minYear` (Number), `maxYear` (Number),
  `months` (Array of `{ label, open, paid }`).
- Emits: `update:year` (Number).
- Renders:
  - Header row: title `Invoices issued in {{ year }}`, a ← button (disabled when
    `year <= minYear`) and → button (disabled when `year >= maxYear`), and a right-aligned
    legend (Open swatch + label, Paid swatch + label).
  - Plot: a fixed-height SVG using a `viewBox` so it scales to container width.
    - Y scale: `max` = largest `(open + paid)` across months; if 0, use a sensible
      floor (e.g. 1000) so the axis renders. Round `max` up to a "nice" step
      (`niceCeil`): pick a step from {1k,2k,2.5k,5k,10k,…} giving ~4–5 gridlines.
    - Gridlines + left CHF labels at each step (`CHF` + `de-CH` thousands, e.g. `CHF25'000`)
      via `formatChf` from `@/formatters/money.js` (0 fraction digits).
    - 12 bars across the x-axis with month labels beneath. Each bar: a Paid rect from
      the baseline up, an Open rect stacked above it. Zero-height segments render nothing.
- Colors via CSS custom properties on the component:
  - Paid: `var(--forest)`.
  - Open: a lighter tint — `color-mix(in srgb, var(--forest) 45%, var(--paper))`.
  - Gridlines/labels: `var(--border)` / `var(--ink-3)`.

**`resources/js/Pages/Invoices/Index.vue`**

- Accept the new `invoiceChart` prop.
- A `chartYear` ref initialised from `invoiceChart.year`.
- Render `<InvoiceBarChart>` between the stats strip and the filter row, passing the
  prop's `year/min_year/max_year/months`, and handling `@update:year` with the partial
  reload above (also updating `chartYear`). When the reload returns, the prop updates
  and the chart re-renders.

## Error handling / edge cases

- **Year with no issued invoices:** all bars zero; the axis still renders (y floor).
- **No invoices at all:** `min_year == max_year == current year`; both arrows disabled.
- **Out-of-range `chart_year`:** clamped to `[min_year, max_year]` in the controller.
- The chart shows nothing clickable; failures are limited to the read query.

## Testing

- **Backend (Pest), `tests/Feature/Support/InvoiceMonthlyChartTest.php`** (or extend an
  existing InvoiceProjections test file):
  - Buckets a sent and a paid invoice into the correct month's `open`/`paid` (CHF).
  - Excludes `draft` and `void`.
  - Buckets by `issued_on`, not `paid_at` (a paid invoice issued in Jan counts in Jan).
  - Returns 12 months even when most are empty.
  - Respects `$year` (an invoice in a different year is excluded).
  - `min_year` = earliest issued year; `max_year` = current year; both = current year
    when there are no invoices.
- **Backend, `tests/Feature/Http/InvoiceControllerTest.php`** (extend):
  - `/invoices` passes an `invoiceChart` prop with the expected shape.
  - `/invoices?chart_year=2025` returns chart data for 2025 (and a partial reload with
    `only=invoiceChart` works).
- **Frontend:** no JS test harness exists; the SVG component is verified by
  `npm run build` + manual check. Data correctness is covered by the backend tests.
