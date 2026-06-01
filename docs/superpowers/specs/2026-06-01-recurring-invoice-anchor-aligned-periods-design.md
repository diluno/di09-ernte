# Recurring invoices: anchor-aligned billing periods

**Date:** 2026-06-01
**Status:** Approved, ready to plan/implement
**Supersedes:** the "advance billing = calendar period containing the run date" decision in `docs/superpowers/specs/2026-05-29-recurring-invoices-design.md`.

## Problem

A yearly schedule anchored on 1 June 2026 generated an invoice whose period
displayed as `PERIODE 01.01. – 31.12.2026`. That is the *calendar* year
containing the run date — the behavior originally specced — but it is wrong for
true advance billing: billing on 1 June should cover the **upcoming** twelve
months, `01.06.2026 – 31.05.2027`.

The original rule (`BillingPeriod::for()` returns the calendar period — month /
quarter / half / year — that contains the run date) only matches expectations
when the anchor coincides with a calendar boundary (e.g. monthly on the 1st). For
any other anchor it bills for a window that is partly in the past.

## Decision

The period an invoice covers runs **from its run date (the anchor) forward one
full cadence**, i.e. `[run date, day before the next run]`. This applies to
**all** cadences (monthly, quarterly, half-yearly, yearly) for a single coherent
definition of advance billing.

For monthly anchored on the 1st this is identical to today; it only changes
anchors that sit off a calendar boundary.

## New behavior

`advance()` already computes the next run date with correct anchor-day clamping
(Feb / short months spring back), so the period end reuses it:

```
BillingPeriod::for(string $cadence, Carbon $runDate, int $anchorDay): array
  start = $runDate
  end   = advance($cadence, $runDate, $anchorDay)->subDay()
  label = monthYearRange(start, end)
```

| Cadence   | Anchor       | Period start → end          | `{period}` label          |
|-----------|--------------|-----------------------------|---------------------------|
| monthly   | 1 Jun 2026   | 01.06.2026 → 30.06.2026     | `Juni 2026`               |
| monthly   | 15 Jun 2026  | 15.06.2026 → 14.07.2026     | `Juni 2026 – Juli 2026`   |
| quarterly | 1 Apr 2026   | 01.04.2026 → 30.06.2026     | `April 2026 – Juni 2026`  |
| yearly    | 1 Jun 2026   | 01.06.2026 → 31.05.2027     | `Juni 2026 – Mai 2027`    |

### `{period}` label rule

- If `start` and `end` fall in the same calendar month → single `MMMM YYYY`.
- Otherwise → `MMMM YYYY – MMMM YYYY`.
- The old `Q2 2026` / `H1 2026` / bare-year labels are removed; they do not
  generalize to arbitrary anchors.
- Month names render in **German** (`->locale('de')->translatedFormat('F Y')`) to
  match the German invoice, even though the app default locale is `en`.

The PDF "Periode" line is unchanged — it already renders `period_start –
period_end` (`d.m.` – `d.m.Y`), which now reads e.g. `01.06. – 31.05.2027`.

## Components touched

- `app/Support/BillingPeriod.php` — rewrite `for()` to the forward definition
  with the new `$anchorDay` parameter; drop the now-unused `half()` helper; leave
  `advance()`, `months()`, `nextRunOnOrAfter()` untouched. `for()` has no callers
  outside the generator and tests, so the signature change is safe.
- `app/Services/Invoicing/RecurringInvoiceGenerator.php:33` — pass
  `$schedule->anchor_day` into `for()`.

## Testing

- `tests/Unit/Support/BillingPeriodTest.php` — rewrite the `for()` assertions for
  forward periods and range labels (incl. same-month single-label case, and
  anchor-day clamping into a short month).
- `tests/Feature/Services/RecurringInvoiceGeneratorTest.php` — update the
  `period_start`/`period_end` expectations and the `{period}` title assertion
  (e.g. quarterly title becomes `Hosting — April 2026 – Juni 2026`).

## One-off production data fix

After the code ships, correct the already-generated yearly invoice in place via
SSH: set `period_start` / `period_end` to the anchor-aligned span and, if its
title used `{period}`, re-interpolate the new label. (It is still a draft / safe
to amend.)
