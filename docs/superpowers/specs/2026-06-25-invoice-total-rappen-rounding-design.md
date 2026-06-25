# Invoice total 5-rappen rounding

**Date:** 2026-06-25
**Status:** Approved design

## Problem

Invoice totals are currently exact to the rappen (0.01). Swiss banks and
accounting software frequently round outgoing payments to the nearest 5 rappen
(*Rappenrundung*), so a client paying an invoice of CHF 518.88 may transfer
CHF 518.90. This leaves a 1–4 rappen residual on every such invoice that has to
be cleared in bookkeeping as a rounding difference.

The user has rounded invoice totals to 5 rappen for ~10 years in Harvest and
wants ernte to do the same, eliminating the residual so payments match invoices
exactly.

## Goal

Round each invoice's **grand total** to the nearest 5 rappen, while keeping the
invoice internally consistent and VAT-correct.

## Design

### Rounding rule

Commercial rounding of the grand total, in rappen:

```
total_rounded = round((subtotal + vat) / 5) * 5
rounding      = total_rounded - (subtotal + vat)   // signed, range -2..+2
```

- **Subtotal and VAT are unchanged and remain exact.** Only the total moves, by
  at most 2 rappen.
- VAT is still computed on the exact subtotal (existing behaviour), so VAT
  reporting is unaffected.
- The invoice always reconciles: `subtotal + vat + rounding = total`.

Examples:

| subtotal+vat | rounded total | rounding |
|---|---|---|
| 518.88 | 518.90 | +0.02 |
| 518.86 | 518.85 | −0.01 |
| 518.90 | 518.90 |  0.00 |
| 480.00 | 480.00 |  0.00 |

### Scope decisions

- **Always on.** No setting or toggle. Every invoice rounds its total.
- **No backfill.** Existing invoices are left untouched. Only invoices
  created or edited after this ships are rounded. Existing rows default to
  `rounding_rappen = 0` and keep their stored `total_rappen`.

### Storage

Add one column to the `invoices` table:

- `rounding_rappen` — **signed** integer (`integer`, not `unsignedBigInteger`),
  default `0`. Holds the rounding adjustment (−2..+2 rappen).

`total_rappen` stores the **rounded** total (its meaning shifts from "exact"
to "rounded"; existing rows are unaffected because their stored value stays as
issued).

Update `Invoice` model: add `rounding_rappen` to `$fillable` and cast to
`integer`.

### Computation — single source of truth

Extend `App\Support\LineTotals::compute()` to return the new shape:

```php
return [
    'subtotal_rappen' => $subtotal,
    'vat_rappen'      => $vat,
    'rounding_rappen' => $rounding,   // new
    'total_rappen'    => $subtotal + $vat + $rounding, // now rounded
];
```

All server write paths already funnel through `LineTotals::compute()` (via
`InvoiceBuilder::computeTotals()`), so they pick up the change automatically:

- `InvoiceBuilder::createDraft()` — assign `rounding_rappen` alongside the others.
- `InvoiceController::update()` — assign `rounding_rappen` alongside the others.
- `RecurringInvoiceGenerator::generate()` — delegates to `createDraft()`, no change.

### Frontend live preview

Mirror the same rounding rule so the editor preview matches the rendered PDF:

- `resources/js/formatters/vat.js` (`totalsForLines`) — return `rounding` and a
  rounded `total`.
- `resources/js/Pages/Invoices/Edit.vue` and `Create.vue` — use the rounded
  total and render the new line.

### Display

Add a **"Rundung"** line between MwSt and Total, shown **only when
`rounding_rappen ≠ 0`** (hidden when the total already lands on a 5-rappen
boundary):

- `resources/views/invoices/pdf.blade.php` — totals block.
- `Edit.vue` / `Create.vue` totals block.

`App\Support\InvoiceProjections::detail()` exposes `rounding` (CHF) in the API
response so the iOS companion and any JSON consumers can render it.

### QR-bill

No change required. `QrBillRenderer` already derives the payment amount from
`total_rappen` (`round($invoice->total_rappen / 100, 2)`), which is now the
rounded total — so the scannable amount matches the printed total automatically.

## Testing

Unit tests on `LineTotals::compute()`:

- Rounds up: subtotal+vat ending .88 → .90, `rounding = +2`.
- Rounds down: ending .86 → .85, `rounding = -1`.
- Exact boundary: ending .90 → .90, `rounding = 0`.
- Reconciliation invariant: `subtotal + vat + rounding == total` for a range of
  inputs.

Feature/render tests:

- PDF/editor shows the Rundung line when rounding ≠ 0 and hides it when 0.
- QR-bill amount equals the rounded `total_rappen / 100`.

## Out of scope

- Configurable toggle (decided: always on).
- Backfilling or recomputing existing invoices.
- 5-rappen rounding of anything other than the grand total (lines, subtotal, VAT
  stay exact).
</content>
</invoke>
