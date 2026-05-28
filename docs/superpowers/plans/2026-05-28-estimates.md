# Estimates Feature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Estimates" (Offerte) feature that lets the user create a draft quote with manually-entered line items, send it to a client as a PDF, track accept/decline, and convert an accepted estimate into a draft invoice in one click.

**Architecture:** A parallel `Estimate` stack mirroring the shipped invoice stack (`app/Services/Invoicing/*`, `app/Http/Controllers/InvoiceController.php`, `resources/js/Pages/Invoices/*`), minus everything payment-related (no Swiss QR-bill, no reminders, no time-entry linking). The one change to existing code is extracting `InvoiceBuilder::computeTotals` into a shared `App\Support\LineTotals` helper that both features call. `expired` is a computed flag (`status = sent` AND `valid_until` past), never a stored status.

**Tech Stack:** Laravel 12, Inertia + Vue 3, MariaDB (DDEV), Pest, Spatie/Browsershot for PDF.

**Reference (read before starting):** The design spec at `docs/superpowers/specs/2026-05-28-estimates-design.md` and the existing invoice implementation it mirrors.

**Conventions to follow (observed in the invoice code):**
- Money stored in **rappen** (integer); display divides by 100. All financial math is server-side; client-submitted amounts are never trusted.
- Inertia pages referenced by **literal path strings** (`'Estimates/Index'`), routes by **literal path strings** in Vue (not Ziggy `route()`).
- GET show/preview/pdf routes bind by `{estimate:number}`; mutation routes (update/send/accept/decline/convert) bind by `{estimate}` (id).
- Tests are Pest, `use(RefreshDatabase)`, run against MariaDB `db_test`. PDF-rendering tests are tagged `->group('browsershot')`.

**How to run tests:**
- Fast suite (skip Chromium PDF render): `ddev artisan test --exclude-group browsershot`
- A single test: `ddev artisan test --filter='the test name'`
- Browsershot tests: `ddev artisan test --group browsershot`

---

## Task 1: Database migrations + schema test

**Files:**
- Create: `database/migrations/2026_05_28_120000_create_estimate_counters.php`
- Create: `database/migrations/2026_05_28_120001_create_estimates.php`
- Create: `database/migrations/2026_05_28_120002_create_estimate_lines.php`
- Create: `database/migrations/2026_05_28_120003_create_estimate_events.php`
- Test: `tests/Feature/Schema/EstimateStructureTest.php` (added in Task 2, after models exist)

> Note: the migration timestamps must sort **after** the invoice migrations (`2026_05_27_*`). `estimate_counters` must run before `estimates` only matters if a FK referenced it (it does not), but keep the numeric order for readability.

- [ ] **Step 1: Create the counters migration**

`database/migrations/2026_05_28_120000_create_estimate_counters.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_n')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_counters');
    }
};
```

- [ ] **Step 2: Create the estimates migration**

`database/migrations/2026_05_28_120001_create_estimates.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined'])->default('draft');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('vat_rate', 5, 2)->default(8.10);
            $table->unsignedBigInteger('subtotal_rappen')->default(0);
            $table->unsignedBigInteger('vat_rappen')->default(0);
            $table->unsignedBigInteger('total_rappen')->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_id');
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
```

- [ ] **Step 3: Create the estimate_lines migration**

`database/migrations/2026_05_28_120002_create_estimate_lines.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->text('description');
            $table->decimal('hours', 10, 2);
            $table->unsignedBigInteger('rate_rappen');
            $table->unsignedBigInteger('amount_rappen');
            $table->boolean('vat_exempt')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_lines');
    }
};
```

- [ ] **Step 4: Create the estimate_events migration**

`database/migrations/2026_05_28_120003_create_estimate_events.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->enum('kind', ['created', 'sent', 'accepted', 'declined', 'converted', 'pdf_generated']);
            $table->dateTime('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['estimate_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_events');
    }
};
```

- [ ] **Step 5: Run the migrations to verify they apply cleanly**

Run: `ddev artisan migrate`
Expected: four `Migrated:` lines for the estimate tables, no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_28_1200*.php
git commit -m "feat(estimates): add estimates schema (counters, estimates, lines, events)"
```

---

## Task 2: Models + factories + schema test

**Files:**
- Create: `app/Models/Estimate.php`
- Create: `app/Models/EstimateLine.php`
- Create: `app/Models/EstimateEvent.php`
- Create: `app/Models/EstimateCounter.php`
- Create: `database/factories/EstimateFactory.php`
- Create: `database/factories/EstimateLineFactory.php`
- Test: `tests/Feature/Schema/EstimateStructureTest.php`

- [ ] **Step 1: Write the failing schema/model test**

`tests/Feature/Schema/EstimateStructureTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\EstimateLine;
use App\Models\Invoice;

test('estimate has many lines and events', function () {
    $estimate = Estimate::factory()->create();
    EstimateLine::factory()->count(3)->create(['estimate_id' => $estimate->id]);
    EstimateEvent::create(['estimate_id' => $estimate->id, 'kind' => 'created', 'occurred_at' => now()]);

    expect($estimate->fresh()->lines)->toHaveCount(3);
    expect($estimate->fresh()->events)->toHaveCount(1);
});

test('cannot delete a client with estimates (restrictOnDelete)', function () {
    $client = Client::factory()->create();
    Estimate::factory()->create(['client_id' => $client->id]);

    expect(fn () => $client->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('deleting a converted invoice nulls converted_invoice_id', function () {
    $invoice = Invoice::factory()->create();
    $estimate = Estimate::factory()->create(['converted_invoice_id' => $invoice->id]);

    $invoice->delete();

    expect($estimate->fresh()->converted_invoice_id)->toBeNull();
});

test('expired accessor is true only for past-due sent estimates', function () {
    $expired = Estimate::factory()->sent()->create(['valid_until' => now()->subDay()->toDateString()]);
    expect($expired->expired)->toBeTrue();

    $live = Estimate::factory()->sent()->create(['valid_until' => now()->addDay()->toDateString()]);
    expect($live->expired)->toBeFalse();

    $accepted = Estimate::factory()->accepted()->create(['valid_until' => now()->subDay()->toDateString()]);
    expect($accepted->expired)->toBeFalse(); // accepted is terminal, never "expired"
});

test('hours accessor sums line hours', function () {
    $estimate = Estimate::factory()->create();
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 1.5]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 2.25]);

    expect($estimate->fresh()->hours)->toBe(3.75);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateStructureTest'`
Expected: FAIL — `Class "App\Models\Estimate" not found`.

- [ ] **Step 3: Create the Estimate model**

`app/Models/Estimate.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Estimate extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'project_id',
        'issued_on', 'valid_until',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'total_rappen',
        'notes', 'sent_at', 'decided_at', 'converted_invoice_id', 'pdf_path',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(EstimateLine::class); }
    public function events() { return $this->hasMany(EstimateEvent::class); }
    public function convertedInvoice() { return $this->belongsTo(Invoice::class, 'converted_invoice_id'); }

    public function getExpiredAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function getHoursAttribute(): float
    {
        return round((float) $this->lines->sum('hours'), 2);
    }

    public function scopeOpen($q)     { return $q->where('status', 'sent'); }
    public function scopeAccepted($q) { return $q->where('status', 'accepted'); }
    public function scopeDeclined($q) { return $q->where('status', 'declined'); }
}
```

- [ ] **Step 4: Create the EstimateLine model**

`app/Models/EstimateLine.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstimateLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id', 'description', 'hours', 'rate_rappen', 'amount_rappen',
        'vat_exempt', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'vat_exempt' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function estimate() { return $this->belongsTo(Estimate::class); }
}
```

- [ ] **Step 5: Create the EstimateEvent model**

`app/Models/EstimateEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimateEvent extends Model
{
    protected $fillable = ['estimate_id', 'kind', 'occurred_at', 'payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function estimate() { return $this->belongsTo(Estimate::class); }
}
```

- [ ] **Step 6: Create the EstimateCounter model**

`app/Models/EstimateCounter.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimateCounter extends Model
{
    protected $primaryKey = 'year';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['year', 'last_n'];
    protected $casts = ['year' => 'integer', 'last_n' => 'integer'];
}
```

- [ ] **Step 7: Create the EstimateFactory**

`database/factories/EstimateFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EstimateFactory extends Factory
{
    protected $model = Estimate::class;

    public function definition(): array
    {
        return [
            // Test-only number format; production numbers come from EstimateNumberer (Task 4).
            'number' => 'OF-' . now()->year . '-T' . str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'client_id' => Client::factory(),
            'status' => 'draft',
            'currency' => 'CHF',
            'vat_rate' => 8.10,
            'subtotal_rappen' => 0,
            'vat_rappen' => 0,
            'total_rappen' => 0,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'issued_on' => now()->subDays(3)->toDateString(),
            'valid_until' => now()->addDays(27)->toDateString(),
            'sent_at' => now()->subDays(3),
        ]);
    }

    public function accepted(): self
    {
        return $this->state(fn () => [
            'status' => 'accepted',
            'issued_on' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'sent_at' => now()->subDays(10),
            'decided_at' => now()->subDays(2),
        ]);
    }

    public function declined(): self
    {
        return $this->state(fn () => [
            'status' => 'declined',
            'issued_on' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'sent_at' => now()->subDays(10),
            'decided_at' => now()->subDays(1),
        ]);
    }
}
```

- [ ] **Step 8: Create the EstimateLineFactory**

`database/factories/EstimateLineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class EstimateLineFactory extends Factory
{
    protected $model = EstimateLine::class;

    public function definition(): array
    {
        $hours = $this->faker->randomFloat(2, 1, 20);
        $rate = 14500; // 145 CHF/h
        return [
            'estimate_id' => Estimate::factory(),
            'description' => ucfirst($this->faker->bs()),
            'hours' => $hours,
            'rate_rappen' => $rate,
            'amount_rappen' => (int) round($hours * $rate),
            'vat_exempt' => false,
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 9: Run the schema test to verify it passes**

Run: `ddev artisan test --filter='EstimateStructureTest'`
Expected: PASS (5 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Models/Estimate.php app/Models/EstimateLine.php app/Models/EstimateEvent.php app/Models/EstimateCounter.php database/factories/EstimateFactory.php database/factories/EstimateLineFactory.php tests/Feature/Schema/EstimateStructureTest.php
git commit -m "feat(estimates): add Estimate models, factories, schema test"
```

---

## Task 3: Extract shared LineTotals helper

**Goal:** Lift the totals math out of `InvoiceBuilder::computeTotals` into `App\Support\LineTotals` so both invoices and estimates share one tested implementation. `InvoiceBuilder::computeTotals` stays as a thin delegating wrapper so every existing invoice call site and test keeps working unchanged.

**Files:**
- Create: `app/Support/LineTotals.php`
- Modify: `app/Services/Invoicing/InvoiceBuilder.php` (the `computeTotals` body, lines 33-51)
- Test: `tests/Feature/Support/LineTotalsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/LineTotalsTest.php`:

```php
<?php

use App\Support\LineTotals;

test('compute respects vat_exempt lines', function () {
    $totals = LineTotals::compute(
        lineAmounts: [10000, 5000],
        vatExempts: [false, true],
        vatRate: 8.10,
    );

    expect($totals['subtotal_rappen'])->toBe(15000);
    expect($totals['vat_rappen'])->toBe(810);   // 8.10% of the 10000 taxable line only
    expect($totals['total_rappen'])->toBe(15810);
});

test('compute with all taxable lines matches the original VAT formula', function () {
    $totals = LineTotals::compute(
        lineAmounts: [29000],
        vatExempts: [false],
        vatRate: 8.10,
    );

    expect($totals)->toMatchArray([
        'subtotal_rappen' => 29000,
        'vat_rappen'      => 2349,
        'total_rappen'    => 31349,
    ]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='LineTotalsTest'`
Expected: FAIL — `Class "App\Support\LineTotals" not found`.

- [ ] **Step 3: Create the LineTotals helper**

`app/Support/LineTotals.php`:

```php
<?php

namespace App\Support;

class LineTotals
{
    /**
     * Compute subtotal, VAT, and total from arrays of line amounts and exempt flags.
     *
     * Exempt lines are included in the subtotal but excluded from the VAT base.
     *
     * @param  int[]   $lineAmounts  Amount in rappen for each line.
     * @param  bool[]  $vatExempts   Parallel exempt flag for each line.
     * @param  float   $vatRate      VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function compute(array $lineAmounts, array $vatExempts, float $vatRate): array
    {
        $taxable = 0;
        $exempt = 0;
        foreach ($lineAmounts as $i => $amt) {
            if (!empty($vatExempts[$i])) {
                $exempt += $amt;
            } else {
                $taxable += $amt;
            }
        }
        $subtotal = $taxable + $exempt;
        $vat = (int) round($taxable * $vatRate / 100);
        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen'      => $vat,
            'total_rappen'    => $subtotal + $vat,
        ];
    }
}
```

- [ ] **Step 4: Make InvoiceBuilder::computeTotals delegate to it**

In `app/Services/Invoicing/InvoiceBuilder.php`, replace the body of the `computeTotals` method (currently lines 33-51) so it delegates. Keep the method signature and docblock. Add `use App\Support\LineTotals;` to the imports at the top.

New method body:

```php
    public static function computeTotals(array $lineAmounts, array $vatExempts, float $vatRate): array
    {
        return \App\Support\LineTotals::compute($lineAmounts, $vatExempts, $vatRate);
    }
```

(Using the fully-qualified name avoids needing to confirm the import block; an explicit `use App\Support\LineTotals;` is equivalent and also fine.)

- [ ] **Step 5: Run the new test plus the full invoice suite to confirm nothing regressed**

Run: `ddev artisan test --filter='LineTotalsTest'`
Expected: PASS (2 tests).

Run: `ddev artisan test --exclude-group browsershot --filter='Invoice'`
Expected: PASS — all existing invoice tests still green (they call `InvoiceBuilder::computeTotals`, which now delegates).

- [ ] **Step 6: Commit**

```bash
git add app/Support/LineTotals.php app/Services/Invoicing/InvoiceBuilder.php tests/Feature/Support/LineTotalsTest.php
git commit -m "refactor: extract LineTotals helper shared by invoices and estimates"
```

---

## Task 4: EstimateNumberer service

**Files:**
- Create: `app/Services/Estimating/EstimateNumberer.php`
- Test: `tests/Feature/Services/EstimateNumbererTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/EstimateNumbererTest.php`:

```php
<?php

use App\Services\Estimating\EstimateNumberer;

test('allocates sequential, prefixed, zero-padded numbers per year', function () {
    $numberer = app(EstimateNumberer::class);

    expect($numberer->nextFor(2026))->toBe('OF-2026-001');
    expect($numberer->nextFor(2026))->toBe('OF-2026-002');
    expect($numberer->nextFor(2026))->toBe('OF-2026-003');
});

test('sequences are independent per year', function () {
    $numberer = app(EstimateNumberer::class);

    expect($numberer->nextFor(2026))->toBe('OF-2026-001');
    expect($numberer->nextFor(2027))->toBe('OF-2027-001');
    expect($numberer->nextFor(2026))->toBe('OF-2026-002');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateNumbererTest'`
Expected: FAIL — `Class "App\Services\Estimating\EstimateNumberer" not found`.

- [ ] **Step 3: Create the EstimateNumberer**

`app/Services/Estimating/EstimateNumberer.php`:

```php
<?php

namespace App\Services\Estimating;

use Illuminate\Support\Facades\DB;

class EstimateNumberer
{
    /**
     * Allocate the next estimate number for the given year, formatted "OF-YYYY-NNN".
     *
     * Uses MariaDB's INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(expr) trick:
     * the UPDATE clause both stores the new value AND publishes it via LAST_INSERT_ID(),
     * so we can read it back atomically with a follow-up SELECT — no race window.
     */
    public function nextFor(int $year): string
    {
        $n = DB::transaction(function () use ($year) {
            DB::statement(
                "INSERT INTO estimate_counters (year, last_n, created_at, updated_at) " .
                "VALUES (?, LAST_INSERT_ID(1), NOW(), NOW()) " .
                "ON DUPLICATE KEY UPDATE last_n = LAST_INSERT_ID(last_n + 1), updated_at = NOW()",
                [$year]
            );
            return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
        });

        return sprintf('OF-%d-%03d', $year, $n);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='EstimateNumbererTest'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Estimating/EstimateNumberer.php tests/Feature/Services/EstimateNumbererTest.php
git commit -m "feat(estimates): add EstimateNumberer (OF-YYYY-NNN, atomic per-year sequence)"
```

---

## Task 5: EstimateBuilder service

**Files:**
- Create: `app/Services/Estimating/EstimateBuilder.php`
- Test: `tests/Feature/Services/EstimateBuilderTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/EstimateBuilderTest.php`:

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'billable' => true,
        'rate_rappen' => 14500,
    ]);
    $this->svc = app(EstimateBuilder::class);
});

test('createDraft persists lines, recomputes amounts, and computes totals', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        lines: [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
            ['description' => 'Travel',       'hours' => 1.0, 'rate_rappen' => 5000,  'vat_exempt' => true],
        ],
        notes: 'Valid for 30 days.',
    );

    expect($estimate->status)->toBe('draft');
    expect($estimate->lines)->toHaveCount(2);

    $design = $estimate->lines->firstWhere('description', 'Design phase');
    expect($design->amount_rappen)->toBe(29000);  // recomputed: 2 * 14500
    expect($design->vat_exempt)->toBeFalse();

    $travel = $estimate->lines->firstWhere('description', 'Travel');
    expect($travel->amount_rappen)->toBe(5000);
    expect($travel->vat_exempt)->toBeTrue();

    // subtotal = 34000; vat = 8.10% of taxable 29000 = 2349; total = 36349
    expect($estimate->subtotal_rappen)->toBe(34000);
    expect($estimate->vat_rappen)->toBe(2349);
    expect($estimate->total_rappen)->toBe(36349);
    expect($estimate->notes)->toBe('Valid for 30 days.');
});

test('createDraft allocates a number via EstimateNumberer and writes a created event', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    );

    expect($estimate->number)->toMatch('/^OF-\d{4}-\d{3}$/');
    expect($estimate->events()->where('kind', 'created')->count())->toBe(1);
});

test('createDraft stamps vat_rate and currency from the business profile', function () {
    BusinessProfile::current()->update(['default_vat_rate' => 7.70]);

    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'Work', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    );

    expect((float) $estimate->vat_rate)->toBe(7.70);
    expect($estimate->currency)->toBe('CHF');
});

test('createDraft ignores any client-submitted amount_rappen (anti-tamper)', function () {
    $estimate = $this->svc->createDraft(
        client: $this->client, project: null,
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false, 'amount_rappen' => 999999]],
    );

    expect($estimate->lines->first()->amount_rappen)->toBe(10000);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateBuilderTest'`
Expected: FAIL — `Class "App\Services\Estimating\EstimateBuilder" not found`.

- [ ] **Step 3: Create the EstimateBuilder**

`app/Services/Estimating/EstimateBuilder.php`:

```php
<?php

namespace App\Services\Estimating;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\EstimateLine;
use App\Models\Project;
use App\Support\LineTotals;
use Illuminate\Support\Facades\DB;

class EstimateBuilder
{
    public function __construct(private EstimateNumberer $numberer) {}

    /**
     * Persist a draft estimate from the user's manually-entered lines.
     * Recomputes every line's amount and the estimate totals server-side
     * (never trusts client math).
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}>  $lines
     */
    public function createDraft(
        Client $client,
        ?Project $project,
        array $lines,
        ?string $notes = null,
    ): Estimate {
        return DB::transaction(function () use ($client, $project, $lines, $notes) {
            $profile = BusinessProfile::current();

            $number = $this->numberer->nextFor((int) date('Y'));

            $estimate = Estimate::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $profile->default_vat_rate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
                'notes' => $notes,
            ]);

            $lineAmounts = [];
            $vatExempts  = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount
                $exempt = (bool) ($line['vat_exempt'] ?? false);

                EstimateLine::create([
                    'estimate_id' => $estimate->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'vat_exempt' => $exempt,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
                $vatExempts[]  = $exempt;
            }

            $totals = LineTotals::compute($lineAmounts, $vatExempts, (float) $estimate->vat_rate);
            $estimate->subtotal_rappen = $totals['subtotal_rappen'];
            $estimate->vat_rappen      = $totals['vat_rappen'];
            $estimate->total_rappen    = $totals['total_rappen'];
            $estimate->save();

            EstimateEvent::create([
                'estimate_id' => $estimate->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['lines_count' => count($lines)],
            ]);

            return $estimate->fresh(['lines', 'events']);
        });
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='EstimateBuilderTest'`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Estimating/EstimateBuilder.php tests/Feature/Services/EstimateBuilderTest.php
git commit -m "feat(estimates): add EstimateBuilder (server-side totals, created event)"
```

---

## Task 6: EstimatePdfRenderer + PDF Blade template

**Files:**
- Create: `app/Services/Estimating/EstimatePdfRenderer.php`
- Create: `resources/views/estimates/pdf.blade.php`
- Test: `tests/Feature/Services/EstimatePdfRendererTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/EstimatePdfRendererTest.php`:

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Services\Estimating\EstimatePdfRenderer;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich',
    ]);
});

test('html renders the estimate number, client, lines and the Offerte heading', function () {
    $client = Client::factory()->create(['name' => 'Atlas Robotics']);
    $estimate = Estimate::factory()->create([
        'client_id' => $client->id,
        'number' => 'OF-2026-001',
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal_rappen' => 29000, 'vat_rappen' => 2349, 'total_rappen' => 31349,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'description' => 'Design phase']);

    $html = app(EstimatePdfRenderer::class)->html($estimate);

    expect($html)->toContain('OF-2026-001');
    expect($html)->toContain('Atlas Robotics');
    expect($html)->toContain('Design phase');
    expect($html)->toContain('Offerte');
    expect($html)->toContain('Gültig bis');
    expect($html)->not->toContain('Fällig'); // no due date / no payment slip
});

test('pdf caches the file on disk and stamps pdf_path', function () {
    $estimate = Estimate::factory()->create(['number' => 'OF-2026-009']);

    $path = app(EstimatePdfRenderer::class)->pdf($estimate);

    expect($path)->toBe('estimates/OF-2026-009.pdf');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($path))->toBeTrue();
    expect($estimate->fresh()->pdf_path)->toBe('estimates/OF-2026-009.pdf');
})->group('browsershot');
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimatePdfRendererTest'`
Expected: FAIL — `Class "App\Services\Estimating\EstimatePdfRenderer" not found`.

- [ ] **Step 3: Create the PDF Blade template**

`resources/views/estimates/pdf.blade.php`:

```blade
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Offerte {{ $estimate->number }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 40px; font-size: 12px; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 36px; }
    .label { font-size: 9px; letter-spacing: .08em; text-transform: uppercase; color: #6b6b6b; margin-bottom: 4px; }
    h1 { font-size: 22px; margin: 4px 0 0; }
    .cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-bottom: 28px; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 9px; letter-spacing: .08em; text-transform: uppercase; color: #6b6b6b; border-bottom: 1px solid #1a1a1a; padding: 8px 0; }
    thead th.num, tbody td.num { text-align: right; }
    tbody td { padding: 8px 0; border-bottom: 1px solid #e8e1d4; }
    .totals { margin-top: 18px; width: 280px; margin-left: auto; display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; }
    .totals .v { text-align: right; }
    .totals .grand { font-weight: 700; font-size: 16px; border-top: 1px solid #1a1a1a; padding-top: 8px; }
    .totals .grand-l { border-top: 1px solid #1a1a1a; padding-top: 8px; font-weight: 600; }
    .foot { margin-top: 28px; font-size: 10px; color: #6b6b6b; }
  </style>
</head>
@php
  $money = fn ($rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp
<body>
  <div class="head">
    <div>
      <div class="label">Offerte</div>
      <h1>#{{ $estimate->number }}</h1>
    </div>
    <div style="text-align: right">
      <div style="font-weight: 700">{{ $profile->name }}</div>
      <div style="color: #6b6b6b">{{ $profile->address_line_1 }}</div>
      <div style="color: #6b6b6b">{{ $profile->postal_code }} {{ $profile->city }}</div>
      @if ($profile->uid)<div style="color: #6b6b6b">{{ $profile->uid }}</div>@endif
    </div>
  </div>

  <div class="cols">
    <div>
      <div class="label">Offerte für</div>
      <div style="font-weight: 600">{{ $estimate->client->name }}</div>
      <div style="color: #3d3d3d">{{ $estimate->client->contact_name }}</div>
      <div style="color: #6b6b6b">{{ $estimate->client->address_line_1 }}</div>
      <div style="color: #6b6b6b">{{ $estimate->client->postal_code }} {{ $estimate->client->city }}</div>
    </div>
    <div>
      <div class="label">Ausgestellt</div>
      <div style="font-weight: 600">{{ $estimate->issued_on?->format('d.m.Y') ?? '—' }}</div>
      <div class="label" style="margin-top: 14px">Gültig bis</div>
      <div style="font-weight: 600">{{ $estimate->valid_until?->format('d.m.Y') ?? '—' }}</div>
    </div>
    <div>
      @if ($estimate->project)
        <div class="label">Projekt</div>
        <div style="font-weight: 600">{{ $estimate->project->name }}</div>
      @endif
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Beschreibung</th>
        <th class="num" style="width: 70px">Stunden</th>
        <th class="num" style="width: 90px">Ansatz</th>
        <th class="num" style="width: 110px">Betrag</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($estimate->lines as $line)
        <tr>
          <td>{{ $line->description }}@if ($line->vat_exempt) <span style="color:#6b6b6b">(MwSt-befreit)</span>@endif</td>
          <td class="num">{{ number_format((float) $line->hours, 2) }}</td>
          <td class="num">{{ $money($line->rate_rappen) }}</td>
          <td class="num">{{ $money($line->amount_rappen) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div>Zwischensumme</div><div class="v">{{ $money($estimate->subtotal_rappen) }}</div>
    <div>MwSt {{ rtrim(rtrim(number_format((float) $estimate->vat_rate, 2), '0'), '.') }}%</div><div class="v">{{ $money($estimate->vat_rappen) }}</div>
    <div class="grand-l">Total</div><div class="v grand">{{ $money($estimate->total_rappen) }}</div>
  </div>

  @if ($estimate->notes)
    <div class="foot">{{ $estimate->notes }}</div>
  @endif

  <div class="foot">
    <div>Dieses Angebot ist gültig bis {{ $estimate->valid_until?->format('d.m.Y') ?? '—' }}.</div>
    @if ($profile->email)<div>{{ $profile->email }}</div>@endif
  </div>
</body>
</html>
```

- [ ] **Step 4: Create the EstimatePdfRenderer**

`app/Services/Estimating/EstimatePdfRenderer.php`:

```php
<?php

namespace App\Services\Estimating;

use App\Models\BusinessProfile;
use App\Models\Estimate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class EstimatePdfRenderer
{
    /** Render the estimate document to an HTML string (used by /preview and the PDF). */
    public function html(Estimate $estimate): string
    {
        $estimate->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('estimates.pdf', [
            'estimate' => $estimate,
            'profile' => BusinessProfile::current(),
        ])->render();
    }

    /** Render to a cached PDF on the local disk; returns the storage-relative path. */
    public function pdf(Estimate $estimate): string
    {
        $relative = "estimates/{$estimate->number}.pdf";
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir($dir = dirname($absolute))) {
            mkdir($dir, 0775, true);
        }

        $this->browsershot($estimate)->save($absolute);

        $estimate->update(['pdf_path' => $relative]);

        return $relative;
    }

    /** Render a PDF without caching it on the estimate. */
    public function pdfBytes(Estimate $estimate): string
    {
        return $this->browsershot($estimate)->pdf();
    }

    private function browsershot(Estimate $estimate): Browsershot
    {
        $shot = Browsershot::html($this->html($estimate))
            ->format('A4')
            ->showBackground()
            ->margins(12, 12, 12, 12)
            // The DDEV/container Chromium has no usable sandbox; this is required to launch it.
            ->noSandbox();

        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        return $shot;
    }
}
```

- [ ] **Step 5: Run the html test (skip browsershot)**

Run: `ddev artisan test --filter='EstimatePdfRendererTest' --exclude-group browsershot`
Expected: PASS — the `html` test passes; the `pdf` (browsershot) test is skipped.

- [ ] **Step 6: Run the browsershot test to confirm caching works**

Run: `ddev artisan test --filter='EstimatePdfRendererTest' --group browsershot`
Expected: PASS — confirms Chromium renders and the file is cached at `estimates/OF-2026-009.pdf`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Estimating/EstimatePdfRenderer.php resources/views/estimates/pdf.blade.php tests/Feature/Services/EstimatePdfRendererTest.php
git commit -m "feat(estimates): add EstimatePdfRenderer + PDF Blade template (no QR slip)"
```

---

## Task 7: EstimateMail + email template

**Files:**
- Create: `app/Mail/EstimateMail.php`
- Create: `resources/views/emails/estimates/sent.blade.php`
- Test: `tests/Feature/Mail/EstimateMailTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Mail/EstimateMailTest.php`:

```php
<?php

use App\Mail\EstimateMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Support\Facades\Storage;

test('estimate mail renders details and attaches the pdf path', function () {
    BusinessProfile::create([
        'name' => 'Ernte Test',
        'country' => 'CH',
        'email' => 'offers@ernte.test',
        'default_currency' => 'CHF',
        'default_vat_rate' => 8.10,
    ]);

    $client = Client::factory()->create(['contact_name' => 'Mira Okafor']);
    $estimate = Estimate::factory()->create([
        'client_id' => $client->id,
        'number' => 'OF-2026-014',
        'status' => 'sent',
        'valid_until' => now()->addDays(30)->toDateString(),
        'total_rappen' => 123450,
    ]);

    Storage::disk('local')->put('estimates/OF-2026-014.pdf', '%PDF-test');

    $mail = new EstimateMail($estimate, 'estimates/OF-2026-014.pdf');
    $html = $mail->render();

    expect($html)->toContain('Mira Okafor');
    expect($html)->toContain('OF-2026-014');
    expect($html)->toContain("CHF 1'234.50");
    expect($mail->pdfPath)->toBe('estimates/OF-2026-014.pdf');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateMailTest'`
Expected: FAIL — `Class "App\Mail\EstimateMail" not found`.

- [ ] **Step 3: Create the email template**

`resources/views/emails/estimates/sent.blade.php`:

```blade
@php
    $fmt = fn (int $rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp

Guten Tag{{ $estimate->client->contact_name ? ' ' . $estimate->client->contact_name : '' }}

Anbei senden wir Ihnen unsere Offerte {{ $estimate->number }} als PDF.

Offertbetrag: {!! $fmt((int) $estimate->total_rappen) !!}
Gültig bis: {{ optional($estimate->valid_until)->format('d.m.Y') }}

Wir freuen uns auf Ihre Rückmeldung.

Freundliche Grüsse
{{ $profile->name }}
@if ($profile->email)
{{ $profile->email }}
@endif
```

- [ ] **Step 4: Create the EstimateMail mailable**

`app/Mail/EstimateMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\BusinessProfile;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EstimateMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public BusinessProfile $profile;

    public function __construct(
        public Estimate $estimate,
        public string $pdfPath,
    ) {
        $this->estimate->loadMissing(['client', 'project', 'lines']);
        $this->profile = BusinessProfile::current();
    }

    public function build(): self
    {
        $from = $this->profile->email ?: config('mail.from.address');
        $name = $this->profile->name ?: config('mail.from.name');

        return $this
            ->from($from, $name)
            ->replyTo($from, $name)
            ->subject("Offerte {$this->estimate->number} - {$name}")
            ->view('emails.estimates.sent')
            ->with([
                'estimate' => $this->estimate,
                'profile' => $this->profile,
            ])
            ->attach(Storage::disk('local')->path($this->pdfPath), [
                'as' => "Offerte-{$this->estimate->number}.pdf",
                'mime' => 'application/pdf',
            ]);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `ddev artisan test --filter='EstimateMailTest'`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add app/Mail/EstimateMail.php resources/views/emails/estimates/sent.blade.php tests/Feature/Mail/EstimateMailTest.php
git commit -m "feat(estimates): add EstimateMail + sent email template"
```

---

## Task 8: EstimateLifecycle service (send / accept / decline / convert)

**Files:**
- Create: `app/Services/Estimating/EstimateLifecycle.php`
- Test: `tests/Feature/Services/EstimateLifecycleTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/EstimateLifecycleTest.php`:

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
    $this->builder = app(EstimateBuilder::class);
    $this->lifecycle = app(EstimateLifecycle::class);
});

function draftEstimate(): \App\Models\Estimate
{
    return test()->builder->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false]],
    );
}

test('accept transitions sent -> accepted and stamps decided_at + event', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);

    test()->lifecycle->accept($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('accepted');
    expect($estimate->decided_at)->not->toBeNull();
    expect($estimate->events()->where('kind', 'accepted')->count())->toBe(1);
});

test('decline transitions sent -> declined and stamps decided_at + event', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);

    test()->lifecycle->decline($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('declined');
    expect($estimate->decided_at)->not->toBeNull();
    expect($estimate->events()->where('kind', 'declined')->count())->toBe(1);
});

test('accept is rejected unless the estimate is sent', function () {
    $estimate = draftEstimate(); // draft
    expect(fn () => test()->lifecycle->accept($estimate))->toThrow(\DomainException::class);
});

test('convertToInvoice builds a linked draft invoice from the lines', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);

    $invoice = test()->lifecycle->convertToInvoice($estimate);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->status)->toBe('draft');
    expect($invoice->client_id)->toBe(test()->client->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('Design phase');
    expect($invoice->lines->first()->amount_rappen)->toBe(29000);
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/'); // invoice numbering, not OF-

    $estimate->refresh();
    expect($estimate->converted_invoice_id)->toBe($invoice->id);
    expect($estimate->events()->where('kind', 'converted')->count())->toBe(1);
});

test('convertToInvoice is rejected unless the estimate is accepted', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent']);

    expect(fn () => test()->lifecycle->convertToInvoice($estimate))->toThrow(\DomainException::class);
});

test('convertToInvoice refuses to convert the same estimate twice', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'accepted', 'decided_at' => now()]);

    test()->lifecycle->convertToInvoice($estimate);

    expect(fn () => test()->lifecycle->convertToInvoice($estimate->fresh()))->toThrow(\DomainException::class);
});

test('send transitions draft -> sent, stamps dates, caches pdf, mails, writes events', function () {
    BusinessProfile::current()->update(['address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    $estimate = draftEstimate();
    Mail::fake();

    test()->lifecycle->send($estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe('sent');
    expect($estimate->issued_on)->not->toBeNull();
    expect($estimate->valid_until?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($estimate->pdf_path)->not->toBeNull();
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(1);
    expect($estimate->events()->where('kind', 'pdf_generated')->count())->toBe(1);
    Mail::assertSent(\App\Mail\EstimateMail::class, fn ($mail) => $mail->estimate->is($estimate) && $mail->pdfPath === $estimate->pdf_path);
})->group('browsershot');

test('send is rejected unless draft', function () {
    $estimate = draftEstimate();
    $estimate->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->send($estimate))->toThrow(\DomainException::class);
});

test('send is rejected when the client has no email address', function () {
    test()->client->update(['email' => null]);
    $estimate = draftEstimate();

    expect(fn () => test()->lifecycle->send($estimate))->toThrow(\DomainException::class);
    expect($estimate->fresh()->status)->toBe('draft');
    expect($estimate->events()->where('kind', 'sent')->count())->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateLifecycleTest' --exclude-group browsershot`
Expected: FAIL — `Class "App\Services\Estimating\EstimateLifecycle" not found`.

- [ ] **Step 3: Create the EstimateLifecycle**

`app/Services/Estimating/EstimateLifecycle.php`:

```php
<?php

namespace App\Services\Estimating;

use App\Mail\EstimateMail;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EstimateLifecycle
{
    public function __construct(
        private EstimatePdfRenderer $pdf,
        private InvoiceBuilder $invoiceBuilder,
    ) {}

    /** draft -> sent: stamp issued/valid dates, render + cache the PDF, email the client, write events. */
    public function send(Estimate $estimate): void
    {
        $estimate->loadMissing('client');

        if ($estimate->status !== 'draft') {
            throw new \DomainException("Only a draft can be sent (status: {$estimate->status}).");
        }

        if (! $estimate->client?->email) {
            throw new \DomainException('Cannot send estimate because the client has no email address.');
        }

        DB::transaction(function () use ($estimate) {
            $estimate->update([
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'sent_at' => now(),
            ]);
            $estimate->refresh();

            $path = $this->pdf->pdf($estimate);

            Mail::to($estimate->client->email)->send(new EstimateMail($estimate, $path));

            $this->event($estimate, 'pdf_generated', ['path' => $path]);
            $this->event($estimate, 'sent', ['email_to' => $estimate->client->email, 'pdf_path' => $path]);
        });
    }

    /** sent -> accepted. */
    public function accept(Estimate $estimate): void
    {
        if ($estimate->status !== 'sent') {
            throw new \DomainException("Only a sent estimate can be accepted (status: {$estimate->status}).");
        }

        DB::transaction(function () use ($estimate) {
            $estimate->update(['status' => 'accepted', 'decided_at' => now()]);
            $this->event($estimate, 'accepted');
        });
    }

    /** sent -> declined. */
    public function decline(Estimate $estimate): void
    {
        if ($estimate->status !== 'sent') {
            throw new \DomainException("Only a sent estimate can be declined (status: {$estimate->status}).");
        }

        DB::transaction(function () use ($estimate) {
            $estimate->update(['status' => 'declined', 'decided_at' => now()]);
            $this->event($estimate, 'declined');
        });
    }

    /**
     * Build a draft invoice from an accepted estimate's lines and link the two.
     * The resulting invoice is a normal draft — the user sends it through the
     * invoice flow (which adds the QR-bill).
     */
    public function convertToInvoice(Estimate $estimate): Invoice
    {
        if ($estimate->status !== 'accepted') {
            throw new \DomainException("Only an accepted estimate can be converted (status: {$estimate->status}).");
        }
        if ($estimate->converted_invoice_id !== null) {
            throw new \DomainException("Estimate {$estimate->number} has already been converted.");
        }

        $estimate->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return DB::transaction(function () use ($estimate) {
            $lines = $estimate->lines->map(fn ($l) => [
                'description' => $l->description,
                'hours' => (float) $l->hours,
                'rate_rappen' => (int) $l->rate_rappen,
                'vat_exempt' => (bool) $l->vat_exempt,
            ])->all();

            $today = now()->toDateString();

            $invoice = $this->invoiceBuilder->createDraft(
                client: $estimate->client,
                project: $estimate->project,
                periodStart: $today,
                periodEnd: $today,
                lines: $lines,
                entryIds: [],
            );

            $estimate->update(['converted_invoice_id' => $invoice->id]);
            $this->event($estimate, 'converted', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number]);

            return $invoice;
        });
    }

    private function event(Estimate $estimate, string $kind, ?array $payload = null): void
    {
        EstimateEvent::create([
            'estimate_id' => $estimate->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
```

- [ ] **Step 4: Run the non-browsershot tests to verify they pass**

Run: `ddev artisan test --filter='EstimateLifecycleTest' --exclude-group browsershot`
Expected: PASS — all transition + convert tests pass (the `send` browsershot test is skipped).

- [ ] **Step 5: Run the browsershot send test**

Run: `ddev artisan test --filter='EstimateLifecycleTest' --group browsershot`
Expected: PASS — confirms send renders/caches the PDF and dispatches `EstimateMail`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Estimating/EstimateLifecycle.php tests/Feature/Services/EstimateLifecycleTest.php
git commit -m "feat(estimates): add EstimateLifecycle (send/accept/decline/convert)"
```

---

## Task 9: EstimateProjections (list rows + stats)

**Files:**
- Create: `app/Support/EstimateProjections.php`
- Test: `tests/Feature/Support/EstimateProjectionsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/EstimateProjectionsTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Support\EstimateProjections;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics']);
});

test('index maps rows with client, hours, total and expired flag', function () {
    $estimate = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'number' => 'OF-2026-001',
        'valid_until' => now()->subDay()->toDateString(), 'total_rappen' => 10050,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $estimate->id, 'hours' => 5.5]);

    $rows = EstimateProjections::index('all');

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('OF-2026-001');
    expect($row['client']['name'])->toBe('Atlas Robotics');
    expect($row['total'])->toBe(100.5);
    expect($row['hours'])->toBe(5.5);
    expect($row['expired'])->toBeTrue();
});

test('the expired filter narrows to past-valid sent estimates', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'valid_until' => now()->subDay()->toDateString()]);
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'valid_until' => now()->addDay()->toDateString()]);

    expect(EstimateProjections::index('expired'))->toHaveCount(1);
});

test('the accepted filter narrows to accepted estimates', function () {
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id]);
    Estimate::factory()->declined()->create(['client_id' => $this->client->id]);

    expect(EstimateProjections::index('accepted'))->toHaveCount(1);
});

test('search matches number or client name', function () {
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2026-077']);

    expect(EstimateProjections::index('all', '077'))->toHaveCount(1);
    expect(EstimateProjections::index('all', 'Atlas'))->toHaveCount(1);
    expect(EstimateProjections::index('all', 'nope'))->toHaveCount(0);
});

test('stats returns open total, accepted ytd, and acceptance rate', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id, 'total_rappen' => 50000]);
    Estimate::factory()->accepted()->create(['client_id' => $this->client->id, 'total_rappen' => 80000, 'decided_at' => now()]);
    Estimate::factory()->declined()->create(['client_id' => $this->client->id, 'total_rappen' => 20000, 'decided_at' => now()]);

    $stats = EstimateProjections::stats();

    expect($stats['open'])->toBe(500.0);
    expect($stats['accepted_ytd'])->toBe(800.0);
    expect($stats['acceptance_rate'])->toBe(50); // 1 accepted of 2 decided
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateProjectionsTest'`
Expected: FAIL — `Class "App\Support\EstimateProjections" not found`.

- [ ] **Step 3: Create the EstimateProjections**

`app/Support/EstimateProjections.php`:

```php
<?php

namespace App\Support;

use App\Models\Estimate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EstimateProjections
{
    /**
     * Estimate list rows for /estimates.
     *
     * $filter: 'all' | 'draft' | 'sent' | 'accepted' | 'declined' | 'expired'.
     * 'expired' is virtual: status='sent' AND valid_until < today.
     *
     * @return Collection<int, array>
     */
    public static function index(string $filter = 'all', ?string $search = null): Collection
    {
        $q = Estimate::query()
            ->with(['client:id,name', 'project:id,name', 'lines:id,estimate_id,hours']);

        if ($filter === 'expired') {
            $q->where('status', 'sent')->whereDate('valid_until', '<', Carbon::today());
        } elseif (in_array($filter, ['draft', 'sent', 'accepted', 'declined'], true)) {
            $q->where('status', $filter);
        }

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $q->orderByDesc('id')->get()->map(fn (Estimate $e) => [
            'id' => $e->id,
            'number' => $e->number,
            'status' => $e->status,
            'expired' => $e->expired,
            'issued_on' => $e->issued_on?->toDateString(),
            'valid_until' => $e->valid_until?->toDateString(),
            'hours' => (float) round((float) $e->lines->sum('hours'), 2),
            'total' => (float) round($e->total_rappen / 100, 2),
            'client' => ['id' => $e->client->id, 'name' => $e->client->name],
            'project_name' => $e->project?->name,
        ]);
    }

    /** Top-of-page summary numbers in CHF (plus an acceptance-rate percentage). */
    public static function stats(): array
    {
        $open = (int) Estimate::open()->sum('total_rappen');

        $acceptedYtd = (int) Estimate::query()
            ->where('status', 'accepted')
            ->whereYear('decided_at', Carbon::now()->year)
            ->sum('total_rappen');

        $accepted = Estimate::where('status', 'accepted')->count();
        $declined = Estimate::where('status', 'declined')->count();
        $decided = $accepted + $declined;

        return [
            'open' => round($open / 100, 2),
            'accepted_ytd' => round($acceptedYtd / 100, 2),
            'acceptance_rate' => $decided > 0 ? (int) round($accepted / $decided * 100) : null,
            'count' => Estimate::count(),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='EstimateProjectionsTest'`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/EstimateProjections.php tests/Feature/Support/EstimateProjectionsTest.php
git commit -m "feat(estimates): add EstimateProjections (list rows + stats)"
```

---

## Task 10: Form requests, controller, routes

**Files:**
- Create: `app/Http/Requests/StoreEstimateRequest.php`
- Create: `app/Http/Requests/UpdateEstimateRequest.php`
- Create: `app/Http/Controllers/EstimateController.php`
- Modify: `routes/web.php` (add the estimates route block after the invoice block, ~line 51)
- Test: `tests/Feature/Http/EstimateControllerTest.php`

- [ ] **Step 1: Write the failing controller test**

`tests/Feature/Http/EstimateControllerTest.php`:

```php
<?php

use App\Mail\EstimateMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimatePdfRenderer;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

function makeDraftEstimate(): Estimate
{
    return app(EstimateBuilder::class)->createDraft(
        client: test()->client,
        project: test()->project,
        lines: [['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false]],
    );
}

test('GET /estimates renders Estimates/Index with rows + stats + counts', function () {
    $est = Estimate::factory()->sent()->create([
        'client_id' => $this->client->id, 'number' => 'OF-2026-001',
        'valid_until' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_50,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'hours' => 5.5]);

    $this->get('/estimates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Index')
            ->has('estimates', 1, fn (Assert $r) => $r
                ->where('number', 'OF-2026-001')
                ->where('client.name', 'Atlas Robotics')
                ->where('total', 100.5)
                ->where('hours', 5.5)
                ->where('status', 'sent')
                ->where('expired', false)
                ->etc())
            ->has('stats', fn (Assert $s) => $s->has('open')->has('accepted_ytd')->has('acceptance_rate')->etc())
            ->has('counts', fn (Assert $c) => $c->where('all', 1)->has('draft')->has('sent')->has('accepted')->has('declined')->has('expired')->etc())
            ->where('filters.filter', 'all'));
});

test('unauthenticated /estimates redirects to login', function () {
    auth()->logout();
    $this->get('/estimates')->assertRedirect('/login');
});

test('GET /estimates/new renders the editor with clients and projects', function () {
    $this->get('/estimates/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Create')
            ->has('clients')
            ->has('projects'));
});

test('POST /estimates creates a draft from submitted lines and redirects to its detail', function () {
    $res = $this->post('/estimates', [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'notes' => 'Quote for Q3 work.',
        'lines' => [
            ['description' => 'Design phase', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
        ],
    ]);

    $estimate = Estimate::latest('id')->first();
    $res->assertRedirect("/estimates/{$estimate->number}");
    expect($estimate->lines)->toHaveCount(1);
    expect($estimate->total_rappen)->toBe(31349); // 29000 + 8.10%
    expect($estimate->notes)->toBe('Quote for Q3 work.');
});

test('POST /estimates requires at least one line', function () {
    $this->post('/estimates', ['client_id' => $this->client->id, 'lines' => []])
        ->assertSessionHasErrors('lines');
});

test('POST /estimates rejects a project belonging to a different client', function () {
    $otherClient = Client::factory()->create(['name' => 'Other Co', 'short_code' => 'OC']);
    $otherProject = Project::factory()->create(['client_id' => $otherClient->id, 'billable' => true, 'rate_rappen' => 10000]);

    $this->post('/estimates', [
        'client_id' => $this->client->id,
        'project_id' => $otherProject->id,
        'lines' => [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 14500, 'vat_exempt' => false]],
    ])->assertSessionHasErrors('project_id');
});

test('GET /estimates/{number} renders Estimates/Show with estimate + lines + events', function () {
    $est = makeDraftEstimate();

    $this->get("/estimates/{$est->number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estimates/Show')
            ->where('estimate.number', $est->number)
            ->where('estimate.status', 'draft')
            ->has('estimate.lines', 1)
            ->has('events', 1, fn (Assert $e) => $e->where('kind', 'created')->etc())
            ->where('preview_url', "/estimates/{$est->number}/preview"));
});

test('GET /estimates/{number}/preview returns raw HTML (not Inertia)', function () {
    $est = makeDraftEstimate();
    $res = $this->get("/estimates/{$est->number}/preview");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/html');
    $res->assertSee($est->number, false);
});

test('GET /estimates/{number}/pdf streams draft PDFs without caching', function () {
    $est = makeDraftEstimate();

    $this->mock(EstimatePdfRenderer::class, function ($mock) use ($est) {
        $mock->shouldReceive('pdfBytes')
            ->once()
            ->with(Mockery::on(fn ($estimate) => $estimate->is($est)))
            ->andReturn('%PDF-draft');
    });

    $this->get("/estimates/{$est->number}/pdf")
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('content-type', 'application/pdf')
        ->assertStreamedContent('%PDF-draft');

    expect($est->fresh()->pdf_path)->toBeNull();
});

test('PATCH /estimates/{id} edits a draft notes + lines and recomputes totals', function () {
    $est = makeDraftEstimate();
    $this->patch("/estimates/{$est->id}", [
        'notes' => 'Updated scope.',
        'lines' => [['description' => 'Edited', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    ])->assertRedirect("/estimates/{$est->number}");

    $est->refresh();
    expect($est->notes)->toBe('Updated scope.');
    expect($est->lines)->toHaveCount(1);
    expect($est->subtotal_rappen)->toBe(10000);
    expect($est->total_rappen)->toBe(10810);
});

test('PATCH is rejected once the estimate is not a draft', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent']);
    $this->patch("/estimates/{$est->id}", ['notes' => 'x'])->assertStatus(403);
});

test('POST /estimates/{id}/accept accepts a sent estimate', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);
    $this->post("/estimates/{$est->id}/accept")->assertRedirect();
    expect($est->fresh()->status)->toBe('accepted');
});

test('POST /estimates/{id}/decline declines a sent estimate', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'valid_until' => now()->addDays(29)]);
    $this->post("/estimates/{$est->id}/decline")->assertRedirect();
    expect($est->fresh()->status)->toBe('declined');
});

test('POST /estimates/{id}/convert creates a linked draft invoice and redirects to it', function () {
    $est = makeDraftEstimate();
    $est->update(['status' => 'accepted', 'decided_at' => now()]);

    $this->post("/estimates/{$est->id}/convert")
        ->assertSessionMissing('error');

    $invoice = Invoice::latest('id')->first();
    expect($invoice)->not->toBeNull();
    expect($est->fresh()->converted_invoice_id)->toBe($invoice->id);
});

test('POST /estimates/{id}/send keeps draft when client email is missing', function () {
    $this->client->update(['email' => null]);
    $est = makeDraftEstimate();

    $this->post("/estimates/{$est->id}/send")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($est->fresh()->status)->toBe('draft');
    expect($est->events()->where('kind', 'sent')->count())->toBe(0);
});

test('POST /estimates/{id}/send issues the draft', function () {
    BusinessProfile::current()->update(['address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    $this->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    Mail::fake();
    $est = makeDraftEstimate();

    $this->post("/estimates/{$est->id}/send")
        ->assertRedirect()
        ->assertSessionMissing('error')
        ->assertSessionHasNoErrors();

    expect($est->fresh()->status)->toBe('sent');
    Mail::assertSent(EstimateMail::class);
})->group('browsershot');
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateControllerTest' --exclude-group browsershot`
Expected: FAIL — route/controller not found (404s / `Target class [EstimateController] does not exist`).

- [ ] **Step 3: Create StoreEstimateRequest**

`app/Http/Requests/StoreEstimateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimateRequest extends FormRequest
{
    public function authorize(): bool { return true; } // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $this->input('client_id')))],
            'notes' => 'sometimes|nullable|string|max:5000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 4: Create UpdateEstimateRequest**

`app/Http/Requests/UpdateEstimateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only drafts are editable.
        return $this->route('estimate')->status === 'draft';
    }

    public function rules(): array
    {
        return [
            'notes' => 'sometimes|nullable|string|max:5000',
            'lines' => 'sometimes|array|min:1',
            'lines.*.description' => 'required_with:lines|string|max:1000',
            'lines.*.hours' => 'required_with:lines|numeric|min:0',
            'lines.*.rate_rappen' => 'required_with:lines|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 5: Create the EstimateController**

`app/Http/Controllers/EstimateController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEstimateRequest;
use App\Http\Requests\UpdateEstimateRequest;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Project;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use App\Services\Estimating\EstimatePdfRenderer;
use App\Support\EstimateProjections;
use App\Support\LineTotals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EstimateController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Estimates/Index', [
            'estimates' => EstimateProjections::index($filter, $search)->values(),
            'stats' => EstimateProjections::stats(),
            'counts' => [
                'all' => Estimate::count(),
                'draft' => Estimate::where('status', 'draft')->count(),
                'sent' => Estimate::where('status', 'sent')->count(),
                'accepted' => Estimate::where('status', 'accepted')->count(),
                'declined' => Estimate::where('status', 'declined')->count(),
                'expired' => Estimate::where('status', 'sent')->whereDate('valid_until', '<', now()->toDateString())->count(),
            ],
            'filters' => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Estimates/Create', [
            'clients' => Client::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values(),
        ]);
    }

    public function store(StoreEstimateRequest $request, EstimateBuilder $builder): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']);
        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $estimate = $builder->createDraft(
            client: $client,
            project: $project,
            lines: $data['lines'],
            notes: $data['notes'] ?? null,
        );

        return redirect("/estimates/{$estimate->number}")->with('success', "Draft {$estimate->number} created.");
    }

    public function show(Estimate $estimate): Response
    {
        $estimate->load([
            'client', 'project',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'events' => fn ($q) => $q->orderByDesc('occurred_at'),
            'convertedInvoice:id,number',
        ]);

        return Inertia::render('Estimates/Show', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'status' => $estimate->status,
                'expired' => $estimate->expired,
                'client' => $estimate->client->only('id', 'name'),
                'project_name' => $estimate->project?->name,
                'issued_on' => $estimate->issued_on?->toDateString(),
                'valid_until' => $estimate->valid_until?->toDateString(),
                'subtotal' => round($estimate->subtotal_rappen / 100, 2),
                'vat' => round($estimate->vat_rappen / 100, 2),
                'total' => round($estimate->total_rappen / 100, 2),
                'vat_rate' => (float) $estimate->vat_rate,
                'notes' => $estimate->notes,
                'lines' => $estimate->lines->map(fn (EstimateLine $l) => [
                    'id' => $l->id, 'description' => $l->description,
                    'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                    'amount' => round($l->amount_rappen / 100, 2), 'vat_exempt' => (bool) $l->vat_exempt,
                ]),
                'converted_invoice' => $estimate->convertedInvoice
                    ? ['id' => $estimate->convertedInvoice->id, 'number' => $estimate->convertedInvoice->number]
                    : null,
            ],
            'events' => $estimate->events->map(fn ($e) => [
                'kind' => $e->kind,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'payload' => $e->payload,
            ]),
            'preview_url' => "/estimates/{$estimate->number}/preview",
            'pdf_url' => "/estimates/{$estimate->number}/pdf",
        ]);
    }

    public function preview(Estimate $estimate, EstimatePdfRenderer $renderer): HttpResponse
    {
        return response($renderer->html($estimate))->header('Content-Type', 'text/html');
    }

    public function update(UpdateEstimateRequest $request, Estimate $estimate): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $estimate) {
            if (array_key_exists('notes', $data)) {
                $estimate->notes = $data['notes'];
            }
            if (! empty($data['lines'])) {
                $estimate->lines()->delete();
                $lineAmounts = [];
                $vatExempts = [];
                $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $exempt = (bool) ($line['vat_exempt'] ?? false);
                    $estimate->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'vat_exempt' => $exempt, 'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount;
                    $vatExempts[] = $exempt;
                }
                $totals = LineTotals::compute($lineAmounts, $vatExempts, (float) $estimate->vat_rate);
                $estimate->subtotal_rappen = $totals['subtotal_rappen'];
                $estimate->vat_rappen = $totals['vat_rappen'];
                $estimate->total_rappen = $totals['total_rappen'];
            }
            $estimate->save();
        });

        return redirect("/estimates/{$estimate->number}")->with('success', 'Draft updated.');
    }

    public function send(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->send($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', "Could not send estimate {$estimate->number}: {$e->getMessage()}");
        } catch (\Throwable $e) {
            Log::error('Estimate send failed.', ['estimate_id' => $estimate->id, 'exception' => $e]);
            return back()->with('error', "Could not email estimate {$estimate->number}. Please check mail settings and try again.");
        }

        return back()->with('success', "Estimate {$estimate->number} sent.");
    }

    public function accept(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->accept($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Estimate {$estimate->number} accepted.");
    }

    public function decline(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->decline($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Estimate {$estimate->number} declined.");
    }

    public function convert(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $invoice = $lifecycle->convertToInvoice($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/invoices/{$invoice->number}")
            ->with('success', "Invoice {$invoice->number} created from estimate {$estimate->number}.");
    }

    public function pdf(Estimate $estimate, EstimatePdfRenderer $renderer): \Symfony\Component\HttpFoundation\Response
    {
        if ($estimate->status === 'draft') {
            return response()->streamDownload(
                function () use ($estimate, $renderer) {
                    echo $renderer->pdfBytes($estimate);
                },
                "Offerte-{$estimate->number}.pdf",
                ['Content-Type' => 'application/pdf'],
            );
        }

        $relative = $estimate->pdf_path && Storage::disk('local')->exists($estimate->pdf_path)
            ? $estimate->pdf_path
            : $renderer->pdf($estimate);

        return response()->download(
            Storage::disk('local')->path($relative),
            "Offerte-{$estimate->number}.pdf",
        );
    }
}
```

- [ ] **Step 6: Register the routes**

In `routes/web.php`, add `use App\Http\Controllers\EstimateController;` to the imports (alongside the other controller imports near the top), then add this block immediately after the invoices block (after line 51, before the `/reports` route):

```php
    Route::get('/estimates', [EstimateController::class, 'index'])->name('estimates.index');
    Route::get('/estimates/new', [EstimateController::class, 'create'])->name('estimates.create');
    Route::post('/estimates', [EstimateController::class, 'store'])->name('estimates.store');

    Route::get('/estimates/{estimate:number}', [EstimateController::class, 'show'])->name('estimates.show');
    Route::get('/estimates/{estimate:number}/preview', [EstimateController::class, 'preview'])->name('estimates.preview');
    Route::get('/estimates/{estimate:number}/pdf', [EstimateController::class, 'pdf'])->name('estimates.pdf');
    Route::patch('/estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
    Route::post('/estimates/{estimate}/send', [EstimateController::class, 'send'])->name('estimates.send');
    Route::post('/estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
    Route::post('/estimates/{estimate}/decline', [EstimateController::class, 'decline'])->name('estimates.decline');
    Route::post('/estimates/{estimate}/convert', [EstimateController::class, 'convert'])->name('estimates.convert');
```

> `/estimates/new` must be registered before `/estimates/{estimate:number}` so "new" is not captured as a number. The order above is correct.

- [ ] **Step 7: Run the controller tests (skip browsershot)**

Run: `ddev artisan test --filter='EstimateControllerTest' --exclude-group browsershot`
Expected: PASS — all controller tests except the browsershot-grouped `send` test (which is skipped).

- [ ] **Step 8: Run the browsershot send test**

Run: `ddev artisan test --filter='EstimateControllerTest' --group browsershot`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StoreEstimateRequest.php app/Http/Requests/UpdateEstimateRequest.php app/Http/Controllers/EstimateController.php routes/web.php tests/Feature/Http/EstimateControllerTest.php
git commit -m "feat(estimates): add controller, form requests, and routes"
```

---

## Task 11: Vue pages + sidebar nav

**Files:**
- Create: `resources/js/Pages/Estimates/Index.vue`
- Create: `resources/js/Pages/Estimates/Create.vue`
- Create: `resources/js/Pages/Estimates/Show.vue`
- Modify: `resources/js/Components/Sidebar.vue` (add an "Estimates" nav item to the `NAV` array)

> **Badge styling note:** the design CSS defines `.badge.dot` classes for invoice statuses `draft`, `sent`, `paid`, `void`, `overdue`. Estimates reuse these by mapping status → an existing class (so no new CSS is required): `draft→draft`, `sent→sent`, `expired→overdue`, `accepted→paid`, `declined→void`. The visible label still shows the real estimate status text.

- [ ] **Step 1: Add the sidebar nav item**

In `resources/js/Components/Sidebar.vue`, add an Estimates entry to the `NAV` computed array (after the `invoices` entry, ~line 13):

```js
  { id: 'estimates', href: '/estimates', label: 'Estimates', glyph: '✎', count: null },
```

So the array reads (invoices line unchanged, new line added directly below it):

```js
  { id: 'invoices', href: '/invoices', label: 'Invoices', glyph: '≡', count: null },
  { id: 'estimates', href: '/estimates', label: 'Estimates', glyph: '✎', count: null },
  { id: 'reports',  href: '/reports',  label: 'Reports',  glyph: '△', count: null },
```

- [ ] **Step 2: Create Estimates/Index.vue**

`resources/js/Pages/Estimates/Index.vue`:

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimates: { type: Array, required: true },
  stats:     { type: Object, required: true },
  counts:    { type: Object, required: true },
  filters:   { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'all');

function setFilter(f) {
  router.get('/estimates', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}
let t = null;
function onSearch() {
  if (t) clearTimeout(t);
  t = setTimeout(() => router.get('/estimates', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true }), 250);
}

function fmtMoney(v)      { return 'CHF ' + Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtMoneyShort(v) { return 'CHF ' + Math.round(v).toLocaleString('de-CH'); }
function fmtDate(d)       { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—'; }

// Map estimate status → an existing invoice badge class + a display label.
function badge(est) {
  if (est.expired) return { cls: 'overdue', label: 'expired' };
  return { cls: { draft: 'draft', sent: 'sent', accepted: 'paid', declined: 'void' }[est.status] ?? 'draft', label: est.status };
}

const TABS = computed(() => [
  { id: 'all',      label: 'All',      count: props.counts.all },
  { id: 'draft',    label: 'Draft',    count: props.counts.draft },
  { id: 'sent',     label: 'Sent',     count: props.counts.sent },
  { id: 'accepted', label: 'Accepted', count: props.counts.accepted },
  { id: 'declined', label: 'Declined', count: props.counts.declined },
  { id: 'expired',  label: 'Expired',  count: props.counts.expired },
]);
</script>

<template>
  <Head title="Estimates" />

  <div class="page-head">
    <div>
      <div class="crumb">~ / estimates</div>
      <h1 class="page-title">
        Estimates
        <span class="meta">{{ counts.all }} total<span class="ascii-dot">·</span>FY {{ new Date().getFullYear() }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/estimates/new" class="btn primary">+ New estimate</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Open</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.open) }}</div>
      <div class="delta">{{ counts.sent }} sent</div>
    </div>
    <div class="stat">
      <div class="label">Accepted YTD</div>
      <div class="val">{{ fmtMoneyShort(stats.accepted_ytd) }}</div>
    </div>
    <div class="stat">
      <div class="label">Acceptance rate</div>
      <div class="val">{{ stats.acceptance_rate ?? '—' }}<span v-if="stats.acceptance_rate !== null" class="unit">%</span></div>
    </div>
    <div class="stat">
      <div class="label">Total</div>
      <div class="val">{{ stats.count }}</div>
    </div>
  </div>

  <div class="filter-row">
    <button v-for="tab in TABS" :key="tab.id" class="chip" :aria-pressed="filter === tab.id" @click="setFilter(tab.id)">
      {{ tab.label }} <span class="dim" style="margin-left: 4px">{{ tab.count }}</span>
    </button>
    <div class="search">
      <span style="color: var(--ink-4)">⌕</span>
      <input v-model="search" placeholder="filter…" @input="onSearch" />
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l" style="width: 160px">Estimate</th>
          <th style="width: 240px">Client</th>
          <th class="num" style="width: 100px">Issued</th>
          <th class="num" style="width: 100px">Valid until</th>
          <th class="num" style="width: 80px">Hours</th>
          <th class="num" style="width: 140px">Total</th>
          <th style="width: 120px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="est in estimates" :key="est.id" @click="router.visit(`/estimates/${est.number}`)">
          <td class="pad-l strong">
            <span class="mono-tag" style="padding: 2px 6px; color: var(--ink); border-color: var(--border-strong)">#{{ est.number }}</span>
          </td>
          <td>{{ est.client.name }}</td>
          <td class="num">{{ fmtDate(est.issued_on) }}</td>
          <td class="num" :style="{ color: est.expired ? 'var(--red)' : undefined }">{{ fmtDate(est.valid_until) }}</td>
          <td class="num">{{ est.hours.toFixed(1) }}h</td>
          <td class="num strong">{{ fmtMoney(est.total) }}</td>
          <td><span class="badge dot" :class="badge(est).cls">{{ badge(est).label }}</span></td>
        </tr>
        <tr v-if="estimates.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">No estimates match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

- [ ] **Step 3: Create Estimates/Create.vue**

`resources/js/Pages/Estimates/Create.vue`:

```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients:  { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] }, // { id, name, client_id, rate }
});

const clientId = ref(null);
const projectId = ref(null);
const notes = ref('');

// Projects belonging to the selected client.
const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);

// Reset the project when the client changes.
watch(clientId, () => { projectId.value = null; });

// Editable lines (manual entry).
const lines = ref([]);
let nextKey = 0;
function addLine() {
  lines.value.push({ key: nextKey++, description: '', hours: 0, rate: selectedProject.value?.rate ?? 0, vat_exempt: false });
}
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

// Seed one empty line on mount for convenience.
addLine();

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const VAT_RATE = 8.1;
const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * VAT_RATE / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const canSave = computed(() => clientId.value && lines.value.length > 0);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    notes: notes.value || null,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
    })),
  })).post('/estimates');
}
</script>

<template>
  <Head title="New estimate" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">New estimate</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/estimates" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Create draft</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Client</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Client</span>
          <select v-model="clientId">
            <option :value="null" disabled>Select a client…</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>Project (optional)</span>
          <select v-model="projectId" :disabled="!clientId">
            <option :value="null">—</option>
            <option v-for="p in clientProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
      </div>

      <h3 class="section-title">Lines</h3>
      <table class="table">
        <thead>
          <tr>
            <th class="pad-l">Description</th>
            <th class="num" style="width: 80px">Hours</th>
            <th class="num" style="width: 100px">Rate</th>
            <th class="num" style="width: 120px">Amount</th>
            <th style="width: 60px">MwSt</th>
            <th style="width: 70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td class="pad-l"><input v-model="l.description" class="cell-input" placeholder="description" /></td>
            <td class="num"><input v-model="l.hours" type="number" min="0" step="0.25" class="cell-input num" /></td>
            <td class="num"><input v-model="l.rate" type="number" min="0" class="cell-input num" /></td>
            <td class="num strong">{{ fmtMoney(Math.round(Number(l.hours) * Number(l.rate) * 100)) }}</td>
            <td><label style="display: flex; gap: 4px; align-items: center"><input type="checkbox" v-model="l.vat_exempt" /><span class="dim" style="font-size: var(--fs-xs)">exempt</span></label></td>
            <td>
              <button class="icon-btn" title="move up" @click="moveUp(i)">↑</button>
              <button class="icon-btn" title="remove" @click="removeLine(l.key)">×</button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes shown on the estimate PDF…"></textarea>
    </div>

    <aside>
      <h3 class="section-title">Totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ VAT_RATE }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Server recomputes all amounts on save. The estimate is created as a draft; send it to stamp the validity date and email the client.
      </p>
      <div v-if="Object.keys(form.errors).length" style="color: var(--red); font-size: var(--fs-sm); margin-top: 12px">
        {{ Object.values(form.errors).join(' · ') }}
      </div>
    </aside>
  </div>
</template>

<style scoped>
.cell-input { width: 100%; border: 1px solid transparent; background: transparent; padding: 4px 6px; font-family: inherit; color: var(--ink); }
.cell-input:focus { outline: none; border-color: var(--accent); background: var(--paper); }
.cell-input.num { text-align: right; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: var(--fs-sm); color: var(--ink-2); }
.field select { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field select:focus { outline: none; border-color: var(--accent); }
</style>
```

- [ ] **Step 4: Create Estimates/Show.vue**

`resources/js/Pages/Estimates/Show.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  estimate: { type: Object, required: true },
  events: { type: Array, required: true },
  preview_url: { type: String, required: true },
  pdf_url: { type: String, required: true },
});

const isDraft = computed(() => props.estimate.status === 'draft');
const isSent = computed(() => props.estimate.status === 'sent');
const isAccepted = computed(() => props.estimate.status === 'accepted');
const converted = computed(() => props.estimate.converted_invoice);

const badge = computed(() => {
  const e = props.estimate;
  if (e.expired) return { cls: 'overdue', label: 'expired' };
  return { cls: { draft: 'draft', sent: 'sent', accepted: 'paid', declined: 'void' }[e.status] ?? 'draft', label: e.status };
});

function send()    { router.post(`/estimates/${props.estimate.id}/send`,    {}, { preserveScroll: true }); }
function accept()  { router.post(`/estimates/${props.estimate.id}/accept`,  {}, { preserveScroll: true }); }
function decline() {
  if (!window.confirm(`Mark estimate #${props.estimate.number} as declined?`)) return;
  router.post(`/estimates/${props.estimate.id}/decline`, {}, { preserveScroll: true });
}
function convert() {
  if (!window.confirm(`Create a draft invoice from estimate #${props.estimate.number}?`)) return;
  router.post(`/estimates/${props.estimate.id}/convert`, {}, { preserveScroll: true });
}

const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', accepted: 'Accepted', declined: 'Declined',
  converted: 'Converted to invoice', pdf_generated: 'Generated PDF',
};
function fmtWhen(iso) { return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
</script>

<template>
  <Head :title="`Estimate #${estimate.number}`" />

  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/estimates">~ / estimates</Link><span class="ascii-dot">/</span><span>#{{ estimate.number }}</span>
      </div>
      <h1 class="page-title">
        Estimate #{{ estimate.number }}
        <span class="meta">{{ estimate.client.name }}<span class="ascii-dot">·</span><span class="badge dot" :class="badge.cls">{{ badge.label }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <a :href="pdf_url" class="btn">Download PDF</a>
      <button v-if="isDraft" class="btn primary" @click="send">Send</button>
      <template v-else-if="isSent">
        <button class="btn primary" @click="accept">Accept</button>
        <button class="btn ghost" @click="decline">Decline</button>
      </template>
      <Link v-if="converted" :href="`/invoices/${converted.number}`" class="btn">Invoice #{{ converted.number }}</Link>
      <button v-else-if="isAccepted" class="btn primary" @click="convert">Convert to invoice</button>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Estimate document" style="width: 100%; height: 1100px; border: 1px solid var(--border); background: #fff"></iframe>
    </div>

    <aside class="invoice-side">
      <h3 class="section-title">Activity</h3>
      <div style="display: flex; flex-direction: column; gap: 10px; font-size: var(--fs-sm)">
        <div v-for="(e, i) in events" :key="i" style="display: flex; gap: 10px; align-items: baseline">
          <span style="color: var(--ink-4); font-size: 10px; min-width: 96px">{{ fmtWhen(e.occurred_at) }}</span>
          <span style="color: var(--ink-2)">{{ EVENT_LABEL[e.kind] ?? e.kind }}</span>
        </div>
        <div v-if="events.length === 0" class="muted">No activity yet.</div>
      </div>

      <h3 class="section-title" style="margin-top: 24px">Validity</h3>
      <div style="font-size: var(--fs-sm); color: var(--ink-2)">
        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border)">
          <span>Valid until</span><span class="muted">{{ estimate.valid_until ?? '—' }}</span>
        </div>
      </div>
    </aside>
  </div>
</template>
```

- [ ] **Step 5: Build the frontend to confirm the pages compile**

Run: `ddev exec npm run build`
Expected: build succeeds with no Vue compile errors for the three new pages.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Estimates/Index.vue resources/js/Pages/Estimates/Create.vue resources/js/Pages/Estimates/Show.vue resources/js/Components/Sidebar.vue
git commit -m "feat(estimates): add Index/Create/Show Vue pages + sidebar nav"
```

---

## Task 12: Full-suite verification + manual UI check

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite excluding browsershot**

Run: `ddev artisan test --exclude-group browsershot`
Expected: PASS — all existing tests plus the new estimate tests are green.

- [ ] **Step 2: Run the browsershot group**

Run: `ddev artisan test --group browsershot`
Expected: PASS — invoice and estimate PDF/send tests render via Chromium.

- [ ] **Step 3: Manual UI walkthrough**

Start the app (`ddev launch` / the project's dev URL) and, logged in:
1. Click **Estimates** in the sidebar → the Index page loads with empty/seeded rows, filter tabs, and the stats panel.
2. Click **+ New estimate** → pick a client (that has an email address), optionally a project, add 2 lines (one VAT-exempt), enter notes → **Create draft**.
3. On the Show page, confirm the PDF preview renders the "Offerte" heading, "Gültig bis", lines, and totals, with **no QR slip**.
4. Click **Download PDF** → a `Offerte-OF-….pdf` downloads.
5. Click **Send** → status becomes `sent`, the activity log shows Sent, and (with a real/mailpit mailer) the client receives the email with the PDF attached.
6. Click **Accept** → status `accepted`; then **Convert to invoice** → you land on a new draft invoice carrying the same lines, and returning to the estimate shows the **Invoice #…** link instead of the convert button.

Report any rendering issues (badge colors, layout) before declaring done. If you cannot run the browser, say so explicitly rather than claiming the UI works.

- [ ] **Step 4: Final commit (if any tweaks were needed)**

```bash
git add -A
git commit -m "test(estimates): verify full suite + manual UI walkthrough"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** schema (Task 1), models (Task 2), shared `LineTotals` (Task 3), numberer (Task 4), builder (Task 5), PDF (Task 6), mail (Task 7), lifecycle incl. send/accept/decline/convert (Task 8), projections/stats (Task 9), controller+requests+routes (Task 10), Vue pages + nav (Task 11), verification (Task 12). The spec's "out of scope" items (QR-bill, reminders, time-entry linking, reverse invoice→estimate link) are intentionally absent.
- **Number prefix** is `OF-` (Task 4) and the factory uses `OF-…-T#####` to avoid clashing with real numbers.
- **`expired`** is computed in the model (Task 2) and used by projections/Vue — never stored.
- **Convert** reuses `InvoiceBuilder::createDraft` (Task 8); the new invoice gets its own `YYYY-NNN` number and QR reference for free, and `entryIds` is `[]`.
- **No invoice regressions:** the only edit to existing code is `InvoiceBuilder::computeTotals` delegating to `LineTotals` (Task 3, Step 5 re-runs the invoice suite).
