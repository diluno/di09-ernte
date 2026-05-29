# Recurring Invoices — Design

**Date:** 2026-05-29
**Status:** Approved (brainstorming) — ready for implementation planning

## Summary

Add recurring invoices to ernte: per-client schedules that carry a fixed-line
invoice template and a cadence, and that generate ordinary `Invoice` rows on a
daily scheduler. Each schedule may default to producing a **draft for review**
or **auto-send** the generated invoice. There is **no machine import from
Harvest** — the historical Harvest invoices merely give the operator the
starting line items/amounts to re-enter by hand.

Generated invoices are indistinguishable from hand-made ones (same numbering,
QR reference, PDF, reminders, lists, lifecycle); the only new link is a
back-reference column on `invoices`.

## Decisions captured during brainstorming

- **Harvest:** no importer. Manual seed only.
- **Generation mode:** per-schedule. Default = generate **draft only**; opt-in
  **auto-send** (creates + emails + marks sent, reusing the manual send path).
- **Cadences:** `monthly`, `quarterly`, `half-yearly`, `yearly`. No weekly /
  arbitrary-N.
- **Line items:** **fixed template lines** (flat-fee / retainer / hosting).
  No time-entry pulling. (The manual builder already covers time-and-materials.)
- **Anchor & period:** anchor day-of-month; period = the calendar period
  (month / quarter / half / year) **containing the run date** → advance billing.
- **End condition:** runs until paused. No end date or occurrence count.
- **Title:** template string with a literal `{period}` placeholder, substituted
  per cycle.

## 1. Data model

### `recurring_invoices` (new table)

| column | type | notes |
|---|---|---|
| `id` | id | |
| `client_id` | FK → clients | `restrictOnDelete` |
| `project_id` | nullable FK → projects | `nullOnDelete`; cosmetic / reporting |
| `title` | nullable string | may contain the literal `{period}` placeholder |
| `notes` | nullable text | |
| `currency` | char(3) | default `CHF` |
| `vat_rate` | decimal(5,2) | snapshotted from profile default at creation, editable |
| `cadence` | enum | `monthly` / `quarterly` / `half-yearly` / `yearly` |
| `anchor_day` | unsignedTinyInteger | 1–31; day-of-month to generate, clamped to month length |
| `next_run_on` | date | the next occurrence date to generate |
| `last_generated_on` | nullable date | |
| `auto_send` | boolean | default `false` |
| `paused_at` | nullable timestamp | pause = set it; `null` = active |
| timestamps | | |

Indexes: `next_run_on`, `client_id`.

### `recurring_invoice_lines` (new table)

Mirrors `invoice_lines` minus the invoice FK:

`id`, `recurring_invoice_id` (FK, `cascadeOnDelete`), `description` (text),
`hours` (decimal 10,2), `rate_rappen` (unsignedBigInteger), `vat_exempt`
(bool, default false), `sort_order` (unsignedInteger, default 0), timestamps.
Index `['recurring_invoice_id', 'sort_order']`.

**Amount is not stored** on the template — it is recomputed (`hours × rate`) at
generation time, consistent with `InvoiceBuilder` never trusting client math.

### `invoices` (alter)

Add `recurring_invoice_id` — nullable FK → `recurring_invoices`,
`nullOnDelete`. Deleting a schedule leaves its past invoices intact and simply
nulls the back-reference.

### Models

- `RecurringInvoice` — `belongsTo` client/project; `hasMany` lines; `hasMany`
  invoices (the generated ones). Casts for dates/bool/decimal. Helper accessors:
  `isPaused()`, scope `due($date)` (= `whereNull('paused_at')->whereDate('next_run_on', '<=', $date)`).
- `RecurringInvoiceLine` — `belongsTo` recurring invoice.
- `Invoice` — add `recurringInvoice()` `belongsTo` and `recurring_invoice_id` to
  `$fillable`.

## 2. Generation engine

### `BillingPeriod` (pure helper / value object)

`App\Support\BillingPeriod` (or `App\Services\Invoicing\BillingPeriod`).

`for(string $cadence, Carbon $date): array` returns
`['start' => Carbon, 'end' => Carbon, 'label' => string]` where the period is
the **calendar period containing `$date`**:

| cadence | period | label |
|---|---|---|
| monthly | calendar month | `June 2026` |
| quarterly | calendar quarter | `Q2 2026` |
| half-yearly | calendar half (Jan–Jun / Jul–Dec) | `H1 2026` / `H2 2026` |
| yearly | calendar year | `2026` |

Also exposes `advance(string $cadence, Carbon $date): Carbon` — the next
occurrence: step the period by its length (1 / 3 / 6 / 12 months), then set the
day to `min(anchor_day, daysInMonth)` for the resulting month so the anchor day
never drifts after a short month (e.g. day 31 → Feb → back to 31 in March).
`anchor_day` is passed in (or `advance` takes it as an argument).

### `RecurringInvoiceGenerator` (service)

`generate(RecurringInvoice $schedule, Carbon $runDate): Invoice`

1. Compute period via `BillingPeriod::for($schedule->cadence, $runDate)`.
2. Interpolate `{period}` in `$schedule->title` with the period label.
3. Map `recurring_invoice_lines` → the `$lines` array shape `InvoiceBuilder::createDraft` expects.
4. Call `InvoiceBuilder::createDraft($client, $project, period.start, period.end, $lines, entryIds: [], title: $interpolated, notes: $schedule->notes)`.
5. Stamp `recurring_invoice_id` on the new invoice.
6. If `$schedule->auto_send`: call `InvoiceLifecycle::issue($invoice)` inside a
   try/catch. On `DomainException` (e.g. client has no email), leave the invoice
   as a **draft** and log an `invoice_event` `recurring_autosend_skipped` with
   the reason, so it surfaces in the drafts list.
7. Advance: `$schedule->next_run_on = BillingPeriod::advance(...)` (clamped to
   `anchor_day`); set `last_generated_on = $runDate`; save.

Wrapped so a per-schedule failure is logged and does not abort the whole run.

### `GenerateRecurringInvoicesCommand`

Signature `ernte:invoices:generate-recurring`. Scheduled **daily** in
`routes/console.php` (alongside the existing remind / stamp-overdue / backup
jobs).

```
foreach (RecurringInvoice::due(today)->with('lines','client','project') as $schedule) {
    while (! $schedule->paused_at && $schedule->next_run_on <= today) {
        $generator->generate($schedule, $schedule->next_run_on);  // advances next_run_on
        $schedule->refresh();
    }
}
```

The `while` loop is the **catch-up**: a few missed scheduler days still produce
every due cycle exactly once. Emits an info summary (`generated N invoice(s)
across M schedule(s); skipped K auto-sends`).

### No backfill of history

When a schedule is **created**, `next_run_on` is initialised to the **next
occurrence on/after today** (never a past period) — even if the operator picks
an anchor day earlier in the month. Creating a schedule never retroactively
bills past months. On **resume**, `next_run_on` is likewise snapped forward to
the next future occurrence.

## 3. Reuse — invoice land is untouched

Generated invoices use the existing `InvoiceNumberer` (drafts already consume a
number on creation), `QrReferenceGenerator`, PDF rendering, reminder command,
list highlighting, and `InvoiceLifecycle`. The only schema addition anywhere
else is the `recurring_invoice_id` column.

## 4. UI (Inertia / Vue)

- **Sidebar:** new **Recurring** nav entry beside Invoices / Estimates.
- **`Pages/RecurringInvoices/Index.vue`** — table: client, title, cadence, next
  run date, auto-send badge, paused badge, count of generated invoices. Row
  actions: edit, pause / resume, delete, **Generate now**.
- **`Pages/RecurringInvoices/Create.vue` / `Edit.vue`** — mirror the
  `Invoices/Create` line editor: client select, title (with `{period}` hint),
  cadence select, anchor day, VAT rate, auto-send toggle, fixed line rows
  (description / hours / rate / vat-exempt) with running totals.
- **`Pages/Invoices/Show.vue`** — when `recurring_invoice_id` is set, show a
  small "Generated from recurring schedule →" link.

### Controller & routes

`RecurringInvoiceController` with `index / create / store / edit / update /
destroy` plus `pause`, `resume`, and `run` (manual single-occurrence generate),
routed in `routes/web.php` under the `auth` group, mirroring the estimates
routes. Validation via a form request (client exists, cadence in enum,
anchor_day 1–31, at least one line, etc.).

## 5. Error handling & edge cases

- **Auto-send with no client email / QR not configured:** invoice stays draft,
  `recurring_autosend_skipped` event logged. Never silently lost.
- **Short months:** `anchor_day` clamped to `daysInMonth` for the target month;
  the stored `anchor_day` is preserved so it springs back the next long month.
- **Delete schedule:** generated invoices kept (`nullOnDelete`).
- **Pause / resume:** pause stops generation; resume snaps `next_run_on` to the
  next future occurrence (no backfill).
- **Per-schedule failure** during the daily run is caught and logged; other
  schedules still run.

## 6. Testing

**Unit**
- `BillingPeriod::for` labels & bounds for all four cadences (incl. `H1/H2`).
- `BillingPeriod::advance` + anchor-day clamping (day 31 → February → March).
- `RecurringInvoiceGenerator`: lines copied & amounts recomputed, totals correct,
  `{period}` interpolation, `recurring_invoice_id` stamped, auto-send success
  path, auto-send skip-on-no-email path, `next_run_on` / `last_generated_on`
  advanced.

**Feature**
- Command generates due schedules, skips paused, **catches up** multiple missed
  periods, and **never backfills** before `next_run_on`.
- CRUD + pause / resume / run controller tests.
- Inertia page-render tests for the new pages — these require the new
  `RecurringInvoices/*.vue` to be present in the **Vite manifest**, or they 500
  (known ernte test gotcha). The plan must build assets before these tests.

## 7. Suggested plan split

Per the sub-plan preference, split into two independently-shippable pieces:

- **(i) Backend** — migrations, models, `BillingPeriod`, `RecurringInvoiceGenerator`,
  the command + schedule entry. Shippable & exercisable via the artisan command
  and tinker, fully unit/feature tested without UI.
- **(ii) UI** — controller, routes, form request, Vue pages, sidebar entry, the
  `Invoices/Show` back-link.
