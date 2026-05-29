# Recurring Invoices (i) — Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the recurring-invoice schedule model, the period/advance math, the generator service, and the daily catch-up command — fully testable from the artisan command and tinker, with no UI.

**Architecture:** A `RecurringInvoice` schedule (with `recurring_invoice_lines`) stores a fixed-line invoice template plus a cadence and `next_run_on`. A daily command `ernte:invoices:generate-recurring` finds due, non-paused schedules and, per schedule, loops while `next_run_on <= today` (catch-up), calling `RecurringInvoiceGenerator`. The generator reuses the existing `InvoiceBuilder::createDraft` to produce ordinary `Invoice` rows stamped with a `recurring_invoice_id` back-reference, optionally auto-sending via the existing `InvoiceLifecycle::issue`. Calendar-period math (`monthly`/`quarterly`/`half-yearly`/`yearly`) lives in a pure `App\Support\BillingPeriod` helper.

**Tech Stack:** Laravel 12, MariaDB, Pest 4, Carbon.

**Spec:** `docs/superpowers/specs/2026-05-29-recurring-invoices-design.md`

**Conventions observed:** money in `*_rappen` integers; line `amount` is recomputed server-side, never trusted from input; events are rows in `invoice_events`; tests are Pest with model factories; migrations are timestamped `2026_05_29_*`.

---

## File structure

- Create: `database/migrations/2026_05_29_120000_create_recurring_invoices.php`
- Create: `database/migrations/2026_05_29_120001_create_recurring_invoice_lines.php`
- Create: `database/migrations/2026_05_29_120002_add_recurring_invoice_id_to_invoices.php`
- Create: `app/Models/RecurringInvoice.php`
- Create: `app/Models/RecurringInvoiceLine.php`
- Modify: `app/Models/Invoice.php` (add `recurring_invoice_id` to `$fillable`, add `recurringInvoice()` relation)
- Create: `database/factories/RecurringInvoiceFactory.php`
- Create: `database/factories/RecurringInvoiceLineFactory.php`
- Create: `app/Support/BillingPeriod.php`
- Modify: `app/Services/Invoicing/InvoiceBuilder.php` (optional `?float $vatRate` arg on `createDraft`)
- Create: `app/Services/Invoicing/RecurringInvoiceGenerator.php`
- Create: `app/Console/Commands/GenerateRecurringInvoicesCommand.php`
- Modify: `routes/console.php` (schedule the command)
- Tests:
  - `tests/Unit/Support/BillingPeriodTest.php`
  - `tests/Feature/Services/RecurringInvoiceGeneratorTest.php`
  - `tests/Feature/Console/GenerateRecurringInvoicesCommandTest.php`
  - `tests/Feature/Services/InvoiceBuilderTest.php` (add one case for the new arg)

> **Test runner:** this project runs tests via DDEV. Use `ddev artisan test --filter=…`. If `ddev` is unavailable in your shell, fall back to `php artisan test --filter=…`.

---

### Task 1: Migrations

**Files:**
- Create: `database/migrations/2026_05_29_120000_create_recurring_invoices.php`
- Create: `database/migrations/2026_05_29_120001_create_recurring_invoice_lines.php`
- Create: `database/migrations/2026_05_29_120002_add_recurring_invoice_id_to_invoices.php`

- [ ] **Step 1: Write `create_recurring_invoices` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title')->nullable();   // may contain the literal {period}
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->decimal('vat_rate', 5, 2)->default(8.10);
            $table->enum('cadence', ['monthly', 'quarterly', 'half-yearly', 'yearly']);
            $table->unsignedTinyInteger('anchor_day');     // 1..31, clamped to month length at generation
            $table->date('next_run_on');
            $table->date('last_generated_on')->nullable();
            $table->boolean('auto_send')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index('next_run_on');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
```

- [ ] **Step 2: Write `create_recurring_invoice_lines` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained('recurring_invoices')->cascadeOnDelete();
            $table->text('description');
            $table->decimal('hours', 10, 2);
            $table->unsignedBigInteger('rate_rappen');
            $table->boolean('vat_exempt')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['recurring_invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_lines');
    }
};
```

- [ ] **Step 3: Write `add_recurring_invoice_id_to_invoices` migration**

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
            $table->foreignId('recurring_invoice_id')
                ->nullable()
                ->after('project_id')
                ->constrained('recurring_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
        });
    }
};
```

- [ ] **Step 4: Run the migrations**

Run: `ddev artisan migrate`
Expected: three new migrations run with `DONE`. No errors.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_29_120000_create_recurring_invoices.php \
        database/migrations/2026_05_29_120001_create_recurring_invoice_lines.php \
        database/migrations/2026_05_29_120002_add_recurring_invoice_id_to_invoices.php
git commit -m "feat(recurring): add recurring_invoices schema and invoice back-reference"
```

---

### Task 2: Models

**Files:**
- Create: `app/Models/RecurringInvoice.php`
- Create: `app/Models/RecurringInvoiceLine.php`
- Modify: `app/Models/Invoice.php`

- [ ] **Step 1: Write `RecurringInvoice` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'project_id', 'title', 'notes', 'currency', 'vat_rate',
        'cadence', 'anchor_day', 'next_run_on', 'last_generated_on',
        'auto_send', 'paused_at',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'anchor_day' => 'integer',
        'next_run_on' => 'date',
        'last_generated_on' => 'date',
        'auto_send' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(RecurringInvoiceLine::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }

    public function isPaused(): bool { return $this->paused_at !== null; }

    /** Active schedules whose next run is on or before $date. */
    public function scopeDue(Builder $q, $date) { return $q->whereNull('paused_at')->whereDate('next_run_on', '<=', $date); }
}
```

- [ ] **Step 2: Write `RecurringInvoiceLine` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_invoice_id', 'description', 'hours', 'rate_rappen',
        'vat_exempt', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'vat_exempt' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function recurringInvoice() { return $this->belongsTo(RecurringInvoice::class); }
}
```

- [ ] **Step 3: Modify `Invoice` model**

In `app/Models/Invoice.php`, add `'recurring_invoice_id'` to `$fillable` (e.g. on the `'number', 'client_id', 'project_id',` line make it `'number', 'client_id', 'project_id', 'recurring_invoice_id',`), and add this relation alongside the others (after `public function project()`):

```php
    public function recurringInvoice() { return $this->belongsTo(RecurringInvoice::class); }
```

- [ ] **Step 4: Sanity-check via tinker**

Run: `ddev artisan tinker --execute="echo App\Models\RecurringInvoice::query()->toSql();"`
Expected: prints `select * from \`recurring_invoices\`` (no error → class loads, table resolves).

- [ ] **Step 5: Commit**

```bash
git add app/Models/RecurringInvoice.php app/Models/RecurringInvoiceLine.php app/Models/Invoice.php
git commit -m "feat(recurring): add RecurringInvoice/RecurringInvoiceLine models and invoice relation"
```

---

### Task 3: Factories

**Files:**
- Create: `database/factories/RecurringInvoiceFactory.php`
- Create: `database/factories/RecurringInvoiceLineFactory.php`

- [ ] **Step 1: Write `RecurringInvoiceFactory`**

```php
<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RecurringInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'title' => 'Hosting — {period}',
            'notes' => null,
            'currency' => 'CHF',
            'vat_rate' => 8.10,
            'cadence' => 'monthly',
            'anchor_day' => 1,
            'next_run_on' => now()->startOfMonth()->toDateString(),
            'last_generated_on' => null,
            'auto_send' => false,
            'paused_at' => null,
        ];
    }

    public function paused(): self { return $this->state(fn () => ['paused_at' => now()]); }

    public function autoSend(): self { return $this->state(fn () => ['auto_send' => true]); }

    public function cadence(string $cadence, int $anchorDay): self
    {
        return $this->state(fn () => ['cadence' => $cadence, 'anchor_day' => $anchorDay]);
    }
}
```

- [ ] **Step 2: Write `RecurringInvoiceLineFactory`**

```php
<?php

namespace Database\Factories;

use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringInvoiceLineFactory extends Factory
{
    protected $model = RecurringInvoiceLine::class;

    public function definition(): array
    {
        return [
            'recurring_invoice_id' => RecurringInvoice::factory(),
            'description' => 'Hosting',
            'hours' => 1,
            'rate_rappen' => 10000, // 100 CHF
            'vat_exempt' => false,
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 3: Verify factories build**

Run: `ddev artisan tinker --execute="App\Models\RecurringInvoice::factory()->has(App\Models\RecurringInvoiceLine::factory()->count(2),'lines')->create(); echo App\Models\RecurringInvoiceLine::count();"`
Expected: prints `2`.

- [ ] **Step 4: Commit**

```bash
git add database/factories/RecurringInvoiceFactory.php database/factories/RecurringInvoiceLineFactory.php
git commit -m "test(recurring): add RecurringInvoice/Line factories"
```

---

### Task 4: `BillingPeriod` helper (period + advance math)

**Files:**
- Create: `app/Support/BillingPeriod.php`
- Test: `tests/Unit/Support/BillingPeriodTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;

test('for() returns calendar period and label per cadence', function () {
    $d = Carbon::parse('2026-06-15');

    $m = BillingPeriod::for('monthly', $d);
    expect($m['start']->toDateString())->toBe('2026-06-01');
    expect($m['end']->toDateString())->toBe('2026-06-30');
    expect($m['label'])->toBe('June 2026');

    $q = BillingPeriod::for('quarterly', $d);
    expect($q['start']->toDateString())->toBe('2026-04-01');
    expect($q['end']->toDateString())->toBe('2026-06-30');
    expect($q['label'])->toBe('Q2 2026');

    $h = BillingPeriod::for('half-yearly', $d);
    expect($h['start']->toDateString())->toBe('2026-01-01');
    expect($h['end']->toDateString())->toBe('2026-06-30');
    expect($h['label'])->toBe('H1 2026');

    $h2 = BillingPeriod::for('half-yearly', Carbon::parse('2026-09-10'));
    expect($h2['start']->toDateString())->toBe('2026-07-01');
    expect($h2['end']->toDateString())->toBe('2026-12-31');
    expect($h2['label'])->toBe('H2 2026');

    $y = BillingPeriod::for('yearly', $d);
    expect($y['start']->toDateString())->toBe('2026-01-01');
    expect($y['end']->toDateString())->toBe('2026-12-31');
    expect($y['label'])->toBe('2026');
});

test('advance() steps by cadence and clamps the anchor day to short months', function () {
    // Monthly from Jan 31 → Feb 28 (clamped) → Mar 31 (springs back).
    $feb = BillingPeriod::advance('monthly', Carbon::parse('2026-01-31'), 31);
    expect($feb->toDateString())->toBe('2026-02-28');
    $mar = BillingPeriod::advance('monthly', $feb, 31);
    expect($mar->toDateString())->toBe('2026-03-31');

    expect(BillingPeriod::advance('quarterly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2026-05-15');
    expect(BillingPeriod::advance('half-yearly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2026-08-15');
    expect(BillingPeriod::advance('yearly', Carbon::parse('2026-02-15'), 15)->toDateString())->toBe('2027-02-15');
});

test('nextRunOnOrAfter() snaps a past start forward without backfilling', function () {
    $start = Carbon::parse('2026-01-10');
    $from = Carbon::parse('2026-05-29');
    expect(BillingPeriod::nextRunOnOrAfter('monthly', $start, $from)->toDateString())->toBe('2026-06-10');

    // A future start is returned unchanged.
    expect(BillingPeriod::nextRunOnOrAfter('monthly', Carbon::parse('2026-07-01'), $from)->toDateString())->toBe('2026-07-01');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=BillingPeriodTest`
Expected: FAIL — "Class \"App\\Support\\BillingPeriod\" not found".

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class BillingPeriod
{
    /**
     * The calendar period containing $date (advance billing).
     *
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public static function for(string $cadence, Carbon $date): array
    {
        $d = $date->copy()->startOfDay();

        return match ($cadence) {
            'monthly' => [
                'start' => $d->copy()->startOfMonth(),
                'end' => $d->copy()->endOfMonth()->startOfDay(),
                'label' => $d->format('F Y'),
            ],
            'quarterly' => [
                'start' => $d->copy()->startOfQuarter(),
                'end' => $d->copy()->endOfQuarter()->startOfDay(),
                'label' => 'Q' . $d->quarter . ' ' . $d->year,
            ],
            'half-yearly' => self::half($d),
            'yearly' => [
                'start' => $d->copy()->startOfYear(),
                'end' => $d->copy()->endOfYear()->startOfDay(),
                'label' => (string) $d->year,
            ],
            default => throw new \InvalidArgumentException("Unknown cadence: {$cadence}"),
        };
    }

    /** The next occurrence date after $date, preserving the anchor day (clamped to month length). */
    public static function advance(string $cadence, Carbon $date, int $anchorDay): Carbon
    {
        $months = self::months($cadence);
        $target = $date->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $target->setDay(min($anchorDay, $target->daysInMonth))->startOfDay();
    }

    /** The first occurrence on or after $from, stepping from $start by the cadence (no history backfill). */
    public static function nextRunOnOrAfter(string $cadence, Carbon $start, Carbon $from): Carbon
    {
        $anchorDay = $start->day;
        $next = $start->copy()->startOfDay();
        $floor = $from->copy()->startOfDay();
        $guard = 0;

        while ($next->lt($floor) && $guard++ < 1200) {
            $next = self::advance($cadence, $next, $anchorDay);
        }

        return $next;
    }

    private static function months(string $cadence): int
    {
        return match ($cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'half-yearly' => 6,
            'yearly' => 12,
            default => throw new \InvalidArgumentException("Unknown cadence: {$cadence}"),
        };
    }

    private static function half(Carbon $d): array
    {
        $firstHalf = $d->month <= 6;

        return [
            'start' => $firstHalf ? $d->copy()->startOfYear() : $d->copy()->setDate($d->year, 7, 1)->startOfDay(),
            'end' => $firstHalf ? $d->copy()->setDate($d->year, 6, 30)->startOfDay() : $d->copy()->endOfYear()->startOfDay(),
            'label' => ($firstHalf ? 'H1 ' : 'H2 ') . $d->year,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter=BillingPeriodTest`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Support/BillingPeriod.php tests/Unit/Support/BillingPeriodTest.php
git commit -m "feat(recurring): add BillingPeriod period/advance helper"
```

---

### Task 5: Thread a VAT rate through `InvoiceBuilder::createDraft`

The generator must stamp each invoice with the schedule's `vat_rate`, but `createDraft` currently hard-codes the business-profile default. Add an optional trailing argument that defaults to the profile (back-compatible with every existing caller).

**Files:**
- Modify: `app/Services/Invoicing/InvoiceBuilder.php`
- Test: `tests/Feature/Services/InvoiceBuilderTest.php`

- [ ] **Step 1: Write the failing test** (append to `InvoiceBuilderTest.php`)

```php
test('createDraft stamps an explicit vat rate when provided', function () {
    $invoice = $this->svc->createDraft(
        client: $this->client,
        project: null,
        periodStart: now()->subDay()->toDateString(),
        periodEnd: now()->toDateString(),
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
        entryIds: [],
        title: null,
        notes: null,
        vatRate: 2.60,
    );

    expect((float) $invoice->vat_rate)->toBe(2.60);
    expect($invoice->vat_rappen)->toBe(260); // 2.60% of 10000
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="stamps an explicit vat rate"`
Expected: FAIL — unknown named argument `$vatRate` (or wrong vat_rate value).

- [ ] **Step 3: Implement the optional argument**

In `app/Services/Invoicing/InvoiceBuilder.php`, change the `createDraft` signature to add a trailing `?float $vatRate = null`:

```php
    public function createDraft(
        Client $client,
        ?Project $project,
        string $periodStart,
        string $periodEnd,
        array $lines,
        array $entryIds,
        ?string $title = null,
        ?string $notes = null,
        ?float $vatRate = null,
    ): Invoice {
```

Add `$vatRate` to the transaction `use (...)` list, and change the `'vat_rate'` line in the `Invoice::create([...])` call from:

```php
                'vat_rate' => $profile->default_vat_rate,
```

to:

```php
                'vat_rate' => $vatRate ?? $profile->default_vat_rate,
```

(The totals computation already reads `(float) $invoice->vat_rate`, so no further change is needed.)

- [ ] **Step 4: Run the full builder test to verify pass + no regressions**

Run: `ddev artisan test --filter=InvoiceBuilderTest`
Expected: PASS (all previously-passing cases still pass, plus the new one).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/InvoiceBuilder.php tests/Feature/Services/InvoiceBuilderTest.php
git commit -m "feat(invoicing): allow createDraft to take an explicit vat rate"
```

---

### Task 6: `RecurringInvoiceGenerator` service

**Files:**
- Create: `app/Services/Invoicing/RecurringInvoiceGenerator.php`
- Test: `tests/Feature/Services/RecurringInvoiceGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->gen = app(RecurringInvoiceGenerator::class);
    Mail::fake();
});

function schedule(array $overrides = [], array $lineOverrides = []): RecurringInvoice
{
    $client = Client::factory()->create($overrides['client'] ?? []);
    unset($overrides['client']);
    $schedule = RecurringInvoice::factory()->for($client)->create($overrides);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(array_merge([
        'description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000, 'vat_exempt' => false, 'sort_order' => 0,
    ], $lineOverrides));

    return $schedule->fresh('lines');
}

test('generate() creates a draft invoice from template lines with the schedule vat rate', function () {
    $s = schedule(['vat_rate' => 8.10, 'cadence' => 'monthly', 'anchor_day' => 1]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->status)->toBe('draft');
    expect($invoice->recurring_invoice_id)->toBe($s->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->amount_rappen)->toBe(10000); // 1 * 10000, recomputed
    expect($invoice->period_start->toDateString())->toBe('2026-06-01');
    expect($invoice->period_end->toDateString())->toBe('2026-06-30');
    expect((float) $invoice->vat_rate)->toBe(8.10);
    expect($invoice->total_rappen)->toBe(10810);
});

test('generate() interpolates {period} into the title', function () {
    $s = schedule(['title' => 'Hosting — {period}', 'cadence' => 'quarterly', 'anchor_day' => 1]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-04-01'));

    expect($invoice->title)->toBe('Hosting — Q2 2026');
});

test('generate() advances next_run_on and stamps last_generated_on', function () {
    $s = schedule(['cadence' => 'monthly', 'anchor_day' => 15, 'next_run_on' => '2026-06-15']);

    $this->gen->generate($s, Carbon::parse('2026-06-15'));
    $s->refresh();

    expect($s->next_run_on->toDateString())->toBe('2026-07-15');
    expect($s->last_generated_on->toDateString())->toBe('2026-06-15');
});

test('auto_send issues and emails the invoice when the client has an email', function () {
    $s = schedule(['auto_send' => true, 'client' => ['email' => 'client@example.test']]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice->status)->toBe('sent');
    Mail::assertSent(\App\Mail\InvoiceMail::class);
});

test('auto_send leaves a draft and logs recurring_autosend_skipped when the client has no email', function () {
    $s = schedule(['auto_send' => true, 'client' => ['email' => null]]);

    $invoice = $this->gen->generate($s, Carbon::parse('2026-06-01'));

    expect($invoice->status)->toBe('draft');
    expect($invoice->events()->where('kind', 'recurring_autosend_skipped')->count())->toBe(1);
    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=RecurringInvoiceGeneratorTest`
Expected: FAIL — "Class \"App\\Services\\Invoicing\\RecurringInvoiceGenerator\" not found".

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceGenerator
{
    public function __construct(
        private InvoiceBuilder $builder,
        private InvoiceLifecycle $lifecycle,
    ) {}

    /**
     * Generate one invoice for the occurrence on $runDate, advance the schedule,
     * and (if auto_send) attempt to issue + email it. A failed auto-send leaves
     * the invoice as a draft and logs a recurring_autosend_skipped event.
     */
    public function generate(RecurringInvoice $schedule, Carbon $runDate): Invoice
    {
        $schedule->loadMissing([
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'client',
            'project',
        ]);

        $period = BillingPeriod::for($schedule->cadence, $runDate);

        $title = $schedule->title !== null
            ? str_replace('{period}', $period['label'], $schedule->title)
            : null;

        $lines = $schedule->lines->map(fn (RecurringInvoiceLine $l) => [
            'description' => $l->description,
            'hours' => (float) $l->hours,
            'rate_rappen' => (int) $l->rate_rappen,
            'vat_exempt' => (bool) $l->vat_exempt,
        ])->all();

        $invoice = DB::transaction(function () use ($schedule, $period, $title, $lines, $runDate) {
            $invoice = $this->builder->createDraft(
                client: $schedule->client,
                project: $schedule->project,
                periodStart: $period['start']->toDateString(),
                periodEnd: $period['end']->toDateString(),
                lines: $lines,
                entryIds: [],
                title: $title,
                notes: $schedule->notes,
                vatRate: (float) $schedule->vat_rate,
            );

            $invoice->recurring_invoice_id = $schedule->id;
            $invoice->save();

            $schedule->last_generated_on = $runDate->toDateString();
            $schedule->next_run_on = BillingPeriod::advance(
                $schedule->cadence,
                Carbon::parse($schedule->next_run_on),
                $schedule->anchor_day,
            )->toDateString();
            $schedule->save();

            return $invoice;
        });

        if ($schedule->auto_send) {
            try {
                $this->lifecycle->issue($invoice->fresh('client'));
            } catch (\DomainException $e) {
                InvoiceEvent::create([
                    'invoice_id' => $invoice->id,
                    'kind' => 'recurring_autosend_skipped',
                    'occurred_at' => now(),
                    'payload' => ['reason' => $e->getMessage()],
                ]);
            }
        }

        return $invoice->fresh(['lines', 'events']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter=RecurringInvoiceGeneratorTest`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/RecurringInvoiceGenerator.php tests/Feature/Services/RecurringInvoiceGeneratorTest.php
git commit -m "feat(recurring): add RecurringInvoiceGenerator service"
```

---

### Task 7: `ernte:invoices:generate-recurring` command + schedule

**Files:**
- Create: `app/Console/Commands/GenerateRecurringInvoicesCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/GenerateRecurringInvoicesCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    Mail::fake();
    Carbon::setTestNow('2026-06-20');
});

afterEach(fn () => Carbon::setTestNow());

function makeSchedule(array $overrides = []): RecurringInvoice
{
    $schedule = RecurringInvoice::factory()->create($overrides);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create([
        'description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000,
    ]);

    return $schedule->fresh('lines');
}

test('generates invoices for due schedules and advances them', function () {
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-06-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(1);
    expect(RecurringInvoice::first()->next_run_on->toDateString())->toBe('2026-07-01');
});

test('skips paused schedules', function () {
    makeSchedule(['next_run_on' => '2026-06-01'])->update(['paused_at' => now()]);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

test('does not touch schedules whose next run is in the future', function () {
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-07-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

test('catches up multiple missed monthly periods in one run', function () {
    // next_run three months back; today is 2026-06-20 → generate Mar, Apr, May, Jun = 4.
    makeSchedule(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-03-01']);

    $this->artisan('ernte:invoices:generate-recurring')->assertExitCode(0);

    expect(Invoice::count())->toBe(4);
    expect(RecurringInvoice::first()->next_run_on->toDateString())->toBe('2026-07-01');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=GenerateRecurringInvoicesCommandTest`
Expected: FAIL — command `ernte:invoices:generate-recurring` is not defined.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\RecurringInvoice;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'ernte:invoices:generate-recurring';

    protected $description = 'Generate invoices from due recurring schedules (catch-up safe).';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $today = Carbon::today();
        $generated = 0;
        $schedules = 0;
        $skipped = 0;

        RecurringInvoice::query()
            ->due($today)
            ->orderBy('id')
            ->get()
            ->each(function (RecurringInvoice $schedule) use ($generator, $today, &$generated, &$schedules, &$skipped) {
                $schedules++;
                $guard = 0;

                while (! $schedule->isPaused()
                    && Carbon::parse($schedule->next_run_on)->lte($today)
                    && $guard++ < 60) {
                    $runDate = Carbon::parse($schedule->next_run_on);
                    $invoice = $generator->generate($schedule, $runDate);
                    $generated++;

                    if ($schedule->auto_send && $invoice->status !== 'sent') {
                        $skipped++;
                    }

                    // generate() advanced next_run_on; reload for the next loop iteration.
                    $schedule->refresh();
                }
            });

        $this->info("Generated {$generated} invoice(s) across {$schedules} schedule(s); {$skipped} auto-send(s) skipped.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter=GenerateRecurringInvoicesCommandTest`
Expected: PASS (4 passed).

- [ ] **Step 5: Schedule the command**

In `routes/console.php`, add below the existing `Schedule::command(...)` lines:

```php
Schedule::command('ernte:invoices:generate-recurring')->dailyAt('06:00');
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `ddev artisan schedule:list`
Expected: output includes `ernte:invoices:generate-recurring` at `06:00`.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/GenerateRecurringInvoicesCommand.php routes/console.php tests/Feature/Console/GenerateRecurringInvoicesCommandTest.php
git commit -m "feat(recurring): add daily generate-recurring command and schedule it"
```

---

### Task 8: Full suite + finish

- [ ] **Step 1: Run the whole test suite**

Run: `ddev artisan test`
Expected: PASS — no regressions in invoice/estimate/harvest suites.

- [ ] **Step 2: Manual smoke via tinker (optional sanity)**

Run:
```bash
ddev artisan tinker --execute="\$s = App\Models\RecurringInvoice::factory()->has(App\Models\RecurringInvoiceLine::factory(),'lines')->create(['next_run_on'=>now()->toDateString(),'cadence'=>'monthly','anchor_day'=>now()->day]); Artisan::call('ernte:invoices:generate-recurring'); echo App\Models\Invoice::whereNotNull('recurring_invoice_id')->count();"
```
Expected: prints `1`.

---

## Self-review notes (for the implementer)

- All four cadences (`monthly`/`quarterly`/`half-yearly`/`yearly`) are covered by `BillingPeriodTest`.
- No-backfill lives in `BillingPeriod::nextRunOnOrAfter` (tested here) and is wired into the UI in plan (ii).
- The `recurring_autosend_skipped` event surfaces failed auto-sends; the invoice remains a usable draft.
- Generated invoices reuse `InvoiceNumberer`/`QrReferenceGenerator` via `createDraft`, so numbering and QR are unchanged.
