# Single Temporal VAT Rate + Rate Editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the multi-code temporal VAT system with a single temporal VAT rate (one rate active at a time, with validity-period history) and add a `/settings/vat-rates` editor to manage it.

**Architecture:** A `vat_rates` catalog holds rows of `rate` + `[valid_from, valid_until]` with no overlaps. Documents snapshot their single `vat_rate` at creation from the catalog (fallback: `business_profile.default_vat_rate`). All per-line VAT detail (`vat_code`, `vat_label`, per-line `vat_rate`, `vat_exempt`) is removed — every line is taxed at the document rate. A RESTful resource provides inline CRUD over the catalog.

**Tech Stack:** Laravel 11, Inertia + Vue 3, Pest, MySQL, Vite.

**Reference spec:** `docs/superpowers/specs/2026-05-29-single-vat-rate-design.md`

---

## Execution phases & green checkpoints

This is a coupled refactor. Commit every task, but the test suite is only expected **green at the marked checkpoints**:

- **Phase A** (Tasks 1–8): collapse the backend to a single rate. Columns remain in the DB with defaults; code stops using them. Suite RED between A-tasks, **GREEN at end of Task 8**.
- **Phase B** (Task 9): drop the now-unused line VAT columns. **GREEN at end of Task 9.**
- **Phase C** (Tasks 10–12): frontend + PDF simplification. **GREEN at end of Task 12.**
- **Phase D** (Tasks 13–17): the editor. **GREEN at end of Task 17.**

Test command throughout: `php artisan test` (full suite) or a targeted `php artisan test --filter=Name`.

---

# Phase A — Collapse the backend to a single rate

## Task 1: Forward migration — collapse the `vat_rates` catalog + rewrite the seeder

**Files:**
- Create: `database/migrations/2026_05_29_140000_collapse_vat_rates_to_single_code.php`
- Modify: `database/seeders/BootstrapSeeder.php:52-71`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_05_29_140000_collapse_vat_rates_to_single_code.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the standard rate is used. Drop the other codes' rows so that,
        // once `code` is gone, no two rows share a validity window.
        DB::table('vat_rates')->where('code', '!=', 'standard')->delete();

        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropUnique(['code', 'valid_from']);
            $table->dropIndex(['is_default', 'valid_from']);
            $table->dropIndex(['code', 'valid_from', 'valid_until']);
            $table->dropColumn(['code', 'label', 'is_default']);
        });

        Schema::table('vat_rates', function (Blueprint $table) {
            $table->unique('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropUnique(['valid_from']);
            $table->string('code', 32)->default('standard')->after('id');
            $table->string('label')->default('Normalsatz')->after('code');
            $table->boolean('is_default')->default(false)->after('valid_until');
            $table->unique(['code', 'valid_from']);
            $table->index(['is_default', 'valid_from']);
            $table->index(['code', 'valid_from', 'valid_until']);
        });

        DB::table('vat_rates')->update(['is_default' => true]);
    }
};
```

- [ ] **Step 2: Rewrite the seeder's VAT rows**

In `database/seeders/BootstrapSeeder.php`, replace the seeding loop (lines 52-57) and the `vatRates()` method (lines 60-71) with:

```php
        foreach ($this->vatRates() as $rate) {
            VatRate::updateOrCreate(['valid_from' => $rate['valid_from']], $rate);
        }
    }

    private function vatRates(): array
    {
        return [
            ['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31'],
            ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null],
        ];
    }
```

- [ ] **Step 3: Run the migration fresh + seed**

Run: `php artisan migrate:fresh --seed --env=testing 2>&1 | tail -5`
(Then re-seed dev as well: `php artisan migrate --env=local` is unnecessary — forward migration on dev: `php artisan migrate`.)

Expected: migration runs without error; `vat_rates` has 2 rows.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_29_140000_collapse_vat_rates_to_single_code.php database/seeders/BootstrapSeeder.php
git commit -m "refactor(vat): collapse vat_rates catalog to a single dated rate"
```

---

## Task 2: Rewrite the `VatRate` model

**Files:**
- Modify: `app/Models/VatRate.php` (full rewrite)

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VatRate extends Model
{
    protected $fillable = ['rate', 'valid_from', 'valid_until'];

    protected $casts = [
        'rate' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /**
     * The VAT rate row active on the given date. Falls back to an in-memory row
     * built from the business profile's default when the catalog has no cover.
     */
    public static function forDate(Carbon|string|null $date = null): self
    {
        $day = static::day($date);

        $rate = static::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(function ($query) use ($day) {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day);
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($rate) {
            return $rate;
        }

        $fallback = (float) (BusinessProfile::query()->value('default_vat_rate') ?? 8.10);

        return new self(['rate' => $fallback, 'valid_from' => $day, 'valid_until' => null]);
    }

    /** Convenience: the numeric rate active on the given date. */
    public static function rateForDate(Carbon|string|null $date = null): float
    {
        return (float) static::forDate($date)->rate;
    }

    /** All catalog rows for the editor / client-side totals, oldest first. */
    public static function catalogForFrontend(): Collection
    {
        return static::query()
            ->orderBy('valid_from')
            ->get(['id', 'rate', 'valid_from', 'valid_until']);
    }

    private static function day(Carbon|string|null $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString();
    }
}
```

- [ ] **Step 2: Commit** (suite still red — Phase A)

```bash
git add app/Models/VatRate.php
git commit -m "refactor(vat): single-rate VatRate model API"
```

---

## Task 3: Rewrite `LineTotals`

**Files:**
- Modify: `app/Support/LineTotals.php` (full rewrite)

- [ ] **Step 1: Replace the whole file**

```php
<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total. Every line is taxed at the single
     * document rate.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, float $vatRate): array
    {
        $subtotal = array_sum(array_map('intval', $lineAmounts));
        $vat = (int) round($subtotal * $vatRate / 100);

        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen' => $vat,
            'total_rappen' => $subtotal + $vat,
        ];
    }
}
```

- [ ] **Step 2: Commit** (suite still red — Phase A)

```bash
git add app/Support/LineTotals.php
git commit -m "refactor(vat): single-rate LineTotals::compute"
```

---

## Task 4: Update the builders, lifecycle, and generator

**Files:**
- Modify: `app/Services/Invoicing/InvoiceBuilder.php`
- Modify: `app/Services/Estimating/EstimateBuilder.php`
- Modify: `app/Services/Estimating/EstimateLifecycle.php:115-122`
- Modify: `app/Services/Invoicing/RecurringInvoiceGenerator.php:39-45`

- [ ] **Step 1: `InvoiceBuilder` — replace `computeTotals`, `computeTotalsFromRates`, `suggestLinesFromEntries`, and the VAT logic in `createDraft`**

Replace the two compute methods (lines 26-49) with a single one:

```php
    /**
     * Compute subtotal, VAT, and total from line amounts and the document rate.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function computeTotals(array $lineAmounts, float $vatRate): array
    {
        return LineTotals::compute($lineAmounts, $vatRate);
    }
```

In `suggestLinesFromEntries` (lines 57-89), remove the `$vat = VatRate::defaultForDate(...)` line and the three `vat_*` keys. The method becomes:

```php
    /**
     * Group eligible entries into suggested invoice lines for the Create editor.
     * Pure read — does not persist anything.
     *
     * @return array<int, array{description:string, hours:float, rate_rappen:int, amount_rappen:int, entry_ids:int[]}>
     */
    public function suggestLinesFromEntries(Collection $entries, ?Project $project, Carbon|string|null $taxDate = null): array
    {
        $eligible = $entries
            ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
            ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
            ->values();

        $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
            ? $e->description
            : ($e->task_id ? ('Task #'.$e->task_id) : ('Entry #'.$e->id)));

        $lines = [];
        foreach ($groups as $description => $bucket) {
            /** @var Collection<int, TimeEntry> $bucket */
            $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
            $rate = (int) ($bucket->first()->project->rate_rappen ?? 0);
            $lines[] = [
                'description' => (string) $description,
                'hours' => $hours,
                'rate_rappen' => $rate,
                'amount_rappen' => (int) round($hours * $rate),
                'entry_ids' => $bucket->pluck('id')->all(),
            ];
        }

        return $lines;
    }
```

Note `$taxDate` is now unused inside the method but stays in the signature (callers pass it; harmless). 

In `createDraft`, replace the document-VAT block (lines 113-115) with:

```php
            $taxDate = $taxDate ?: $periodEnd;
            $documentRate = $vatRate ?? VatRate::rateForDate($taxDate);
```

Change the `Invoice::create([...])` `'vat_rate'` value (line 127) to `'vat_rate' => $documentRate,`.

Replace the per-line loop body (lines 140-173) with:

```php
            $lineAmounts = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
            }

            $totals = self::computeTotals($lineAmounts, (float) $invoice->vat_rate);
```

Remove the now-unused `use App\Models\BusinessProfile;` only if nothing else references it — it is still used (`BusinessProfile::current()` at line 111), so keep it.

- [ ] **Step 2: `EstimateBuilder` — same treatment**

Replace the document-VAT line (line 38) with:

```php
            $documentRate = VatRate::rateForDate($taxDate);
```

Change `Estimate::create` `'vat_rate'` (line 48) to `'vat_rate' => $documentRate,`.

Replace the per-line loop (lines 59-84) with:

```php
            $lineAmounts = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount

                EstimateLine::create([
                    'estimate_id' => $estimate->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
            }

            $totals = LineTotals::compute($lineAmounts, (float) $estimate->vat_rate);
```

`$profile` is still used for currency, keep the `BusinessProfile::current()` line.

- [ ] **Step 3: `EstimateLifecycle::convertToInvoice` — drop per-line VAT from the line map**

Replace lines 116-122 with:

```php
            $lines = $estimate->lines->map(fn ($l) => [
                'description' => $l->description,
                'hours' => (float) $l->hours,
                'rate_rappen' => (int) $l->rate_rappen,
            ])->all();
```

- [ ] **Step 4: `RecurringInvoiceGenerator::generate` — drop per-line VAT from the line map**

Replace lines 39-45 with:

```php
        $lines = $schedule->lines->map(fn (RecurringInvoiceLine $l) => [
            'description' => $l->description,
            'hours' => (float) $l->hours,
            'rate_rappen' => (int) $l->rate_rappen,
        ])->all();
```

- [ ] **Step 5: Commit** (suite still red — Phase A)

```bash
git add app/Services/Invoicing/InvoiceBuilder.php app/Services/Estimating/EstimateBuilder.php app/Services/Estimating/EstimateLifecycle.php app/Services/Invoicing/RecurringInvoiceGenerator.php
git commit -m "refactor(vat): builders/generator use a single document rate"
```

---

## Task 5: Update the controllers

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `app/Http/Controllers/EstimateController.php`
- Modify: `app/Http/Controllers/RecurringInvoiceController.php`

- [ ] **Step 1: `InvoiceController`**

**Keep** `'vat_rates' => VatRate::catalogForFrontend(),` in both `create()` branches and **keep** the `use App\Models\VatRate;` import — the forms still need the catalog for client-side totals.

In the main `create()` return (lines 98-109), remove only the `vat_exempt`/`vat_code`/`vat_label`/`vat_rate` keys from the `suggested_lines` map (leave the `'vat_rates'` line below it intact). The suggested-lines map becomes:

```php
            'suggested_lines' => collect($builder->suggestLinesFromEntries($entries, $project, $end))
                ->map(fn ($l) => [
                    'description' => $l['description'],
                    'hours' => $l['hours'],
                    'rate' => (int) round($l['rate_rappen'] / 100),
                    'rate_rappen' => $l['rate_rappen'],
                    'entry_ids' => $l['entry_ids'],
                ])->values(),
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
```

In `show()` (lines 159-164), drop the per-line VAT keys; the line map becomes:

```php
                'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                    'id' => $l->id, 'description' => $l->description,
                    'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                    'amount' => round($l->amount_rappen / 100, 2),
                ]),
```

(Keep the document-level `'vat_rate' => (float) $invoice->vat_rate,` at line 154 — and keep the `'vat_rates'` prop and `VatRate` import.)

In `update()` (lines 193-224), replace the lines-rebuild block with:

```php
            if (! empty($data['lines'])) {
                $invoice->lines()->delete();
                $lineAmounts = [];
                $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $invoice->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount;
                }
                $totals = InvoiceBuilder::computeTotals($lineAmounts, (float) $invoice->vat_rate);
                $invoice->subtotal_rappen = $totals['subtotal_rappen'];
                $invoice->vat_rappen = $totals['vat_rappen'];
                $invoice->total_rappen = $totals['total_rappen'];
            }
```

(`VatRate` stays imported and used by `catalogForFrontend()`.)

- [ ] **Step 2: `EstimateController`**

**Keep** `'vat_rates' => VatRate::catalogForFrontend(),` in `create()` (line 58) and `edit()` (line 158), and keep the `use App\Models\VatRate;` import.

In `edit()` line map (lines 141-149), drop the `vat_exempt`/`vat_code`/`vat_label`/`vat_rate` keys:

```php
                'lines' => $estimate->lines->map(fn (EstimateLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => (int) round($l->rate_rappen / 100),
                ])->values(),
```

In `show()` line map (lines 104-109), drop the per-line VAT keys:

```php
                'lines' => $estimate->lines->map(fn (EstimateLine $l) => [
                    'id' => $l->id, 'description' => $l->description,
                    'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                    'amount' => round($l->amount_rappen / 100, 2),
                ]),
```

In `update()` (lines 184-217), replace the lines-rebuild block with:

```php
            if (! empty($data['lines'])) {
                $estimate->lines()->delete();
                $lineAmounts = [];
                $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $estimate->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount;
                }
                $totals = LineTotals::compute($lineAmounts, (float) $estimate->vat_rate);
                $estimate->subtotal_rappen = $totals['subtotal_rappen'];
                $estimate->vat_rappen = $totals['vat_rappen'];
                $estimate->total_rappen = $totals['total_rappen'];
            }
```

(`VatRate` stays imported and used by `catalogForFrontend()`. `LineTotals` is already imported at line 16.)

- [ ] **Step 3: `RecurringInvoiceController`**

In `create()` (lines 47-50), drop only `default_vat_rate`; **keep** `vat_rates`:

```php
    public function create(): Response
    {
        return Inertia::render('RecurringInvoices/Create', $this->formData() + [
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }
```

In `store()` (line 60), replace the document-VAT snapshot:

```php
            $documentRate = $data['vat_rate'] ?? VatRate::rateForDate($nextRun);
```

and set `'vat_rate' => $documentRate,` (line 67).

In `edit()` (lines 94-104), drop only the per-line VAT keys; **keep** the `'vat_rates'` prop:

```php
                'lines' => $recurringInvoice->lines->map(fn (RecurringInvoiceLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => (int) round($l->rate_rappen / 100),
                ])->values(),
            ],
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
```

In `update()` (line 115), replace:

```php
            $documentRate = $data['vat_rate'] ?? VatRate::rateForDate($nextRun);
```

and set `'vat_rate' => $documentRate,` (line 121).

In `syncLines()` (lines 175-194), drop the snapshot and per-line VAT fields:

```php
    /** @param array<int, array{description:string, hours:float|string, rate_rappen:int}> $lines */
    private function syncLines(RecurringInvoice $schedule, array $lines, Carbon|string $taxDate): void
    {
        $sort = 0;
        foreach ($lines as $line) {
            $schedule->lines()->create([
                'description' => (string) $line['description'],
                'hours' => round((float) $line['hours'], 2),
                'rate_rappen' => (int) $line['rate_rappen'],
                'sort_order' => $sort++,
            ]);
        }
    }
```

`VatRate` is still used (`VatRate::rateForDate`), keep the import. `$taxDate` stays in the `syncLines` signature (callers pass it; harmless).

- [ ] **Step 4: Commit** (suite still red — Phase A)

```bash
git add app/Http/Controllers/InvoiceController.php app/Http/Controllers/EstimateController.php app/Http/Controllers/RecurringInvoiceController.php
git commit -m "refactor(vat): controllers drop per-line VAT, keep document rate"
```

---

## Task 6: Update the FormRequests

**Files:**
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `app/Http/Requests/StoreEstimateRequest.php`
- Modify: `app/Http/Requests/UpdateEstimateRequest.php`
- Modify: `app/Http/Requests/StoreRecurringInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateRecurringInvoiceRequest.php`

- [ ] **Step 1: Remove the two per-line VAT rules from each of the six requests**

In every file, delete these two lines:

```php
            'lines.*.vat_exempt' => 'sometimes|boolean',
            'lines.*.vat_code' => ['sometimes', 'nullable', 'string', Rule::exists('vat_rates', 'code')],
```

For `StoreInvoiceRequest`, `UpdateInvoiceRequest`, `StoreEstimateRequest`, `UpdateEstimateRequest` the `use Illuminate\Validation\Rule;` import is still needed (used by `project_id`), keep it. For `StoreRecurringInvoiceRequest` / `UpdateRecurringInvoiceRequest`, `Rule` is still used by `project_id`, keep it. Keep the document-level `'vat_rate' => 'sometimes|numeric|min:0|max:100',` in both recurring requests.

- [ ] **Step 2: Commit** (suite still red — Phase A)

```bash
git add app/Http/Requests/Store*.php app/Http/Requests/Update*.php
git commit -m "refactor(vat): drop per-line VAT validation rules"
```

---

## Task 7: Update factories and Harvest importers

**Files:**
- Modify: `database/factories/InvoiceLineFactory.php:24-27`
- Modify: `database/factories/EstimateLineFactory.php:24-27`
- Modify: `database/factories/RecurringInvoiceLineFactory.php:20-23`
- Modify: `app/Services/Harvest/InvoiceImporter.php:80-93`
- Modify: `app/Services/Harvest/EstimateImporter.php:83-94`

- [ ] **Step 1: Factories — drop the four VAT keys**

In each of the three line factories, delete these lines from the returned array:

```php
            'vat_exempt' => false,
            'vat_code' => 'standard',
            'vat_label' => 'Normalsatz',
            'vat_rate' => 8.10,
```

- [ ] **Step 2: `InvoiceImporter` — warn on untaxed lines, drop per-line VAT**

Replace the line loop (lines 80-93) with:

```php
            $sort = 0;
            foreach ($row['line_items'] ?? [] as $line) {
                if (! (bool) ($line['taxed'] ?? true)) {
                    $warnings[] = "Invoice {$row['number']} has an untaxed line item; ernte applies the document VAT rate to every line.";
                }
                $invoice->lines()->create([
                    'description' => $line['description'] ?? '',
                    'hours' => (float) ($line['quantity'] ?? 0),
                    'rate_rappen' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                    'amount_rappen' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                    'sort_order' => $sort++,
                ]);
            }
```

- [ ] **Step 3: `EstimateImporter` — same treatment**

Replace the line loop (lines 81-95) with:

```php
            $sort = 0;
            foreach ($row['line_items'] ?? [] as $line) {
                if (! (bool) ($line['taxed'] ?? true)) {
                    $warnings[] = "Estimate {$row['number']} has an untaxed line item; ernte applies the document VAT rate to every line.";
                }
                $estimate->lines()->create([
                    'description' => $line['description'] ?? '',
                    'hours' => (float) ($line['quantity'] ?? 0),
                    'rate_rappen' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                    'amount_rappen' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                    'sort_order' => $sort++,
                ]);
            }
```

- [ ] **Step 4: Commit** (suite still red — Phase A)

```bash
git add database/factories/*LineFactory.php app/Services/Harvest/InvoiceImporter.php app/Services/Harvest/EstimateImporter.php
git commit -m "refactor(vat): factories + importers drop per-line VAT"
```

---

## Task 8: Update the affected PHP tests — Phase A GREEN checkpoint

**Files:**
- Modify: `tests/Feature/Support/LineTotalsTest.php` (full rewrite)
- Modify: `tests/Feature/Services/InvoiceBuilderTest.php`
- Modify: `tests/Feature/Services/EstimateBuilderTest.php`
- Modify: `tests/Feature/Harvest/InvoiceImporterTest.php:45,64-72`
- Modify: `tests/Feature/Harvest/EstimateImporterTest.php`
- Modify: `tests/Feature/Http/InvoiceControllerTest.php` (line payloads)
- Modify: `tests/Feature/Http/EstimateControllerTest.php` (line payloads)
- Modify: `tests/Feature/Http/RecurringInvoiceControllerTest.php` (line payloads)

- [ ] **Step 1: Rewrite `LineTotalsTest`**

```php
<?php

use App\Support\LineTotals;

test('compute taxes every line at the document rate', function () {
    $totals = LineTotals::compute([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['total_rappen'])->toBe(16215);
});

test('compute matches the original VAT formula', function () {
    $totals = LineTotals::compute([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'total_rappen' => 31349,
    ]);
});

test('compute with a zero rate yields no VAT', function () {
    $totals = LineTotals::compute([10000], 0.0);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 10000,
        'vat_rappen' => 0,
        'total_rappen' => 10000,
    ]);
});
```

- [ ] **Step 2: Fix `InvoiceBuilderTest`**

In the test `vat_rate is stamped from the dated VAT catalog at build time`, delete this line (per-line column is gone):

```php
    expect((float) $invoice->lines->first()->vat_rate)->toBe(7.70);
```

Replace the `computeTotals respects vat_exempt lines` test (the `computeTotals(... vatExempts ...)` call no longer exists) with:

```php
test('computeTotals taxes every line at the document rate', function () {
    $totals = InvoiceBuilder::computeTotals([10000, 5000], 8.10);

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(1215);   // 8.10% of 15000
    expect($totals['total_rappen'])->toBe(16215);
});
```

Replace the `computeTotals with all non-exempt matches original VAT formula` test body's call with the new signature:

```php
test('computeTotals matches the original VAT formula', function () {
    $totals = InvoiceBuilder::computeTotals([29000], 8.10);

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen' => 2349,
        'total_rappen' => 31349,
    ]);
});
```

In `suggestLinesFromEntries groups by description...`, delete the assertion:

```php
    expect($pr['vat_exempt'])->toBeFalse();
```

Rewrite `createDraft persists submitted lines with per-line vat_exempt and recomputes amounts` (the second line is no longer exempt, so it is now taxed). Replace its body with:

```php
test('createDraft persists submitted lines and recomputes amounts', function () {
    $e = makeEntry($this->user, $this->project, 'Work', 120);

    $invoice = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        periodStart: now()->subDays(7)->toDateString(),
        periodEnd: now()->toDateString(),
        lines: [
            ['description' => 'Consulting',     'hours' => 2.0, 'rate_rappen' => 14500],
            ['description' => 'Reimbursement',  'hours' => 1.0, 'rate_rappen' => 5000],
        ],
        entryIds: [$e->id],
    );

    expect($invoice->status)->toBe('draft');
    expect($invoice->lines)->toHaveCount(2);

    $consulting = $invoice->lines->firstWhere('description', 'Consulting');
    expect($consulting->amount_rappen)->toBe(29000);          // recomputed: 2 * 14500

    $reimb = $invoice->lines->firstWhere('description', 'Reimbursement');
    expect($reimb->amount_rappen)->toBe(5000);

    // subtotal = 34000; vat = 8.10% of 34000 = 2754; total = 36754
    expect($invoice->subtotal_rappen)->toBe(34000);
    expect($invoice->vat_rappen)->toBe(2754);
    expect($invoice->total_rappen)->toBe(36754);

    expect($e->fresh()->invoice_id)->toBe($invoice->id);
    expect($invoice->qr_reference)->toMatch('/^\d{27}$/');
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/');
    expect($invoice->events()->where('kind', 'created')->count())->toBe(1);
});
```

In `createDraft ignores client-submitted amount_rappen (anti-tamper)`, the line still has `vat_exempt`/`amount_rappen` keys — they are simply ignored now, but tidy it to:

```php
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'amount_rappen' => 999999]],
```

The `createDraft stamps an explicit vat rate when provided` test still passes as-is (explicit `vatRate: 2.60` → `vat_rappen` 260). Just remove `'vat_exempt' => false` from its line payload for tidiness.

- [ ] **Step 3: Fix `EstimateBuilderTest`**

Rewrite `createDraft persists lines, recomputes amounts, and computes totals` (Travel is no longer exempt → taxed):

```php
test('createDraft persists lines, recomputes amounts, and computes totals', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        lines: [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500],
            ['description' => 'Travel',       'hours' => 1.0, 'rate_rappen' => 5000],
        ],
        notes: 'Valid for 30 days.',
    );

    expect($estimate->status)->toBe('draft');
    expect($estimate->lines)->toHaveCount(2);

    $design = $estimate->lines->firstWhere('description', 'Design phase');
    expect($design->amount_rappen)->toBe(29000);  // recomputed: 2 * 14500

    $travel = $estimate->lines->firstWhere('description', 'Travel');
    expect($travel->amount_rappen)->toBe(5000);

    // subtotal = 34000; vat = 8.10% of 34000 = 2754; total = 36754
    expect($estimate->subtotal_rappen)->toBe(34000);
    expect($estimate->vat_rappen)->toBe(2754);
    expect($estimate->total_rappen)->toBe(36754);
    expect($estimate->notes)->toBe('Valid for 30 days.');
});
```

In `createDraft stamps vat_rate from the dated catalog...`, delete the per-line assertion:

```php
    expect((float) $estimate->lines->first()->vat_rate)->toBe(7.70);
```

For the remaining two tests, remove `'vat_exempt' => false` / `'vat_exempt' => true` keys from their line payloads (ignored now; tidy).

- [ ] **Step 4: Fix the Harvest importer tests**

In `tests/Feature/Harvest/InvoiceImporterTest.php`: delete line 45 (`expect($inv->lines->first()->vat_exempt)->toBeFalse();`). Replace the `untaxed line items become vat_exempt` test (lines 64-72) with:

```php
test('untaxed line items produce a warning', function () {
    $inv = harvestInvoice(['line_items' => [
        ['id' => 1, 'description' => 'Reimbursement', 'quantity' => 1.0, 'unit_price' => 50.0, 'amount' => 50.0, 'taxed' => false],
    ]]);

    $result = (new InvoiceImporter())->import([$inv], $this->clientMap);

    expect(Invoice::first()->lines)->toHaveCount(1);
    expect($result['warnings'])->not->toBeEmpty();
});
```

`tests/Feature/Harvest/EstimateImporterTest.php` needs **no changes** — it has no per-line `vat_exempt` assertion and no "untaxed" test (it only asserts `$est->lines` count and the document-level `vat_rappen`).

- [ ] **Step 5: Fix the controller tests' line payloads**

In `tests/Feature/Http/InvoiceControllerTest.php`, `EstimateControllerTest.php`, and `RecurringInvoiceControllerTest.php`, remove the `'vat_exempt' => false` / `'vat_exempt' => true` keys from every `lines` payload (they are no longer validated; harmless but tidy). These are at: InvoiceControllerTest lines 109, 165, 247; EstimateControllerTest lines 29, 78, 96, 117, 167, 182, 218; RecurringInvoiceControllerTest lines 42, 69. Keep the document-level `'vat_rate' => 8.10` keys in the recurring payloads.

- [ ] **Step 6: Run the full suite — expect GREEN**

Run: `php artisan test 2>&1 | tail -20`
Expected: all tests pass. If any test still references `vatBreakdown`, `computeFromRates`, `snapshotFor`, `defaultForDate`, `vat_code`, `vat_label`, or per-line `vat_rate`, fix it the same way (grep: `grep -rn "vatBreakdown\|computeFromRates\|snapshotFor\|defaultForDate\|optionsForDate" app/ tests/`).

- [ ] **Step 7: Commit**

```bash
git add tests/
git commit -m "test(vat): update suite for single-rate model"
```

---

# Phase B — Drop the line VAT columns

## Task 9: Forward migration dropping per-line VAT columns — Phase B GREEN checkpoint

**Files:**
- Create: `database/migrations/2026_05_29_140100_drop_line_vat_columns.php`
- Modify: `app/Models/InvoiceLine.php:12-24`
- Modify: `app/Models/EstimateLine.php:12-24`
- Modify: `app/Models/RecurringInvoiceLine.php:12-23`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_05_29_140100_drop_line_vat_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['invoice_lines', 'estimate_lines', 'recurring_invoice_lines'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['vat_code', 'vat_rate']);
                $table->dropColumn(['vat_code', 'vat_label', 'vat_rate', 'vat_exempt']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('vat_exempt')->default(false);
                $table->string('vat_code', 32)->default('standard');
                $table->string('vat_label')->nullable();
                $table->decimal('vat_rate', 5, 2)->default(8.10);
                $table->index(['vat_code', 'vat_rate']);
            });
        }
    }
};
```

- [ ] **Step 2: Trim the three line models' `$fillable` and `$casts`**

In `InvoiceLine` and `EstimateLine`, set:

```php
    protected $fillable = [
        'invoice_id', 'description', 'hours', 'rate_rappen', 'amount_rappen', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'sort_order' => 'integer',
    ];
```

(For `EstimateLine`, the first fillable key is `estimate_id`, not `invoice_id`.)

In `RecurringInvoiceLine` (no `amount_rappen`):

```php
    protected $fillable = [
        'recurring_invoice_id', 'description', 'hours', 'rate_rappen', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'sort_order' => 'integer',
    ];
```

- [ ] **Step 3: Migrate + run the suite — expect GREEN**

Run: `php artisan migrate --env=testing && php artisan test 2>&1 | tail -20`
Expected: migration runs; all tests pass.

(Also apply to the dev DB: `php artisan migrate`.)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_29_140100_drop_line_vat_columns.php app/Models/InvoiceLine.php app/Models/EstimateLine.php app/Models/RecurringInvoiceLine.php
git commit -m "refactor(vat): drop per-line VAT columns"
```

---

# Phase C — Frontend & PDF simplification

## Task 10: Rewrite the `vat.js` formatter

**Files:**
- Modify: `resources/js/formatters/vat.js` (full rewrite)

- [ ] **Step 1: Replace the whole file**

```js
function dateString(value) {
  return value || new Date().toISOString().slice(0, 10);
}

function validOn(rate, date) {
  const day = dateString(date);
  return rate.valid_from <= day && (!rate.valid_until || rate.valid_until >= day);
}

// The single VAT rate active on the given date (0 if the catalog has no cover).
export function vatRateForDate(catalog, date) {
  const rate = [...(catalog || [])]
    .filter((r) => validOn(r, date))
    .sort((a, b) => (a.valid_from < b.valid_from ? 1 : -1))[0];
  return rate ? Number(rate.rate) : 0;
}

export function lineAmountRappen(line) {
  return Math.round(Number(line.hours) * Number(line.rate) * 100);
}

export function totalsForLines(lines, catalog, date) {
  const rate = vatRateForDate(catalog, date);
  const subtotal = lines.reduce((sum, line) => sum + lineAmountRappen(line), 0);
  const vat = Math.round((subtotal * rate) / 100);
  return { subtotal, vat, total: subtotal + vat, rate };
}
```

- [ ] **Step 2: Commit** (frontend not yet rebuilt — Phase C)

```bash
git add resources/js/formatters/vat.js
git commit -m "refactor(vat): single-rate vat.js formatter"
```

---

## Task 11: Update the five document forms

**Files:**
- Modify: `resources/js/Pages/Invoices/Create.vue`
- Modify: `resources/js/Pages/Estimates/Create.vue`
- Modify: `resources/js/Pages/Estimates/Edit.vue`
- Modify: `resources/js/Pages/RecurringInvoices/Create.vue`
- Modify: `resources/js/Pages/RecurringInvoices/Edit.vue`

Each form needs the same five edits. The "date expression" differs per file — it is whatever the existing `totalsForLines(..., <date>)` call passes: `to.value` (Invoices/Create), no date / `undefined` (Estimates/Create — pass nothing), `props.estimate.tax_date` (Estimates/Edit), `nextRunOn.value` (RecurringInvoices/Create), `nextRunOn.value` (RecurringInvoices/Edit).

- [ ] **Step 1: Fix the import** (all five files)

Replace:

```js
import { activeVatRates, defaultVatCode, totalsForLines, vatLabelForCode } from '@/formatters/vat.js';
```

with:

```js
import { totalsForLines } from '@/formatters/vat.js';
```

- [ ] **Step 2: Remove `vat_code` from line objects** (all five files)

In each `addLine()` and any line-seeding map, delete the `vat_code: ...` (and any `vat_rate: ...`) property. For example in `Invoices/Create.vue` the seeded map (lines 44-48) becomes:

```js
const lines = ref(props.suggested_lines.map((l, i) => ({
  key: i, description: l.description, hours: l.hours, rate: l.rate,
})));
```

and `addLine()` drops its `vat_code:` line. In `Estimates/Edit.vue` the seed is `props.estimate.lines.map((l) => ({ key: nextKey++, ...l }))` — leave it (the server no longer sends `vat_*`, so nothing to strip), but remove `vat_code:` from its `addLine()`. In `RecurringInvoices/Edit.vue` the seed map (lines 31-38) drops `vat_code` and `vat_rate`.

- [ ] **Step 3: Remove the `vatOptions` computed and drop `vat_code` from the save transform** (all five files)

Delete the line:

```js
const vatOptions = computed(() => activeVatRates(props.vat_rates, <date>));
```

In `save()`, delete the `vat_code: l.vat_code,` key from each line in the `lines: lines.value.map(...)` payload.

- [ ] **Step 4: Remove the per-line MwSt column** (all five files)

In the lines `<table>`, delete the header cell `<th style="width: 130px">MwSt</th>` and the entire `<td>` containing the `<select v-model="l.vat_code">…</select>`. Update the empty-state row `colspan` from `6` to `5` (the `<tr v-if="lines.length === 0"><td colspan="6" ...>` line).

- [ ] **Step 5: Replace the totals breakdown with a single VAT row** (all five files)

Replace the breakdown loop in the totals aside:

```html
        <template v-for="row in totals.breakdown" :key="row.rate">
          <div class="label">MwSt {{ fmtRate(row.rate) }}%</div><div class="v">{{ fmtMoney(row.vat_rappen) }}</div>
        </template>
```

with:

```html
        <div class="label">MwSt {{ fmtRate(totals.rate) }}%</div><div class="v">{{ fmtMoney(totals.vat) }}</div>
```

`fmtRate` already exists in each file. `totals` is already `computed(() => totalsForLines(...))` in each file — no change needed there. The now-unused `vatRappen` computed can stay or be removed; if you remove `const vatRappen = computed(...)`, confirm it is not referenced elsewhere in the template (it is not).

- [ ] **Step 6: Keep `vat_rates`; drop only the unused `default_vat_rate` prop**

**Keep** `vat_rates: { type: Array, default: () => [] },` in `defineProps` in all five files — `totalsForLines` derives the rate from this catalog. Only in `RecurringInvoices/Create.vue`, delete the now-unused `default_vat_rate: { type: Number, default: 8.1 },` prop.

- [ ] **Step 7: Commit** (build happens in Task 13 — Phase C continues)

```bash
git add resources/js/Pages/Invoices/Create.vue resources/js/Pages/Estimates/Create.vue resources/js/Pages/Estimates/Edit.vue resources/js/Pages/RecurringInvoices/Create.vue resources/js/Pages/RecurringInvoices/Edit.vue
git commit -m "refactor(vat): remove per-line VAT picker from document forms"
```

---

## Task 12: Update the two PDF templates — Phase C GREEN checkpoint

**Files:**
- Modify: `resources/views/invoices/pdf.blade.php:36-43,93-99,104-110`
- Modify: `resources/views/estimates/pdf.blade.php:35-42,90-97,101-107`

- [ ] **Step 1: `invoices/pdf.blade.php` — drop the `vatBreakdown` block**

Replace the `@php … @endphp` block (lines 36-43) with:

```blade
	@php
	  $money = fn ($rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
	  $rateLabel = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
	@endphp
```

In the line loop, remove the exempt annotation (line 95) so the description cell is:

```blade
	          <td class="line-desc">{!! \App\Support\Markdown::toHtml($line->description) !!}</td>
```

Replace the totals VAT loop (lines 106-108) with a single row:

```blade
	    <div>MwSt {{ $rateLabel($invoice->vat_rate) }}%</div><div class="v">{{ $money($invoice->vat_rappen) }}</div>
```

- [ ] **Step 2: `estimates/pdf.blade.php` — same treatment**

Replace the `@php … @endphp` block (lines 35-42) with:

```blade
	@php
	  $money = fn ($rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
	  $rateLabel = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
	@endphp
```

Remove the exempt annotation in the line loop (line 92):

```blade
	          <td class="line-desc">{!! \App\Support\Markdown::toHtml($line->description) !!}</td>
```

Replace the totals VAT loop (lines 103-105) with:

```blade
	    <div>MwSt {{ $rateLabel($estimate->vat_rate) }}%</div><div class="v">{{ $money($estimate->vat_rappen) }}</div>
```

- [ ] **Step 3: Run the PDF renderer tests — expect GREEN**

Run: `php artisan test --filter="PdfRenderer" 2>&1 | tail -20`
Expected: `InvoicePdfRendererTest` and `EstimatePdfRendererTest` pass. Then run the full suite: `php artisan test 2>&1 | tail -10`.

- [ ] **Step 4: Commit**

```bash
git add resources/views/invoices/pdf.blade.php resources/views/estimates/pdf.blade.php
git commit -m "refactor(vat): single MwSt line on invoice/estimate PDFs"
```

---

# Phase D — VAT rate editor

## Task 13: Add the resource routes + rebuild assets

**Files:**
- Modify: `routes/web.php:96-98` (after the existing settings routes)

- [ ] **Step 1: Register the routes**

In `routes/web.php`, immediately after the `settings.tweaks` route (line 98), add:

```php
    Route::get('/settings/vat-rates', [\App\Http\Controllers\VatRateController::class, 'index'])->name('vat-rates.index');
    Route::post('/settings/vat-rates', [\App\Http\Controllers\VatRateController::class, 'store'])->name('vat-rates.store');
    Route::patch('/settings/vat-rates/{vatRate}', [\App\Http\Controllers\VatRateController::class, 'update'])->name('vat-rates.update');
    Route::delete('/settings/vat-rates/{vatRate}', [\App\Http\Controllers\VatRateController::class, 'destroy'])->name('vat-rates.destroy');
```

- [ ] **Step 2: Commit** (controller arrives in Task 15)

```bash
git add routes/web.php
git commit -m "feat(vat): routes for the VAT rate editor"
```

---

## Task 14: VAT rate FormRequests with overlap validation

**Files:**
- Create: `app/Http/Requests/StoreVatRateRequest.php`
- Create: `app/Http/Requests/UpdateVatRateRequest.php`

- [ ] **Step 1: Write `StoreVatRateRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\VatRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreVatRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // single-user app
    }

    public function rules(): array
    {
        return [
            'rate' => 'required|numeric|min:0|max:100',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->date('valid_from')->toDateString();
            $until = $this->filled('valid_until') ? $this->date('valid_until')->toDateString() : null;
            $ignoreId = $this->route('vatRate')?->id;

            $overlaps = VatRate::query()
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->whereDate('valid_from', '<=', $until ?? '9999-12-31')
                ->where(function ($q) use ($from) {
                    $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('valid_from', 'This validity period overlaps an existing VAT rate.');
            }
        });
    }
}
```

- [ ] **Step 2: Write `UpdateVatRateRequest`** (identical rules + overlap, with the route model excluded automatically via `route('vatRate')`)

```php
<?php

namespace App\Http\Requests;

class UpdateVatRateRequest extends StoreVatRateRequest
{
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/StoreVatRateRequest.php app/Http/Requests/UpdateVatRateRequest.php
git commit -m "feat(vat): VAT rate form requests with overlap validation"
```

---

## Task 15: `VatRateController`

**Files:**
- Create: `app/Http/Controllers/VatRateController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVatRateRequest;
use App\Http\Requests\UpdateVatRateRequest;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VatRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/VatRates', [
            'rates' => VatRate::query()
                ->orderByDesc('valid_from')
                ->get(['id', 'rate', 'valid_from', 'valid_until'])
                ->map(fn (VatRate $r) => [
                    'id' => $r->id,
                    'rate' => (float) $r->rate,
                    'valid_from' => $r->valid_from->toDateString(),
                    'valid_until' => $r->valid_until?->toDateString(),
                ]),
        ]);
    }

    public function store(StoreVatRateRequest $request): RedirectResponse
    {
        VatRate::create($request->validated());

        return back()->with('success', 'VAT rate added.');
    }

    public function update(UpdateVatRateRequest $request, VatRate $vatRate): RedirectResponse
    {
        $vatRate->update($request->validated());

        return back()->with('success', 'VAT rate updated.');
    }

    public function destroy(VatRate $vatRate): RedirectResponse
    {
        $vatRate->delete();

        return back()->with('success', 'VAT rate removed.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/VatRateController.php
git commit -m "feat(vat): VatRateController CRUD"
```

---

## Task 16: The editor page + Settings link

**Files:**
- Create: `resources/js/Pages/Settings/VatRates.vue`
- Modify: `resources/js/Pages/Settings/Profile.vue:130-153` (add a link near the VAT rate field)

- [ ] **Step 1: Write `Settings/VatRates.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  rates: { type: Array, default: () => [] },
});

// One edit form per existing row, keyed by id.
const edits = ref(Object.fromEntries(props.rates.map((r) => [r.id, {
  rate: r.rate, valid_from: r.valid_from, valid_until: r.valid_until ?? '',
}])));
const rowErrors = ref({});

const adding = useForm({ rate: '', valid_from: '', valid_until: '' });

function payload(e) {
  return { rate: Number(e.rate), valid_from: e.valid_from, valid_until: e.valid_until || null };
}

function save(id) {
  rowErrors.value = {};
  router.patch(`/settings/vat-rates/${id}`, payload(edits.value[id]), {
    preserveScroll: true,
    onError: (errs) => { rowErrors.value = { id, errs }; },
  });
}

function add() {
  adding.transform((d) => ({ rate: Number(d.rate), valid_from: d.valid_from, valid_until: d.valid_until || null }))
    .post('/settings/vat-rates', {
      preserveScroll: true,
      onSuccess: () => adding.reset(),
    });
}

function remove(id) {
  if (!window.confirm('Remove this VAT rate? Existing documents keep their stored rate; new documents will fall back to the next covering rate.')) return;
  router.delete(`/settings/vat-rates/${id}`, { preserveScroll: true });
}
</script>

<template>
  <Head title="VAT rates" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/settings">~ / settings</Link><span class="ascii-dot">/</span><span>vat rates</span></div>
      <h1 class="page-title">VAT rates <span class="meta">Dated standard rate</span></h1>
    </div>
  </div>

  <div class="settings-page">
    <section class="settings-section">
      <p class="dim" style="font-size: var(--fs-sm); margin: 0 0 16px; line-height: 1.6">
        One VAT rate applies at a time. Add a new row with a future <em>valid from</em> date when the
        Swiss rate changes — periods may not overlap. Existing documents keep the rate stored on them.
      </p>

      <table class="table">
        <thead>
          <tr>
            <th class="pad-l num" style="width: 120px">Rate %</th>
            <th style="width: 180px">Valid from</th>
            <th style="width: 180px">Valid until</th>
            <th style="width: 140px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rates" :key="r.id">
            <td class="pad-l num"><input v-model="edits[r.id].rate" type="number" min="0" max="100" step="0.01" class="cell-input num" /></td>
            <td><input v-model="edits[r.id].valid_from" type="date" class="cell-input" /></td>
            <td><input v-model="edits[r.id].valid_until" type="date" class="cell-input" placeholder="open-ended" /></td>
            <td>
              <button class="btn ghost" @click="save(r.id)">Save</button>
              <button class="btn ghost" @click="remove(r.id)">Delete</button>
              <div v-if="rowErrors.id === r.id" class="error" style="color: var(--red); font-size: var(--fs-xs)">
                {{ Object.values(rowErrors.errs).join(' · ') }}
              </div>
            </td>
          </tr>
          <tr>
            <td class="pad-l num"><input v-model="adding.rate" type="number" min="0" max="100" step="0.01" class="cell-input num" placeholder="8.10" /></td>
            <td><input v-model="adding.valid_from" type="date" class="cell-input" /></td>
            <td><input v-model="adding.valid_until" type="date" class="cell-input" placeholder="open-ended" /></td>
            <td><button class="btn primary" :disabled="adding.processing || !adding.rate || !adding.valid_from" @click="add">+ Add rate</button></td>
          </tr>
        </tbody>
      </table>
      <div v-if="Object.keys(adding.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(adding.errors).join(' · ') }}
      </div>
    </section>
  </div>
</template>

<style scoped>
.cell-input { width: 100%; border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.cell-input:focus { outline: none; border-color: var(--accent); }
.cell-input.num { text-align: right; }
</style>
```

- [ ] **Step 2: Link from `Settings/Profile.vue`**

In `Settings/Profile.vue`, inside the "Invoice defaults" section, change the VAT-rate field's label so it links to the editor. Replace the `<span>VAT rate</span>` label inside the field (line 139) with:

```html
          <span>VAT rate (fallback) · <Link href="/settings/vat-rates">manage dated rates</Link></span>
```

Add `Link` to the existing Inertia import at the top of the file:

```js
import { Head, Link, useForm } from '@inertiajs/vue3';
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Settings/VatRates.vue resources/js/Pages/Settings/Profile.vue
git commit -m "feat(vat): VAT rate editor page + Settings link"
```

---

## Task 17: Build assets + VatRateController test — Phase D GREEN checkpoint

**Files:**
- Create: `tests/Feature/Http/VatRateControllerTest.php`

- [ ] **Step 1: Build the frontend** (so `Settings/VatRates.vue` enters the Vite manifest — feature tests asserting the page 500 otherwise)

Run: `npm run build 2>&1 | tail -5`
Expected: build succeeds; `grep -c "Pages/Settings/VatRates.vue" public/build/manifest.json` returns `1`.

- [ ] **Step 2: Write the controller test**

```php
<?php

use App\Models\User;
use App\Models\VatRate;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    VatRate::query()->delete();
});

test('index renders the editor with all rates', function () {
    VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->get('/settings/vat-rates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/VatRates')
            ->has('rates', 1));
});

test('store creates a rate', function () {
    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertRedirect();

    expect(VatRate::where('rate', 8.10)->whereDate('valid_from', '2024-01-01')->exists())->toBeTrue();
});

test('update changes a rate', function () {
    $rate = VatRate::create(['rate' => 8.00, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->patch("/settings/vat-rates/{$rate->id}", ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertRedirect();

    expect((float) $rate->fresh()->rate)->toBe(8.10);
});

test('destroy removes a rate', function () {
    $rate = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    $this->delete("/settings/vat-rates/{$rate->id}")->assertRedirect();

    expect(VatRate::find($rate->id))->toBeNull();
});

test('valid_until before valid_from is rejected', function () {
    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-12-31', 'valid_until' => '2024-01-01'])
        ->assertSessionHasErrors('valid_until');

    expect(VatRate::count())->toBe(0);
});

test('overlapping validity windows are rejected', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    // New open-ended row starting inside the existing window.
    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2023-06-01', 'valid_until' => null])
        ->assertSessionHasErrors('valid_from');

    expect(VatRate::count())->toBe(1);
});

test('the overlap check excludes the row being edited', function () {
    $rate = VatRate::create(['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null]);

    // Editing the same row to itself must not trip the overlap rule.
    $this->patch("/settings/vat-rates/{$rate->id}", ['rate' => 8.20, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors();

    expect((float) $rate->fresh()->rate)->toBe(8.20);
});

test('a new non-overlapping future window is accepted', function () {
    VatRate::create(['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31']);

    $this->post('/settings/vat-rates', ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null])
        ->assertSessionHasNoErrors();

    expect(VatRate::count())->toBe(2);
});
```

- [ ] **Step 3: Run the suite — expect GREEN**

Run: `php artisan test 2>&1 | tail -20`
Expected: all tests pass, including the new `VatRateControllerTest`.

- [ ] **Step 4: Commit**

```bash
git add public/build tests/Feature/Http/VatRateControllerTest.php
git commit -m "feat(vat): VAT rate editor tests + built assets"
```

---

## Final verification

- [ ] Run the full suite once more: `php artisan test 2>&1 | tail -20` — all green.
- [ ] Grep for stragglers: `grep -rn "vat_code\|vat_label\|vat_exempt\|vatBreakdown\|computeFromRates\|snapshotFor\|defaultVatCode\|activeVatRates\|vatLabelForCode\|optionsForDate\|defaultForDate" app/ resources/ database/ tests/` — should return nothing (other than this plan/spec).
- [ ] Manually click through `/settings/vat-rates`: add a future rate, edit one, try an overlapping window (expect inline error), delete one.
- [ ] Create a draft invoice and estimate; confirm the totals show a single `MwSt {rate}%` row and the PDF renders one VAT line.
```
