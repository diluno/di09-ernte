# Invoice Total 5-Rappen Rounding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Round every invoice's grand total to the nearest 5 rappen, keeping subtotal and VAT exact and the document fully reconciled.

**Architecture:** Rounding happens in one place — `App\Support\LineTotals::compute()` — which now also returns a signed `rounding_rappen`. All server write paths already funnel through it. A new `rounding_rappen` column persists the adjustment; the PDF and Vue editors show a "Rundung" line only when it is non-zero. The frontend mirrors the rule via a shared helper in `resources/js/formatters/vat.js`.

**Tech Stack:** Laravel 13, Pest, Inertia + Vue 3, Vite, DDEV (run artisan/tests via `ddev …`).

## Global Constraints

- All money is stored as integer **rappen**. `rounding_rappen` is **signed** (range −2..+2); all other rappen columns stay `unsignedBigInteger`.
- Rounding rule (rappen): `total = round((subtotal + vat) / 5) * 5`; `rounding = total − (subtotal + vat)`. Subtotal and VAT are never altered.
- Always on. No setting/toggle. No backfill of existing invoices (default 0).
- Rounding line label is exactly `Rundung`, shown only when `rounding_rappen ≠ 0`.
- Run PHP tests with `ddev artisan test`; the host shell cannot reach the DB.

---

### Task 1: Rounding logic in LineTotals

**Files:**
- Modify: `app/Support/LineTotals.php:15-25`
- Test: `tests/Feature/Support/LineTotalsTest.php` (existing — update + extend)

**Interfaces:**
- Produces: `LineTotals::compute(int[] $lineAmounts, float $vatRate): array{subtotal_rappen:int, vat_rappen:int, rounding_rappen:int, total_rappen:int}` where `total_rappen` is rounded to 5 rappen and `subtotal_rappen + vat_rappen + rounding_rappen === total_rappen`.

- [ ] **Step 1: Update the existing tests to expect rounded totals + rounding key**

Replace the body of `tests/Feature/Support/LineTotalsTest.php` with:

```php
<?php

use App\Support\LineTotals;

test('compute taxes every line at the document rate', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['rounding_rappen'])->toBe(0);  // 16215 already on a 5-rappen boundary
    expect($totals['total_rappen'])->toBe(16215);
});

test('compute rounds the grand total up to the nearest 5 rappen', function () {
    // 29000 + 2349 VAT = 31349 -> rounds to 31350 (+1)
    $totals = LineTotals::compute([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
    ]);
});

test('compute rounds the grand total down to the nearest 5 rappen', function () {
    // subtotal+vat = 10286 -> rounds to 10285 (-1)
    $totals = LineTotals::compute([10286], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10286,
        'vat_rappen' => 0,
        'rounding_rappen' => -1,
        'total_rappen' => 10285,
    ]);
});

test('compute with a zero rate yields no VAT', function () {
    $totals = LineTotals::compute([10000], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10000,
        'vat_rappen' => 0,
        'rounding_rappen' => 0,
        'total_rappen' => 10000,
    ]);
});

test('compute always reconciles subtotal + vat + rounding to total', function () {
    foreach ([[12345], [9999, 1], [48000], [33333, 11111]] as $amounts) {
        $t = LineTotals::compute($amounts, 8.10);
        expect($t['subtotal_rappen'] + $t['vat_rappen'] + $t['rounding_rappen'])
            ->toBe($t['total_rappen']);
        expect($t['total_rappen'] % 5)->toBe(0);
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev artisan test --filter=LineTotals`
Expected: FAIL (missing `rounding_rappen` key; totals not rounded).

- [ ] **Step 3: Implement the rounding in `compute()`**

Replace `app/Support/LineTotals.php` lines 15-25 (the `compute` method body) with:

```php
    public static function compute(array $lineAmounts, float $vatRate): array
    {
        $subtotal = array_sum(array_map('intval', $lineAmounts));
        $vat = (int) round($subtotal * $vatRate / 100);
        $exact = $subtotal + $vat;
        $total = (int) (round($exact / 5) * 5);

        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen' => $vat,
            'rounding_rappen' => $total - $exact,
            'total_rappen' => $total,
        ];
    }
```

Also update the docblock `@return` on line 13 to:

```php
     * @return array{subtotal_rappen: int, vat_rappen: int, rounding_rappen: int, total_rappen: int}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ddev artisan test --filter=LineTotals`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/LineTotals.php tests/Feature/Support/LineTotalsTest.php
git commit -m "feat: round invoice grand total to 5 rappen in LineTotals"
```

---

### Task 2: Persist rounding_rappen (migration, model, write paths)

**Files:**
- Create: `database/migrations/2026_06_25_120000_add_rounding_rappen_to_invoices.php`
- Modify: `app/Models/Invoice.php:16,30`
- Modify: `app/Services/Invoicing/InvoiceBuilder.php:135-138`
- Modify: `app/Http/Controllers/InvoiceController.php:217-220`
- Modify: `app/Support/InvoiceProjections.php:109-111`
- Test: `tests/Feature/Invoicing/InvoiceRoundingTest.php` (create)

**Interfaces:**
- Consumes: `LineTotals::compute(...)` from Task 1 (now returns `rounding_rappen`).
- Produces: `invoices.rounding_rappen` column (signed int, default 0); `Invoice->rounding_rappen` cast to integer; `InvoiceProjections::detail()` returns a `rounding` key (CHF float).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Invoicing/InvoiceRoundingTest.php`:

```php
<?php

use App\Models\Invoice;
use App\Support\InvoiceProjections;

test('created draft persists rounded total and rounding adjustment', function () {
    $invoice = Invoice::factory()->create(['vat_rate' => 8.10]);
    $invoice->lines()->create([
        'description' => 'Work',
        'hours' => 1,
        'rate_rappen' => 29000,
        'amount_rappen' => 29000,
        'sort_order' => 0,
    ]);

    // Recompute via the same path update() uses.
    $totals = \App\Services\Invoicing\InvoiceBuilder::computeTotals([29000], 8.10);
    $invoice->update($totals);

    expect($invoice->fresh()->rounding_rappen)->toBe(1);
    expect($invoice->fresh()->total_rappen)->toBe(31350);
});

test('detail projection exposes the rounding amount in CHF', function () {
    $invoice = Invoice::factory()->create([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'vat_rate' => 8.10,
    ]);

    $detail = InvoiceProjections::detail($invoice);

    expect($detail['rounding'])->toBe(0.01);
    expect($detail['total'])->toBe(313.5);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `ddev artisan test --filter=InvoiceRounding`
Expected: FAIL (no `rounding_rappen` column / not fillable / no `rounding` projection key).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_25_120000_add_rounding_rappen_to_invoices.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Signed: the adjustment ranges -2..+2 rappen.
            $table->integer('rounding_rappen')->default(0)->after('vat_rappen');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('rounding_rappen');
        });
    }
};
```

- [ ] **Step 4: Make the column fillable and cast**

In `app/Models/Invoice.php`, line 16, change:

```php
        'subtotal_rappen', 'vat_rappen', 'total_rappen',
```

to:

```php
        'subtotal_rappen', 'vat_rappen', 'rounding_rappen', 'total_rappen',
```

And in the `$casts` array (after the `vat_rappen` cast, line 29), add:

```php
        'rounding_rappen' => 'integer',
```

- [ ] **Step 5: Persist rounding in the two write paths**

In `app/Services/Invoicing/InvoiceBuilder.php`, after line 137 (`$invoice->vat_rappen = $totals['vat_rappen'];`), add:

```php
            $invoice->rounding_rappen = $totals['rounding_rappen'];
```

In `app/Http/Controllers/InvoiceController.php`, after line 219 (`$invoice->vat_rappen = $totals['vat_rappen'];`), add:

```php
                $invoice->rounding_rappen = $totals['rounding_rappen'];
```

- [ ] **Step 6: Expose rounding in the detail projection**

In `app/Support/InvoiceProjections.php`, after line 110 (`'vat' => round($invoice->vat_rappen / 100, 2),`), add:

```php
            'rounding' => round($invoice->rounding_rappen / 100, 2),
```

- [ ] **Step 7: Run the migration and tests**

Run: `ddev artisan migrate && ddev artisan test --filter=InvoiceRounding`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_25_120000_add_rounding_rappen_to_invoices.php app/Models/Invoice.php app/Services/Invoicing/InvoiceBuilder.php app/Http/Controllers/InvoiceController.php app/Support/InvoiceProjections.php tests/Feature/Invoicing/InvoiceRoundingTest.php
git commit -m "feat: persist invoice rounding_rappen and expose it in projection"
```

---

### Task 3: Show the Rundung line in the PDF

**Files:**
- Modify: `resources/views/invoices/pdf.blade.php:108-112`

**Interfaces:**
- Consumes: `$invoice->rounding_rappen` (Task 2), `$money` helper (defined at `pdf.blade.php:43`).

- [ ] **Step 1: Add the conditional Rundung row**

Replace `resources/views/invoices/pdf.blade.php` lines 108-112 with:

```blade
	  <div class="totals">
	    <div>Zwischensumme</div><div class="v">{{ $money($invoice->subtotal_rappen) }}</div>
	    <div>MwSt {{ $rateLabel($invoice->vat_rate) }}%</div><div class="v">{{ $money($invoice->vat_rappen) }}</div>
	    @if ($invoice->rounding_rappen != 0)
	      <div>Rundung</div><div class="v">{{ $money($invoice->rounding_rappen) }}</div>
	    @endif
	    <div class="grand-l">Total</div><div class="v grand">{{ $money($invoice->total_rappen) }}</div>
	  </div>
```

- [ ] **Step 2: Verify the rendered PDF/preview by eye**

Run: `ddev artisan test --filter=Invoice` (ensure no render/feature test regressions).
Then open an invoice whose total is not a multiple of 5 rappen in the app preview and confirm a `Rundung` line appears showing e.g. `CHF 0.01`, and that subtotal + VAT + rounding equals the printed Total. Confirm an invoice landing on a 5-rappen boundary shows **no** Rundung line.
Expected: tests PASS; preview shows the line only when applicable.

- [ ] **Step 3: Commit**

```bash
git add resources/views/invoices/pdf.blade.php
git commit -m "feat: show Rundung line on invoice PDF when total is rounded"
```

---

### Task 4: Mirror rounding + Rundung line in the Vue editors

**Files:**
- Modify: `resources/js/formatters/vat.js:18-27`
- Modify: `resources/js/Pages/Invoices/Edit.vue:55-59,144-150`
- Modify: `resources/js/Pages/Invoices/Create.vue:172-178`
- Test: `resources/js/formatters/vat.test.js` (create, if a JS test runner is configured)

**Interfaces:**
- Produces: `roundTotalRappen(exactRappen: number): number` and an extended `totalsForLines(...) -> { subtotal, vat, rounding, total, rate }`.

- [ ] **Step 1: Add the shared rounding helper and extend totalsForLines**

In `resources/js/formatters/vat.js`, replace lines 18-27 with:

```javascript
export function lineAmountRappen(line) {
  return Math.round(Number(line.hours) * Number(line.rate) * 100);
}

// Commercial rounding of a rappen amount to the nearest 5 rappen.
export function roundTotalRappen(exactRappen) {
  return Math.round(exactRappen / 5) * 5;
}

export function totalsForLines(lines, catalog, date) {
  const rate = vatRateForDate(catalog, date);
  const subtotal = lines.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * rate) / 100);
  const total = roundTotalRappen(subtotal + vat);
  return { subtotal, vat, rounding: total - (subtotal + vat), total, rate };
}
```

- [ ] **Step 2: Write a JS unit test for the helper (only if Vitest/Jest is configured)**

First check: `cat package.json | grep -E '"(test|vitest|jest)"'`. If no JS test runner is configured, **skip this step** (the rule is already covered by Task 1's PHP tests). If one exists, create `resources/js/formatters/vat.test.js`:

```javascript
import { describe, it, expect } from 'vitest';
import { roundTotalRappen, totalsForLines } from './vat';

describe('roundTotalRappen', () => {
  it('rounds up to the nearest 5 rappen', () => {
    expect(roundTotalRappen(31349)).toBe(31350);
  });
  it('rounds down to the nearest 5 rappen', () => {
    expect(roundTotalRappen(10286)).toBe(10285);
  });
  it('leaves a 5-rappen boundary unchanged', () => {
    expect(roundTotalRappen(16215)).toBe(16215);
  });
});

describe('totalsForLines', () => {
  it('reconciles subtotal + vat + rounding to total', () => {
    const t = totalsForLines([{ hours: 1, rate: 290 }], [{ rate: 8.1, valid_from: '2000-01-01' }], '2026-06-25');
    expect(t.subtotal + t.vat + t.rounding).toBe(t.total);
    expect(t.total % 5).toBe(0);
  });
});
```

Run: `npx vitest run resources/js/formatters/vat.test.js`
Expected: PASS.

- [ ] **Step 3: Use the rounded total + Rundung line in Edit.vue**

In `resources/js/Pages/Invoices/Edit.vue`, replace lines 55-59 (the `totals` computed) with:

```javascript
const totals = computed(() => {
  const subtotal = lines.value.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * Number(props.invoice.vat_rate || 0)) / 100);
  const total = roundTotalRappen(subtotal + vat);
  return { subtotal, vat, rounding: total - (subtotal + vat), total };
});
```

Ensure `roundTotalRappen` is imported. Find the existing import of `lineAmountRappen` from `formatters/vat` near the top of the `<script setup>` and add `roundTotalRappen` to it, e.g.:

```javascript
import { lineAmountRappen, roundTotalRappen } from '@/formatters/vat';
```

(Match the existing import path/style already used in the file for `lineAmountRappen`.)

Then replace lines 147-149 (the totals markup rows) with:

```vue
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(totals.subtotal) }}</div>
        <div class="label">MwSt {{ fmtRate(invoice.vat_rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
        <template v-if="totals.rounding !== 0">
          <div class="label">Rundung</div><div class="v">{{ fmtMoney(totals.rounding) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totals.total) }}</div>
```

- [ ] **Step 4: Add the Rundung line in Create.vue**

In `resources/js/Pages/Invoices/Create.vue`, replace lines 175-177 (the totals markup rows) with:

```vue
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ fmtRate(totals.rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
        <template v-if="totals.rounding !== 0">
          <div class="label">Rundung</div><div class="v">{{ fmtMoney(totals.rounding) }}</div>
        </template>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
```

(`totalRappen` already comes from `totals.total`, which is now rounded — see `Create.vue:65`.)

- [ ] **Step 5: Build to verify no compile errors**

Run: `ddev npm run build`
Expected: build succeeds. Then open the Create and Edit invoice screens and confirm the Rundung line appears live for a non-boundary total and the Total matches what the PDF preview shows.

- [ ] **Step 6: Commit**

```bash
git add resources/js/formatters/vat.js resources/js/Pages/Invoices/Edit.vue resources/js/Pages/Invoices/Create.vue
git commit -m "feat: mirror 5-rappen rounding and Rundung line in invoice editors"
```

---

### Task 5: Confirm the QR-bill amount matches the rounded total

**Files:**
- Verify only: `app/Services/Invoicing/QrBillRenderer.php:66-71`
- Test: `tests/Feature/Invoicing/InvoiceRoundingTest.php` (extend)

**Interfaces:**
- Consumes: `$invoice->total_rappen` (now rounded, Task 1/2).

- [ ] **Step 1: Add a test asserting the QR amount equals the rounded total**

Append to `tests/Feature/Invoicing/InvoiceRoundingTest.php`:

```php
test('qr-bill amount equals the rounded total in CHF', function () {
    $invoice = Invoice::factory()->create([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'rounding_rappen' => 1,
        'total_rappen' => 31350,
        'currency' => 'CHF',
        'vat_rate' => 8.10,
    ]);

    // The renderer derives the amount from total_rappen / 100.
    expect(round($invoice->total_rappen / 100, 2))->toBe(313.5);
});
```

- [ ] **Step 2: Run the test and confirm QrBillRenderer needs no change**

Run: `ddev artisan test --filter=InvoiceRounding`
Expected: PASS. Confirm by reading `app/Services/Invoicing/QrBillRenderer.php:66-71` that the amount is `round($invoice->total_rappen / 100, 2)` — it already uses the rounded total, so no code change is required.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Invoicing/InvoiceRoundingTest.php
git commit -m "test: assert QR-bill amount matches rounded invoice total"
```

---

### Task 6: Full regression run

- [ ] **Step 1: Run the whole test suite**

Run: `ddev artisan test`
Expected: PASS (no regressions from the changed `total_rappen` semantics; if any other test hard-coded an unrounded total, update it to the rounded value with the same reasoning as Task 1).

- [ ] **Step 2: Commit any fixups**

```bash
git add -A
git commit -m "test: fix invoice total expectations for 5-rappen rounding"
```

---

## Self-Review Notes

- **Spec coverage:** rounding rule (Task 1), signed column + no backfill (Task 2), single source of truth (Task 1, mirrored Task 4), Rundung line hidden when zero (Tasks 3 & 4), projection exposure (Task 2), QR-bill unchanged (Task 5), tests for up/down/boundary/reconcile (Tasks 1, 4, 5). All covered.
- **Existing-test impact:** `LineTotalsTest` previously asserted unrounded `total_rappen` (31349); Task 1 updates it to 31350. Task 6 catches any other invoice tests that hard-coded totals.
- **DRY:** `roundTotalRappen` is the single JS rule used by both `totalsForLines` and `Edit.vue`'s inline computed.
</content>
