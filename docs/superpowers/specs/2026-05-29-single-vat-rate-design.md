# Single Temporal VAT Rate + Rate Editor

**Date:** 2026-05-29
**Status:** Approved design, pending implementation plan

## Summary

The commit `8606719 Add dated VAT rate handling` introduced a **multi-code** temporal
VAT system (codes `standard` / `reduced` / `special` / `exempt`, with per-line VAT
snapshots and a per-line VAT picker). The business only ever uses the single Swiss
standard rate — never reduced, special, or exempt.

This work **simplifies the system to a single temporal VAT rate** (one rate active at a
time, with validity-period history so rate changes like 7.70% → 8.10% are handled), and
adds a **dedicated editor** at `/settings/vat-rates` to manage that rate over time.

The simplification is the larger part of the work; the editor is small once the model is
single-rate.

## Decisions

- **Single rate, with history.** One VAT rate is active on any given date. Multiple rows
  exist only to represent rate changes over time (non-overlapping validity windows).
- **No codes, no labels, no default flag.** With one active rate there is nothing to pick
  and nothing to flag as default. Swiss invoices display "MwSt {rate}%" — no per-rate
  label is needed.
- **`vat_exempt` removed entirely.** No line is ever exempt; every line is taxed at the
  document's rate.
- **Forward migrations.** The multi-code migrations committed today are left in history; new
  migrations drop the now-unused columns and collapse the seeded catalog. Existing data is
  preserved.
- **Document `vat_rate` stays the source of truth** for a document's rate (it predates the
  multi-code commit). Stored `vat_rappen` / `total` already freeze historical totals.

## Data model

### `vat_rates` catalog (forward migration)
Final shape: `id`, `rate` (decimal 5,2), `valid_from` (date), `valid_until` (date,
nullable), `timestamps`.

- Drop columns `code`, `label`, `is_default`.
- Drop the old indexes (`unique(code, valid_from)`, `(is_default, valid_from)`,
  `(code, valid_from, valid_until)`).
- Add `unique(valid_from)`.
- Collapse seeded rows: delete every non-`standard` row before dropping `code`, leaving the
  two standard periods (7.70% for 2018-01-01 → 2023-12-31, 8.10% from 2024-01-01).
- **Invariant the app enforces (application-level, not a DB constraint): no two rows have
  overlapping `[valid_from, valid_until]` windows.**

### Document line tables (forward migration)
`invoice_lines`, `estimate_lines`, `recurring_invoice_lines`:

- Drop `vat_code`, `vat_label`, and the per-line `vat_rate` snapshot added by the commit.
- Drop the `(vat_code, vat_rate)` index.
- Drop `vat_exempt` (predates the commit, but no longer used).

### Document tables — unchanged
`invoices` / `estimates` / `recurring_invoices` keep their `vat_rate` and `vat_rappen`
columns. The document's `vat_rate` applies uniformly to all its lines.

### `business_profile` — unchanged
`default_vat_rate` stays as the fallback when the catalog has no row covering a date.

## Model & service layer

### `App\Models\VatRate`
Collapse to:

- `forDate(Carbon|string|null $date = null): self` — the rate row active on the date;
  falls back to an in-memory row using `BusinessProfile::default_vat_rate` when none covers
  the date.
- `rateForDate(Carbon|string|null $date = null): float` — convenience returning the numeric
  rate.
- `catalogForFrontend(): Collection` — all rows as `{rate, valid_from, valid_until}`,
  ordered by `valid_from`.

Remove: `defaultForDate`, `snapshotFor`, `optionsForDate`, `labelForCode`, the code/exempt
branches, and the `(code, label, is_default)` from `$fillable` / `$casts`.

### `*Line` models
Remove `vat_exempt`, `vat_code`, `vat_label`, `vat_rate` from `$fillable` and `$casts` on
`InvoiceLine`, `EstimateLine`, `RecurringInvoiceLine`.

### `App\Support\LineTotals`
Collapse to a single-rate computation:

- `compute(array $lineAmounts, float $vatRate): array` returning
  `{subtotal_rappen, vat_rappen, total_rappen}` where
  `subtotal = Σ amounts`, `vat = round(subtotal × rate / 100)`, `total = subtotal + vat`.
- Remove `computeFromRates`, `vatBreakdown`, and the `vatExempts` parameter.

### Builders / generators / lifecycle
- `InvoiceBuilder`, `EstimateBuilder`: snapshot the document `vat_rate` from
  `VatRate::forDate($taxDate)`; drop all per-line snapshot logic and the `vat_exempt`
  handling. `computeTotals` delegates to the new single-rate `LineTotals::compute`.
- `RecurringInvoiceGenerator`: stop mapping per-line vat_code/vat_exempt; generated invoice
  inherits the document rate.
- `EstimateLifecycle::convertToInvoice`: stop carrying per-line vat_code/vat_exempt; the new
  invoice re-snapshots the document rate at conversion date.

### Harvest importers
`EstimateImporter`, `InvoiceImporter`: keep setting the document `vat_rate` from the Harvest
tax field; drop per-line `vat_exempt`/`vat_code`/`vat_label`/`vat_rate`. If a Harvest line
item is untaxed, emit the existing-style warning rather than marking it exempt (consistent
with "no exempt lines").

## Frontend

### `resources/js/formatters/vat.js`
Collapse to:

- `vatRateForDate(catalog, date)` — numeric rate active on the date (fallback 0 / passed
  default).
- `lineAmountRappen(line)` — unchanged.
- `totalsForLines(lines, catalog, date)` — `{subtotal, vat, total}` using the single active
  rate (no per-rate breakdown array).

Remove `activeVatRates`, `defaultVatCode`, `vatRateForCode`, `vatLabelForCode`, `validOn`.

### Document forms
`Invoices/Create`, `Estimates/Create`, `Estimates/Edit`, `RecurringInvoices/Create`,
`RecurringInvoices/Edit`:

- Remove the per-line VAT-code `<select>` and the `vat_code` / `vat_exempt` line fields.
- Show the applicable rate read-only near the totals (e.g. "MwSt 8.10%"), derived from the
  tax/run date via `vatRateForDate`.
- Real-time totals via the simplified `totalsForLines`.

### PDFs
`invoices/pdf.blade.php`, `estimates/pdf.blade.php`: render a single
`MwSt {rate}%` total line computed from the document `vat_rate`; remove the per-rate
breakdown loop and the per-line "(MwSt-befreit)" annotation.

## VAT rate editor

### Routes (RESTful, follows `Route::resource` convention)
```
GET    /settings/vat-rates           VatRateController@index    (Inertia page)
POST   /settings/vat-rates           VatRateController@store
PATCH  /settings/vat-rates/{vatRate} VatRateController@update
DELETE /settings/vat-rates/{vatRate} VatRateController@destroy
```
Linked from the existing Settings page.

### Page — `Settings/VatRates.vue`
Table listing all rate rows (rate %, valid-from, valid-until), ordered by `valid_from`.
Inline per-row edit + delete, plus a blank "add rate" row. Each row saves/deletes
individually (REST per-row, clean per-row validation errors).

### Validation & guardrails (`StoreVatRateRequest` / `UpdateVatRateRequest`)
- `rate` — required, numeric, `0–100`, 2 decimals.
- `valid_from` — required date.
- `valid_until` — nullable date, must be `≥ valid_from`.
- **No overlapping validity windows** — a new/edited row's `[valid_from, valid_until]` must
  not overlap any other row's window (open-ended `valid_until = null` extends to infinity).
  Rejected with a friendly message (not a raw DB error). On update, the row being edited is
  excluded from the overlap check.
- Delete — frontend `confirm()` warning that existing documents are unaffected (they keep
  their snapshot) but the rate stops applying to new documents.

## Testing

- **`LineTotalsTest`** — rewrite for the single-rate `compute`; drop exempt / multi-rate
  breakdown cases.
- **`InvoiceBuilderTest`, `EstimateBuilderTest`** — drop `vat_exempt` and per-line-code
  assertions; assert document `vat_rate` is snapshotted from the dated catalog and totals
  follow the single rate.
- **`RecurringInvoiceGeneratorTest`** — assert generated invoice inherits the document rate;
  no per-line VAT.
- **`InvoiceImporterTest`, `EstimateImporterTest`** — drop exempt assertions; assert document
  `vat_rate` from Harvest.
- **PDF renderer tests** — assert single `MwSt {rate}%` line.
- **New `VatRateControllerTest`** — index/store/update/destroy; reject `valid_until <
  valid_from`; reject overlapping windows; overlap check excludes the edited row.
- **Inertia manifest** — ensure `Settings/VatRates.vue` is built into the Vite manifest so
  the page feature test does not 500 (known project gotcha).

## Out of scope

- Multiple concurrent VAT codes / reduced / special / exempt rates (explicitly removed).
- Retroactively changing historical document totals (frozen via stored `vat_rappen`).
- Per-line VAT overrides.
