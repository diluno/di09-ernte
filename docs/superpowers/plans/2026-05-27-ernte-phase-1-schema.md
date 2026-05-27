# Ernte — Phase 1 (Schema + Domain) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the full data model (9 application tables + 1 counter table) and the four domain services (`TimerService`, `InvoiceNumberer`, `QrReferenceGenerator`, `InvoiceBuilder`) on top of Phase 0's app shell. All testable via Pest. No HTTP / UI work — controllers and Vue views land in Phase 2.

**Architecture:** Schema-first build (per spec §10, build order B). Each entity gets its own migration + model + factory in a focused task. Money is integer rappen (CHF cents) everywhere. The one-running-timer invariant uses a MariaDB generated column + unique index. Invoice numbering uses a `(year, last_n)` counter row with atomic increment. Domain services live under `app/Services/{Timer,Invoicing}/` and are tested in isolation against a real DDEV MariaDB.

**Tech Stack:** Laravel 12, Eloquent, MariaDB 11.4 (generated columns + ON DUPLICATE KEY UPDATE for atomicity), Pest. No new third-party packages this phase — `sprain/swiss-qr-bill` lands in Phase 2 when we actually render PDFs.

**Source spec:** `docs/superpowers/specs/2026-05-27-ernte-design.md`
**Predecessor plan:** `docs/superpowers/plans/2026-05-27-ernte-phase-0-bootstrap.md` (tag `phase-0`)

---

## File map for Phase 1

Created:

| Path | Responsibility |
|---|---|
| `database/migrations/<ts>_create_business_profile.php` | Singleton table (id=1) for sender details, IBAN/QR-IBAN, UID, default VAT rate, reminder window |
| `database/migrations/<ts>_create_clients.php` | Customer records — name, address, contact, default rate, archived flag |
| `database/migrations/<ts>_create_projects.php` | Per-client projects with budgets, retainer flag, status, rate, dates |
| `database/migrations/<ts>_create_tasks.php` | Per-project task list with budget hours and done flag |
| `database/migrations/<ts>_create_time_entries.php` | Hours log; nullable `ended_at` means running; partial unique index enforces ≤1 running per user |
| `database/migrations/<ts>_create_invoice_counters.php` | `(year, last_n)` row per fiscal year for atomic number allocation |
| `database/migrations/<ts>_create_invoices.php` | Invoice header — number, status, currency, VAT, totals, QR reference |
| `database/migrations/<ts>_create_invoice_lines.php` | Line items — description, hours, rate, amount, vat_exempt |
| `database/migrations/<ts>_create_invoice_events.php` | Audit trail for invoice lifecycle |
| `database/migrations/<ts>_create_backups.php` | Backup log for statusbar's "backup Nh ago" display |
| `app/Models/BusinessProfile.php` | Singleton model |
| `app/Models/Client.php` | hasMany projects |
| `app/Models/Project.php` | belongsTo client, hasMany tasks/timeEntries; computed `spent_hours`, `spent_amount_rappen`, `band` accessors |
| `app/Models/Task.php` | belongsTo project |
| `app/Models/TimeEntry.php` | belongsTo project/task/invoice; `running` scope; `duration_seconds` accessor |
| `app/Models/InvoiceCounter.php` | (year, last_n) row |
| `app/Models/Invoice.php` | belongsTo client/project; hasMany lines/events/entries; `overdue` accessor |
| `app/Models/InvoiceLine.php` | belongsTo invoice |
| `app/Models/InvoiceEvent.php` | belongsTo invoice |
| `app/Models/Backup.php` | Backup log row |
| `database/factories/{Client,Project,Task,TimeEntry,Invoice,InvoiceLine}Factory.php` | Test fixtures |
| `app/Services/Timer/TimerService.php` | `start`/`stop`/`switch`/`discard` operations (transactional) |
| `app/Services/Invoicing/InvoiceNumberer.php` | Atomic `nextFor(int $year)` returning `YYYY-NNN` |
| `app/Services/Invoicing/QrReferenceGenerator.php` | Generates 27-digit QR reference with mod-10 recursive check digit |
| `app/Services/Invoicing/InvoiceBuilder.php` | `buildDraftFromEntries(Client $c, ?Project $p, Collection $entries, DateRange)` |
| `database/seeders/DemoFixturesSeeder.php` | Mirrors `design/ernte/project/data.jsx` so dev/demo data is realistic |
| `tests/Feature/Schema/TimeEntryInvariantsTest.php` | One-running-timer invariant via direct DB writes |
| `tests/Feature/Schema/ProjectAccessorsTest.php` | `spent_hours` / `spent_amount_rappen` / `band` accessors |
| `tests/Feature/Schema/InvoiceVatStampingTest.php` | Invoice keeps own `vat_rate` even if business default changes |
| `tests/Feature/Services/TimerServiceTest.php` | Start/stop/switch/discard semantics |
| `tests/Feature/Services/InvoiceNumbererTest.php` | Sequential, per-year, atomic |
| `tests/Feature/Services/QrReferenceGeneratorTest.php` | Length, check digit, uniqueness |
| `tests/Feature/Services/InvoiceBuilderTest.php` | Line grouping, entry attachment, VAT math |

Modified:
- `database/seeders/BootstrapSeeder.php` — also create BusinessProfile singleton from env
- `database/seeders/DatabaseSeeder.php` — leave BootstrapSeeder; do NOT auto-run DemoFixturesSeeder (called explicitly)
- `.env.example` — add `BUSINESS_*` keys

---

## Conventions

- **Branch:** create `phase-1-schema` from `main` before Task 1.
- **All shell commands run inside DDEV:** `ddev artisan`, `ddev composer`, `ddev exec`.
- **Money:** every monetary column is `unsignedBigInteger` storing **rappen** (CHF cents). Never use floats. Variable naming: any int rappen column ends in `_rappen`.
- **Dates vs instants:** `date` columns for calendar dates (issued_on, due_on, started_on), `datetime` for instants (started_at, ended_at, occurred_at, sent_at, paid_at).
- **Test DB:** Pest tests use the same DDEV MariaDB as dev. Test classes use the `RefreshDatabase` trait (`uses(Illuminate\Foundation\Testing\RefreshDatabase::class);` in `tests/Pest.php` if not already global — verify in Task 1).
- **Commits:** imperative, scoped. Same pattern as Phase 0.

---

## Task 0: Branch off main

- [ ] **Step 1: Create the working branch**

```
host$ git checkout -b phase-1-schema main
host$ git status
```
Expected: "On branch phase-1-schema, nothing to commit".

- [ ] **Step 2: Confirm baseline tests still pass**

```
host$ ddev artisan test
```
Expected: 36/36 pass.

This task has no commit — it's just setup.

---

## Task 1: BusinessProfile (singleton) + bootstrap env wiring

**Files:**
- Create: `database/migrations/<ts>_create_business_profile.php`
- Create: `app/Models/BusinessProfile.php`
- Modify: `database/seeders/BootstrapSeeder.php`
- Modify: `.env.example`
- Test: `tests/Feature/Schema/BusinessProfileSingletonTest.php`

- [ ] **Step 1: Generate migration file**

```
host$ ddev artisan make:migration create_business_profile
```
Replace `up()` and `down()`:
```php
public function up(): void
{
    Schema::create('business_profile', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('address_line_1')->nullable();
        $table->string('address_line_2')->nullable();
        $table->string('postal_code')->nullable();
        $table->string('city')->nullable();
        $table->string('country', 2)->default('CH');
        $table->string('uid')->nullable();           // CHE-XXX.XXX.XXX
        $table->string('vat_id')->nullable();        // CHE-XXX.XXX.XXX MWST
        $table->string('iban')->nullable();
        $table->string('qr_iban')->nullable();
        $table->string('email')->nullable();
        $table->string('logo_path')->nullable();
        $table->string('default_currency', 3)->default('CHF');
        $table->decimal('default_vat_rate', 5, 2)->default(8.10);
        $table->string('invoice_number_prefix')->default('');
        $table->unsignedSmallInteger('reminder_days_after_due')->default(7);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('business_profile');
}
```

- [ ] **Step 2: Create the model**

`app/Models/BusinessProfile.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'business_profile';

    protected $fillable = [
        'name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
        'uid', 'vat_id', 'iban', 'qr_iban', 'email', 'logo_path',
        'default_currency', 'default_vat_rate', 'invoice_number_prefix', 'reminder_days_after_due',
    ];

    protected $casts = [
        'default_vat_rate' => 'decimal:2',
        'reminder_days_after_due' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrFail();
    }
}
```

- [ ] **Step 3: Add BUSINESS_* env vars**

Append to `.env.example`:
```
BUSINESS_NAME="Your Name"
BUSINESS_ADDRESS_LINE_1=""
BUSINESS_POSTAL_CODE=""
BUSINESS_CITY=""
BUSINESS_COUNTRY=CH
BUSINESS_UID=""
BUSINESS_VAT_ID=""
BUSINESS_IBAN=""
BUSINESS_QR_IBAN=""
BUSINESS_EMAIL=""
BUSINESS_DEFAULT_CURRENCY=CHF
BUSINESS_DEFAULT_VAT_RATE=8.10
BUSINESS_REMINDER_DAYS_AFTER_DUE=7
```
Mirror to `.env` (leave the values as the defaults — user can fill in real data later via a settings page).

- [ ] **Step 4: Extend `BootstrapSeeder` to create the singleton**

Replace `database/seeders/BootstrapSeeder.php` with:
```php
<?php

namespace Database\Seeders;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ERNTE_USER_EMAIL', 'owner@ernte.local');
        $name = env('ERNTE_USER_NAME', 'Owner');
        $password = env('ERNTE_USER_PASSWORD', 'changeme');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'settings' => [
                    'theme' => 'paper',
                    'density' => 'comfortable',
                    'accent' => '#2d4a3a',
                ],
            ]
        );

        BusinessProfile::updateOrCreate(
            ['id' => 1],
            [
                'name' => env('BUSINESS_NAME', 'Your Name'),
                'address_line_1' => env('BUSINESS_ADDRESS_LINE_1', ''),
                'postal_code' => env('BUSINESS_POSTAL_CODE', ''),
                'city' => env('BUSINESS_CITY', ''),
                'country' => env('BUSINESS_COUNTRY', 'CH'),
                'uid' => env('BUSINESS_UID', ''),
                'vat_id' => env('BUSINESS_VAT_ID', ''),
                'iban' => env('BUSINESS_IBAN', ''),
                'qr_iban' => env('BUSINESS_QR_IBAN', ''),
                'email' => env('BUSINESS_EMAIL', ''),
                'default_currency' => env('BUSINESS_DEFAULT_CURRENCY', 'CHF'),
                'default_vat_rate' => env('BUSINESS_DEFAULT_VAT_RATE', '8.10'),
                'reminder_days_after_due' => (int) env('BUSINESS_REMINDER_DAYS_AFTER_DUE', 7),
            ]
        );
    }
}
```

- [ ] **Step 5: Write the singleton test**

`tests/Feature/Schema/BusinessProfileSingletonTest.php`:
```php
<?php

use App\Models\BusinessProfile;

test('BusinessProfile::current returns the singleton row', function () {
    BusinessProfile::create(['name' => 'Acme', 'country' => 'CH', 'default_currency' => 'CHF']);

    expect(BusinessProfile::current())
        ->toBeInstanceOf(BusinessProfile::class)
        ->name->toBe('Acme');
});

test('BusinessProfile::current throws when no row exists', function () {
    BusinessProfile::query()->delete();

    expect(fn () => BusinessProfile::current())
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('seeder creates a single BusinessProfile row', function () {
    $this->seed(\Database\Seeders\BootstrapSeeder::class);

    expect(BusinessProfile::count())->toBe(1);
});
```

- [ ] **Step 6: Run migration + tests**

```
host$ ddev artisan migrate
host$ ddev artisan test --filter=BusinessProfile
```
Expected: migration adds `business_profile` table; 3 tests PASS.

- [ ] **Step 7: Full suite**

```
host$ ddev artisan test
```
Expected: 39 passing (36 from Phase 0 + 3 new).

- [ ] **Step 8: Commit**

```
host$ git add -A
host$ git commit -m "feat(schema): business_profile singleton + bootstrap env wiring"
```

---

## Task 2: Clients

**Files:**
- Create: `database/migrations/<ts>_create_clients.php`
- Create: `app/Models/Client.php`
- Create: `database/factories/ClientFactory.php`
- Test: `tests/Feature/Schema/ClientTest.php`

- [ ] **Step 1: Generate migration**

```
host$ ddev artisan make:migration create_clients
```
Body:
```php
public function up(): void
{
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('short_code', 4);
        $table->string('contact_name')->nullable();
        $table->string('email')->nullable();
        $table->string('address_line_1')->nullable();
        $table->string('address_line_2')->nullable();
        $table->string('postal_code')->nullable();
        $table->string('city')->nullable();
        $table->string('country', 2)->default('CH');
        $table->string('vat_id')->nullable();
        $table->unsignedBigInteger('default_rate_rappen')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();

        $table->index('archived_at');
    });
}

public function down(): void
{
    Schema::dropIfExists('clients');
}
```

- [ ] **Step 2: Create model**

`app/Models/Client.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_code', 'contact_name', 'email',
        'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
        'vat_id', 'default_rate_rappen', 'archived_at',
    ];

    protected $casts = [
        'default_rate_rappen' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function scopeActive($q) { return $q->whereNull('archived_at'); }
    public function scopeArchived($q) { return $q->whereNotNull('archived_at'); }
}
```

- [ ] **Step 3: Create factory**

`database/factories/ClientFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $name = $this->faker->company();
        return [
            'name' => $name,
            'short_code' => strtoupper(substr(str_replace(' ', '', $name), 0, 2)),
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'address_line_1' => $this->faker->streetAddress(),
            'postal_code' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'country' => 'CH',
            'default_rate_rappen' => $this->faker->numberBetween(10000, 20000), // 100-200 CHF
        ];
    }

    public function archived(): self
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
```

- [ ] **Step 4: Write the failing test**

`tests/Feature/Schema/ClientTest.php`:
```php
<?php

use App\Models\Client;

test('client can be created with factory defaults', function () {
    $c = Client::factory()->create();

    expect($c)
        ->name->not->toBeEmpty()
        ->country->toBe('CH')
        ->default_rate_rappen->toBeInt();
});

test('active and archived scopes', function () {
    Client::factory()->count(3)->create();
    Client::factory()->archived()->count(2)->create();

    expect(Client::active()->count())->toBe(3);
    expect(Client::archived()->count())->toBe(2);
});
```

- [ ] **Step 5: Run migration and tests**

```
host$ ddev artisan migrate
host$ ddev artisan test --filter=Client
```
Expected: 2 PASS.

- [ ] **Step 6: Commit**

```
host$ git add -A
host$ git commit -m "feat(schema): clients table, model, factory"
```

---

## Task 3: Projects + Tasks

**Files:**
- Create: `database/migrations/<ts>_create_projects.php`
- Create: `database/migrations/<ts>_create_tasks.php`
- Create: `app/Models/Project.php`
- Create: `app/Models/Task.php`
- Create: `database/factories/ProjectFactory.php`
- Create: `database/factories/TaskFactory.php`
- Test: `tests/Feature/Schema/ProjectTest.php`
- Test: `tests/Feature/Schema/TaskTest.php`

- [ ] **Step 1: projects migration**

```
host$ ddev artisan make:migration create_projects
```
Body:
```php
public function up(): void
{
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
        $table->string('name');
        $table->string('code')->unique();
        $table->text('description')->nullable();
        $table->string('glyph')->default('alt-0');
        $table->enum('status', ['active', 'archived'])->default('active');
        $table->boolean('billable')->default(true);
        $table->boolean('retainer')->default(false);
        $table->unsignedInteger('retainer_hours')->nullable();
        $table->boolean('retainer_resets_monthly')->default(false);
        $table->unsignedInteger('budget_hours')->default(0);
        $table->unsignedBigInteger('budget_amount_rappen')->default(0);
        $table->unsignedBigInteger('rate_rappen')->default(0);
        $table->date('started_on')->nullable();
        $table->date('deadline_on')->nullable();
        $table->timestamps();

        $table->index(['client_id', 'status']);
    });
}

public function down(): void
{
    Schema::dropIfExists('projects');
}
```

- [ ] **Step 2: tasks migration**

```
host$ ddev artisan make:migration create_tasks
```
Body:
```php
public function up(): void
{
    Schema::create('tasks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
        $table->string('name');
        $table->unsignedInteger('budget_hours')->nullable();
        $table->boolean('done')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();

        $table->index(['project_id', 'done', 'sort_order']);
    });
}

public function down(): void
{
    Schema::dropIfExists('tasks');
}
```

- [ ] **Step 3: Project model with accessors**

`app/Models/Project.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'name', 'code', 'description', 'glyph', 'status',
        'billable', 'retainer', 'retainer_hours', 'retainer_resets_monthly',
        'budget_hours', 'budget_amount_rappen', 'rate_rappen',
        'started_on', 'deadline_on',
    ];

    protected $casts = [
        'billable' => 'boolean',
        'retainer' => 'boolean',
        'retainer_resets_monthly' => 'boolean',
        'budget_hours' => 'integer',
        'budget_amount_rappen' => 'integer',
        'rate_rappen' => 'integer',
        'started_on' => 'date',
        'deadline_on' => 'date',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function tasks() { return $this->hasMany(Task::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }

    public function scopeActive($q) { return $q->where('status', 'active'); }
    public function scopeArchived($q) { return $q->where('status', 'archived'); }

    public function spentHours(): float
    {
        $seconds = (int) $this->timeEntries()
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))), 0) AS seconds')
            ->value('seconds');
        return round($seconds / 3600, 2);
    }

    public function spentAmountRappen(): int
    {
        // Each entry is billed at its project's current rate (simple v1 rule).
        return (int) round($this->spentHours() * $this->rate_rappen);
    }

    public function percentHours(): int
    {
        if ($this->budget_hours <= 0) return 0;
        return (int) round(($this->spentHours() / $this->budget_hours) * 100);
    }

    protected function band(): Attribute
    {
        return Attribute::get(function () {
            $p = $this->percentHours();
            if ($p > 100) return 'over';
            if ($p >= 85) return 'warn';
            return 'ok';
        });
    }
}
```

- [ ] **Step 4: Task model**

`app/Models/Task.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'budget_hours', 'done', 'sort_order'];

    protected $casts = [
        'budget_hours' => 'integer',
        'done' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function project() { return $this->belongsTo(Project::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }
}
```

- [ ] **Step 5: Factories**

`database/factories/ProjectFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = $this->faker->bs();
        return [
            'client_id' => Client::factory(),
            'name' => ucfirst($name),
            'code' => strtoupper($this->faker->unique()->lexify('????-???')),
            'description' => $this->faker->sentence(),
            'glyph' => 'alt-' . $this->faker->numberBetween(0, 4),
            'status' => 'active',
            'billable' => true,
            'budget_hours' => $this->faker->numberBetween(40, 200),
            'budget_amount_rappen' => $this->faker->numberBetween(500000, 3000000),
            'rate_rappen' => $this->faker->randomElement([10000, 12000, 14000, 15000]),
            'started_on' => now()->subDays($this->faker->numberBetween(10, 120))->toDateString(),
            'deadline_on' => now()->addDays($this->faker->numberBetween(10, 90))->toDateString(),
        ];
    }

    public function retainer(): self
    {
        return $this->state(fn () => [
            'retainer' => true,
            'retainer_hours' => 16,
            'retainer_resets_monthly' => true,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn () => ['status' => 'archived']);
    }
}
```

`database/factories/TaskFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => ucfirst($this->faker->bs()),
            'budget_hours' => $this->faker->numberBetween(4, 32),
            'done' => false,
            'sort_order' => 0,
        ];
    }

    public function done(): self
    {
        return $this->state(fn () => ['done' => true]);
    }
}
```

- [ ] **Step 6: Tests**

`tests/Feature/Schema/ProjectTest.php`:
```php
<?php

use App\Models\Project;

test('project belongs to a client', function () {
    $p = Project::factory()->create();
    expect($p->client)->not->toBeNull();
});

test('project code is unique', function () {
    Project::factory()->create(['code' => 'ATLS-FLT']);
    expect(fn () => Project::factory()->create(['code' => 'ATLS-FLT']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('band accessor returns ok / warn / over based on percentHours', function () {
    $p = Project::factory()->create(['budget_hours' => 100, 'rate_rappen' => 10000]);
    expect($p->band)->toBe('ok');
});
```

`tests/Feature/Schema/TaskTest.php`:
```php
<?php

use App\Models\Task;

test('task belongs to a project', function () {
    $t = Task::factory()->create();
    expect($t->project)->not->toBeNull();
});

test('done state', function () {
    $t = Task::factory()->done()->create();
    expect($t->done)->toBeTrue();
});
```

- [ ] **Step 7: Migrate + run tests**

```
host$ ddev artisan migrate
host$ ddev artisan test --filter='ProjectTest|TaskTest'
```
Expected: 5 PASS.

- [ ] **Step 8: Commit**

```
host$ git add -A
host$ git commit -m "feat(schema): projects + tasks tables, models, factories"
```

---

## Task 4: TimeEntries with one-running invariant

**Files:**
- Create: `database/migrations/<ts>_create_time_entries.php`
- Create: `app/Models/TimeEntry.php`
- Create: `database/factories/TimeEntryFactory.php`
- Test: `tests/Feature/Schema/TimeEntryInvariantsTest.php`

- [ ] **Step 1: Write the failing invariant test first**

`tests/Feature/Schema/TimeEntryInvariantsTest.php`:
```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

test('a user can have at most one running time entry', function () {
    $user = User::factory()->create();
    $p1 = Project::factory()->create();
    $p2 = Project::factory()->create();

    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $p1->id,
        'started_at' => now()->subMinutes(30),
        'ended_at' => null,
    ]);

    expect(fn () => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $p2->id,
        'started_at' => now(),
        'ended_at' => null,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('a user can have many finished time entries', function () {
    $user = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->count(5)->create([
        'user_id' => $user->id,
        'project_id' => $p->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHour(),
    ]);

    expect(TimeEntry::where('user_id', $user->id)->count())->toBe(5);
});

test('different users can each have a running timer', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->create(['user_id' => $u1->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);
    TimeEntry::factory()->create(['user_id' => $u2->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);

    expect(TimeEntry::whereNull('ended_at')->count())->toBe(2);
});

test('TimeEntry::running scope returns only running entries', function () {
    $user = User::factory()->create();
    $p = Project::factory()->create();

    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $p->id, 'started_at' => now()->subHour(), 'ended_at' => now()]);
    $running = TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $p->id, 'started_at' => now(), 'ended_at' => null]);

    expect(TimeEntry::running()->pluck('id')->all())->toBe([$running->id]);
});
```

- [ ] **Step 2: Generate the migration**

```
host$ ddev artisan make:migration create_time_entries
```
Body:
```php
use Illuminate\Support\Facades\DB;

public function up(): void
{
    Schema::create('time_entries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
        $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        $table->string('description')->default('');
        $table->dateTime('started_at');
        $table->dateTime('ended_at')->nullable();
        $table->boolean('billable')->default(true);
        $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        $table->timestamps();

        $table->index('project_id');
        $table->index('task_id');
        $table->index('invoice_id');
        $table->index(['user_id', 'started_at']);
    });

    // Generated column: NULL when running (so NULL collisions don't fire);
    // 1 when running; unique on (user_id, is_running) → at most one NULL-collision-immune
    // running row per user. (MariaDB treats NULL as distinct in UNIQUE.)
    DB::statement(
        "ALTER TABLE time_entries ADD COLUMN is_running TINYINT GENERATED ALWAYS AS " .
        "(CASE WHEN ended_at IS NULL THEN 1 ELSE NULL END) STORED"
    );
    DB::statement("ALTER TABLE time_entries ADD UNIQUE KEY user_running (user_id, is_running)");
}

public function down(): void
{
    Schema::dropIfExists('time_entries');
}
```

**Note on the invariant:** MariaDB's UNIQUE allows multiple NULL values, so the generated column is `1` when running and `NULL` when finished. The unique on `(user_id, is_running)` then prevents two `1`s per user but allows any number of `NULL`s — exactly what we want.

Note also that `time_entries` references `invoices` (FK), but `invoices` doesn't exist yet. We solve this by adding the FK constraint without ON DELETE wiring **after** Task 5 creates `invoices`. For now: change the line to omit the FK constraint and only have the column.

Revise the migration body — replace the `invoice_id` line:
```php
$table->unsignedBigInteger('invoice_id')->nullable(); // FK added after invoices table exists
```
Then in Task 5 we'll add the FK in the invoices migration's up().

- [ ] **Step 3: Add `invoice_id` FK as part of the same migration's later ALTER (post-Task-5 hook)**

Skip this step's FK plumbing for now — instead, in Task 5's invoices migration we will add:
```php
DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_invoice_id_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL");
```

For Task 4, just leave `invoice_id` as an unconstrained nullable bigint.

- [ ] **Step 4: TimeEntry model**

`app/Models/TimeEntry.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'task_id', 'description',
        'started_at', 'ended_at', 'billable', 'invoice_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'billable' => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function task() { return $this->belongsTo(Task::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }

    public function scopeRunning($q) { return $q->whereNull('ended_at'); }
    public function scopeFinished($q) { return $q->whereNotNull('ended_at'); }
    public function scopeBillable($q) { return $q->where('billable', true); }
    public function scopeUnbilled($q) { return $q->whereNull('invoice_id'); }

    public function getDurationSecondsAttribute(): int
    {
        // Carbon 3 changed diff semantics; using raw timestamps avoids sign confusion.
        $endTs = ($this->ended_at ?? now())->getTimestamp();
        return max(0, $endTs - $this->started_at->getTimestamp());
    }

    public function getRunningAttribute(): bool
    {
        return $this->ended_at === null;
    }
}
```

- [ ] **Step 5: Factory**

`database/factories/TimeEntryFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        $start = now()->subHours($this->faker->numberBetween(1, 48));
        $end = (clone $start)->addMinutes($this->faker->numberBetween(15, 180));

        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'task_id' => null,
            'description' => ucfirst($this->faker->bs()),
            'started_at' => $start,
            'ended_at' => $end,
            'billable' => true,
            'invoice_id' => null,
        ];
    }

    public function running(): self
    {
        return $this->state(fn () => ['ended_at' => null]);
    }
}
```

- [ ] **Step 6: Run tests — they should fail before migration, pass after**

```
host$ ddev artisan migrate
host$ ddev artisan test --filter=TimeEntryInvariants
```
Expected: all 4 PASS.

- [ ] **Step 7: Commit**

```
host$ git add -A
host$ git commit -m "feat(schema): time_entries with one-running-timer invariant (generated column + unique)"
```

---

## Task 5: Invoices + invoice_lines + invoice_events + invoice_counters

**Files:**
- Create: `database/migrations/<ts>_create_invoice_counters.php`
- Create: `database/migrations/<ts>_create_invoices.php`
- Create: `database/migrations/<ts>_create_invoice_lines.php`
- Create: `database/migrations/<ts>_create_invoice_events.php`
- Create: `app/Models/InvoiceCounter.php`
- Create: `app/Models/Invoice.php`
- Create: `app/Models/InvoiceLine.php`
- Create: `app/Models/InvoiceEvent.php`
- Create: `database/factories/InvoiceFactory.php`
- Create: `database/factories/InvoiceLineFactory.php`
- Test: `tests/Feature/Schema/InvoiceStructureTest.php`

- [ ] **Step 1: invoice_counters migration (created FIRST so InvoiceNumberer can use it)**

```
host$ ddev artisan make:migration create_invoice_counters
```
Body:
```php
public function up(): void
{
    Schema::create('invoice_counters', function (Blueprint $table) {
        $table->unsignedSmallInteger('year')->primary();
        $table->unsignedInteger('last_n')->default(0);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('invoice_counters');
}
```

- [ ] **Step 2: invoices migration**

```
host$ ddev artisan make:migration create_invoices
```
Body:
```php
use Illuminate\Support\Facades\DB;

public function up(): void
{
    Schema::create('invoices', function (Blueprint $table) {
        $table->id();
        $table->string('number')->unique();
        $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
        $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
        $table->date('period_start')->nullable();
        $table->date('period_end')->nullable();
        $table->date('issued_on')->nullable();
        $table->date('due_on')->nullable();
        $table->enum('status', ['draft', 'sent', 'paid', 'void'])->default('draft');
        $table->string('currency', 3)->default('CHF');
        $table->decimal('vat_rate', 5, 2)->default(8.10);
        $table->unsignedBigInteger('subtotal_rappen')->default(0);
        $table->unsignedBigInteger('vat_rappen')->default(0);
        $table->unsignedBigInteger('total_rappen')->default(0);
        $table->text('notes')->nullable();
        $table->string('qr_reference', 27)->nullable()->unique();
        $table->dateTime('sent_at')->nullable();
        $table->dateTime('paid_at')->nullable();
        $table->string('pdf_path')->nullable();
        $table->timestamps();

        $table->index('status');
        $table->index('client_id');
        $table->index('due_on');
    });

    // Now wire the deferred FK from time_entries.invoice_id (Task 4 left it loose).
    DB::statement(
        "ALTER TABLE time_entries ADD CONSTRAINT time_entries_invoice_id_fk " .
        "FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL"
    );
}

public function down(): void
{
    DB::statement("ALTER TABLE time_entries DROP FOREIGN KEY time_entries_invoice_id_fk");
    Schema::dropIfExists('invoices');
}
```

- [ ] **Step 3: invoice_lines migration**

```
host$ ddev artisan make:migration create_invoice_lines
```
Body:
```php
public function up(): void
{
    Schema::create('invoice_lines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
        $table->text('description');
        $table->decimal('hours', 10, 2);
        $table->unsignedBigInteger('rate_rappen');
        $table->unsignedBigInteger('amount_rappen');
        $table->boolean('vat_exempt')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();

        $table->index(['invoice_id', 'sort_order']);
    });
}

public function down(): void
{
    Schema::dropIfExists('invoice_lines');
}
```

- [ ] **Step 4: invoice_events migration**

```
host$ ddev artisan make:migration create_invoice_events
```
Body:
```php
public function up(): void
{
    Schema::create('invoice_events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
        $table->enum('kind', ['created', 'sent', 'reminded', 'paid', 'pdf_generated', 'voided', 'overdue_stamped']);
        $table->dateTime('occurred_at');
        $table->json('payload')->nullable();
        $table->timestamps();

        $table->index(['invoice_id', 'occurred_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('invoice_events');
}
```

- [ ] **Step 5: Models**

`app/Models/InvoiceCounter.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceCounter extends Model
{
    protected $primaryKey = 'year';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['year', 'last_n'];
    protected $casts = ['year' => 'integer', 'last_n' => 'integer'];
}
```

`app/Models/Invoice.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'project_id',
        'period_start', 'period_end', 'issued_on', 'due_on',
        'status', 'currency', 'vat_rate',
        'subtotal_rappen', 'vat_rappen', 'total_rappen',
        'notes', 'qr_reference', 'sent_at', 'paid_at', 'pdf_path',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'subtotal_rappen' => 'integer',
        'vat_rappen' => 'integer',
        'total_rappen' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(InvoiceLine::class); }
    public function events() { return $this->hasMany(InvoiceEvent::class); }
    public function timeEntries() { return $this->hasMany(TimeEntry::class); }

    public function getOverdueAttribute(): bool
    {
        return $this->status === 'sent'
            && $this->due_on !== null
            && $this->due_on->isPast();
    }
}
```

`app/Models/InvoiceLine.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'description', 'hours', 'rate_rappen', 'amount_rappen',
        'vat_exempt', 'sort_order',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate_rappen' => 'integer',
        'amount_rappen' => 'integer',
        'vat_exempt' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
```

`app/Models/InvoiceEvent.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceEvent extends Model
{
    protected $fillable = ['invoice_id', 'kind', 'occurred_at', 'payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
```

- [ ] **Step 6: Factories**

`database/factories/InvoiceFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            // Faker unique() ensures no collisions within a process; format is human-readable
            // for tests but does not need to match production YYYY-NNN exactly.
            'number' => now()->year . '-T' . str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
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
            'issued_on' => now()->subDays(5)->toDateString(),
            'due_on' => now()->addDays(25)->toDateString(),
            'sent_at' => now()->subDays(5),
        ]);
    }

    public function paid(): self
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'issued_on' => now()->subDays(20)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'sent_at' => now()->subDays(20),
            'paid_at' => now()->subDays(2),
        ]);
    }
}
```

`database/factories/InvoiceLineFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        $hours = $this->faker->randomFloat(2, 1, 20);
        $rate = 14500; // 145 CHF/h
        return [
            'invoice_id' => Invoice::factory(),
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

- [ ] **Step 7: Structure tests**

`tests/Feature/Schema/InvoiceStructureTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\TimeEntry;

test('invoice has many lines and events', function () {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory()->count(3)->create(['invoice_id' => $invoice->id]);
    InvoiceEvent::create(['invoice_id' => $invoice->id, 'kind' => 'created', 'occurred_at' => now()]);

    expect($invoice->fresh()->lines)->toHaveCount(3);
    expect($invoice->fresh()->events)->toHaveCount(1);
});

test('cannot delete a client with invoices (restrictOnDelete)', function () {
    $client = Client::factory()->create();
    Invoice::factory()->create(['client_id' => $client->id]);

    expect(fn () => $client->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('deleting an invoice sets time_entries.invoice_id to NULL', function () {
    $invoice = Invoice::factory()->create();
    $entry = TimeEntry::factory()->create(['invoice_id' => $invoice->id]);

    $invoice->delete();

    expect($entry->fresh()->invoice_id)->toBeNull();
});

test('overdue accessor', function () {
    $i = Invoice::factory()->sent()->create(['due_on' => now()->subDay()->toDateString()]);
    expect($i->overdue)->toBeTrue();

    $i2 = Invoice::factory()->paid()->create();
    expect($i2->overdue)->toBeFalse();
});
```

- [ ] **Step 8: Migrate + tests**

```
host$ ddev artisan migrate
host$ ddev artisan test --filter='Invoice|TimeEntryInvariants'
```
Expected: all PASS.

- [ ] **Step 9: Commit**

```
host$ git add -A
host$ git commit -m "feat(schema): invoices + lines + events + counters; wire time_entries.invoice_id FK"
```

---

## Task 6: Backups table

**Files:**
- Create: `database/migrations/<ts>_create_backups.php`
- Create: `app/Models/Backup.php`

Small table for the statusbar's "backup Nh ago" display.

- [ ] **Step 1: Migration**

```
host$ ddev artisan make:migration create_backups
```
Body:
```php
public function up(): void
{
    Schema::create('backups', function (Blueprint $table) {
        $table->id();
        $table->string('path');
        $table->unsignedBigInteger('size_bytes');
        $table->timestamp('created_at')->useCurrent();
    });
}

public function down(): void
{
    Schema::dropIfExists('backups');
}
```

- [ ] **Step 2: Model**

`app/Models/Backup.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    public $timestamps = false;

    protected $fillable = ['path', 'size_bytes', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public static function latest(): ?self
    {
        return static::query()->orderByDesc('created_at')->first();
    }
}
```

- [ ] **Step 3: Migrate + commit**

```
host$ ddev artisan migrate
host$ git add -A
host$ git commit -m "feat(schema): backups log table"
```

(No dedicated test — model is trivial and used only by the future backup command and statusbar.)

---

## Task 7: TimerService (TDD)

**Files:**
- Create: `app/Services/Timer/TimerService.php`
- Test: `tests/Feature/Services/TimerServiceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/TimerServiceTest.php`:
```php
<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Timer\TimerService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->p1 = Project::factory()->create();
    $this->p2 = Project::factory()->create();
    $this->svc = app(TimerService::class);
});

test('start creates a running entry for the user', function () {
    $entry = $this->svc->start($this->user, $this->p1, null, 'Map mode');

    expect($entry)
        ->user_id->toBe($this->user->id)
        ->project_id->toBe($this->p1->id)
        ->description->toBe('Map mode')
        ->ended_at->toBeNull();

    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('start stops any pre-existing running entry atomically', function () {
    $first = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $second = $this->svc->start($this->user, $this->p2);

    expect($first->fresh())->ended_at->not->toBeNull();
    expect($second->fresh())->ended_at->toBeNull();
    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('stop finalizes the running entry', function () {
    $entry = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $stopped = $this->svc->stop($this->user);

    expect($stopped)->id->toBe($entry->id);
    expect($stopped->ended_at)->not->toBeNull();
    expect(TimeEntry::running()->where('user_id', $this->user->id)->count())->toBe(0);
});

test('stop returns null when nothing is running', function () {
    expect($this->svc->stop($this->user))->toBeNull();
});

test('switch is start with auto-stop', function () {
    $first = $this->svc->start($this->user, $this->p1);
    sleep(1);
    $second = $this->svc->switch($this->user, $this->p2, null, 'PR review');

    expect($first->fresh()->ended_at)->not->toBeNull();
    expect($second)->project_id->toBe($this->p2->id);
    expect($second->description)->toBe('PR review');
});

test('discard hard-deletes the running entry', function () {
    $this->svc->start($this->user, $this->p1);
    $this->svc->discard($this->user);

    expect(TimeEntry::where('user_id', $this->user->id)->count())->toBe(0);
});

test('billable defaults to the project value', function () {
    $billable = Project::factory()->create(['billable' => true]);
    $non = Project::factory()->create(['billable' => false]);

    expect($this->svc->start($this->user, $billable)->billable)->toBeTrue();
    expect($this->svc->switch($this->user, $non)->billable)->toBeFalse();
});
```

- [ ] **Step 2: Run — should fail**

```
host$ ddev artisan test --filter=TimerService
```
Expected: FAIL with "class TimerService not found".

- [ ] **Step 3: Implement the service**

`app/Services/Timer/TimerService.php`:
```php
<?php

namespace App\Services\Timer;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TimerService
{
    public function start(User $user, Project $project, ?Task $task = null, string $description = ''): TimeEntry
    {
        return DB::transaction(function () use ($user, $project, $task, $description) {
            $this->stopRunningFor($user);

            return TimeEntry::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'description' => $description,
                'started_at' => now(),
                'ended_at' => null,
                'billable' => (bool) $project->billable,
            ]);
        });
    }

    public function switch(User $user, Project $project, ?Task $task = null, string $description = ''): TimeEntry
    {
        return $this->start($user, $project, $task, $description);
    }

    public function stop(User $user): ?TimeEntry
    {
        return DB::transaction(function () use ($user) {
            return $this->stopRunningFor($user);
        });
    }

    public function discard(User $user): void
    {
        DB::transaction(function () use ($user) {
            TimeEntry::running()->where('user_id', $user->id)->delete();
        });
    }

    private function stopRunningFor(User $user): ?TimeEntry
    {
        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $running) {
            return null;
        }

        $running->ended_at = now();
        $running->save();

        return $running;
    }
}
```

- [ ] **Step 4: Run — should pass**

```
host$ ddev artisan test --filter=TimerService
```
Expected: 7 PASS.

- [ ] **Step 5: Commit**

```
host$ git add -A
host$ git commit -m "feat(timer): TimerService with transactional start/stop/switch/discard"
```

---

## Task 8: InvoiceNumberer (TDD)

**Files:**
- Create: `app/Services/Invoicing/InvoiceNumberer.php`
- Test: `tests/Feature/Services/InvoiceNumbererTest.php`

- [ ] **Step 1: Failing tests**

`tests/Feature/Services/InvoiceNumbererTest.php`:
```php
<?php

use App\Models\InvoiceCounter;
use App\Services\Invoicing\InvoiceNumberer;

beforeEach(function () {
    $this->svc = app(InvoiceNumberer::class);
});

test('first number for a fresh year is YYYY-001', function () {
    expect($this->svc->nextFor(2026))->toBe('2026-001');
});

test('numbers increment sequentially within a year', function () {
    $this->svc->nextFor(2026);
    $this->svc->nextFor(2026);
    expect($this->svc->nextFor(2026))->toBe('2026-003');
});

test('years have independent counters', function () {
    $this->svc->nextFor(2026);
    $this->svc->nextFor(2026);
    expect($this->svc->nextFor(2027))->toBe('2027-001');
    expect($this->svc->nextFor(2026))->toBe('2026-003');
});

test('counter row is created/updated atomically', function () {
    $this->svc->nextFor(2026);
    $row = InvoiceCounter::find(2026);
    expect($row)->last_n->toBe(1);
});

test('counter persists across many allocations', function () {
    for ($i = 0; $i < 15; $i++) {
        $this->svc->nextFor(2026);
    }
    expect(InvoiceCounter::find(2026)->last_n)->toBe(15);
});
```

- [ ] **Step 2: Run — should fail**

```
host$ ddev artisan test --filter=InvoiceNumberer
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/Invoicing/InvoiceNumberer.php`:
```php
<?php

namespace App\Services\Invoicing;

use Illuminate\Support\Facades\DB;

class InvoiceNumberer
{
    public function nextFor(int $year): string
    {
        // Atomic increment in MariaDB: INSERT … ON DUPLICATE KEY UPDATE.
        // last_n becomes the new value via LAST_INSERT_ID() trick.
        $n = DB::transaction(function () use ($year) {
            DB::statement(
                "INSERT INTO invoice_counters (year, last_n, created_at, updated_at) " .
                "VALUES (?, 1, NOW(), NOW()) " .
                "ON DUPLICATE KEY UPDATE last_n = LAST_INSERT_ID(last_n + 1), updated_at = NOW()",
                [$year]
            );
            return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
        });

        return sprintf('%d-%03d', $year, $n);
    }
}
```

The `LAST_INSERT_ID(expr)` trick (MySQL/MariaDB-specific) lets us read back the updated value within the same statement — guaranteed atomic regardless of concurrent inserts.

- [ ] **Step 4: Run — should pass**

```
host$ ddev artisan test --filter=InvoiceNumberer
```
Expected: 5 PASS.

- [ ] **Step 5: Commit**

```
host$ git add -A
host$ git commit -m "feat(invoicing): atomic InvoiceNumberer with per-year counter"
```

---

## Task 9: QrReferenceGenerator (TDD)

**Files:**
- Create: `app/Services/Invoicing/QrReferenceGenerator.php`
- Test: `tests/Feature/Services/QrReferenceGeneratorTest.php`

The Swiss QR-bill QRR (Creditor Reference) is exactly 27 digits: 26 free + 1 check digit, computed via mod-10 recursive ("Modulo 10 rekursiv").

- [ ] **Step 1: Tests**

`tests/Feature/Services/QrReferenceGeneratorTest.php`:
```php
<?php

use App\Services\Invoicing\QrReferenceGenerator;

beforeEach(function () {
    $this->svc = app(QrReferenceGenerator::class);
});

test('reference is exactly 27 digits', function () {
    $ref = $this->svc->generate(42);
    expect(strlen($ref))->toBe(27);
    expect($ref)->toMatch('/^\d{27}$/');
});

test('reference embeds the invoice id in the right-most digits before the check digit', function () {
    // 26 free digits, padded; the last 6 of those should be the zero-padded invoice id.
    $ref = $this->svc->generate(42);
    $payload = substr($ref, 0, 26);
    expect(substr($payload, -6))->toBe('000042');
});

test('the trailing check digit is the mod-10 recursive of the first 26', function () {
    $ref = $this->svc->generate(42);
    $payload = substr($ref, 0, 26);
    $check = (int) substr($ref, -1);

    expect($check)->toBe($this->svc->checkDigit($payload));
});

test('checkDigit known vector: empty string yields 0', function () {
    expect($this->svc->checkDigit(''))->toBe(0);
});

test('checkDigit known vector: "210000000003139471430009017" — Swiss spec example', function () {
    // From the Swiss Implementation Guidelines QR-bill, sample reference.
    // Payload (26 digits): 21000000000313947143000901
    // Expected check digit: 7
    expect($this->svc->checkDigit('21000000000313947143000901'))->toBe(7);
});

test('two references for the same id are deterministic', function () {
    expect($this->svc->generate(42))->toBe($this->svc->generate(42));
});
```

- [ ] **Step 2: Run — should fail**

```
host$ ddev artisan test --filter=QrReferenceGenerator
```

- [ ] **Step 3: Implement**

`app/Services/Invoicing/QrReferenceGenerator.php`:
```php
<?php

namespace App\Services\Invoicing;

class QrReferenceGenerator
{
    /**
     * Mod-10 recursive lookup table (Swiss QR-bill standard, ISO 11649 / Modulo 10 rekursiv).
     */
    private const TABLE = [
        [0, 9, 4, 6, 8, 2, 7, 1, 3, 5],
        [9, 4, 6, 8, 2, 7, 1, 3, 5, 0],
        [4, 6, 8, 2, 7, 1, 3, 5, 0, 9],
        [6, 8, 2, 7, 1, 3, 5, 0, 9, 4],
        [8, 2, 7, 1, 3, 5, 0, 9, 4, 6],
        [2, 7, 1, 3, 5, 0, 9, 4, 6, 8],
        [7, 1, 3, 5, 0, 9, 4, 6, 8, 2],
        [1, 3, 5, 0, 9, 4, 6, 8, 2, 7],
        [3, 5, 0, 9, 4, 6, 8, 2, 7, 1],
        [5, 0, 9, 4, 6, 8, 2, 7, 1, 3],
    ];

    /**
     * Build a 27-digit QRR for the given invoice id.
     *
     * Layout (left-to-right):
     *   - 20 digits of zero padding (could later become bank prefix + customer ref)
     *   - 6 digits of zero-padded invoice id
     *   - 1 mod-10 recursive check digit
     */
    public function generate(int $invoiceId): string
    {
        $payload = str_pad((string) $invoiceId, 26, '0', STR_PAD_LEFT);
        return $payload . $this->checkDigit($payload);
    }

    /**
     * Compute the mod-10 recursive check digit for a numeric string.
     */
    public function checkDigit(string $digits): int
    {
        $carry = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            $carry = self::TABLE[$carry][$d];
        }
        return (10 - $carry) % 10;
    }
}
```

- [ ] **Step 4: Run — should pass**

```
host$ ddev artisan test --filter=QrReferenceGenerator
```
Expected: 6 PASS.

- [ ] **Step 5: Commit**

```
host$ git add -A
host$ git commit -m "feat(invoicing): QrReferenceGenerator with mod-10 recursive check digit"
```

---

## Task 10: InvoiceBuilder (TDD)

**Files:**
- Create: `app/Services/Invoicing/InvoiceBuilder.php`
- Test: `tests/Feature/Services/InvoiceBuilderTest.php`

The builder takes a client, an optional project, a collection of selected `TimeEntry` rows, and a period. It groups entries by description (or task name fallback) into lines, computes amounts, snapshots the VAT rate from `business_profile`, attaches entries to the new invoice, and writes a `created` event.

- [ ] **Step 1: Tests**

`tests/Feature/Services/InvoiceBuilderTest.php`:
```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'billable' => true,
        'rate_rappen' => 14500, // 145 CHF/h
    ]);
    $this->svc = app(InvoiceBuilder::class);
});

function makeEntry($user, $project, string $desc, int $minutes, bool $billable = true): TimeEntry
{
    $start = now()->subDays(2);
    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'description' => $desc,
        'started_at' => $start,
        'ended_at' => (clone $start)->addMinutes($minutes),
        'billable' => $billable,
    ]);
}

test('builds a draft invoice with one line per distinct description', function () {
    $e1 = makeEntry($this->user, $this->project, 'PR review', 60);
    $e2 = makeEntry($this->user, $this->project, 'PR review', 30);
    $e3 = makeEntry($this->user, $this->project, 'Telemetry', 120);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client,
        $this->project,
        TimeEntry::all(),
        now()->subDays(7)->toDateString(),
        now()->toDateString()
    );

    expect($invoice)->status->toBe('draft');
    expect($invoice->lines)->toHaveCount(2);

    $pr = $invoice->lines->firstWhere('description', 'PR review');
    expect((float) $pr->hours)->toBe(1.5);
    expect($pr->rate_rappen)->toBe(14500);
    expect($pr->amount_rappen)->toBe(21750);

    $tel = $invoice->lines->firstWhere('description', 'Telemetry');
    expect((float) $tel->hours)->toBe(2.0);
    expect($tel->amount_rappen)->toBe(29000);
});

test('subtotal/vat/total are computed from line amounts', function () {
    makeEntry($this->user, $this->project, 'A', 60); // 1h × 145 = 14500
    makeEntry($this->user, $this->project, 'B', 60); // 1h × 145 = 14500

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->subtotal_rappen)->toBe(29000);
    expect($invoice->vat_rappen)->toBe(2349);  // 29000 * 8.10% = 2349 (rounded)
    expect($invoice->total_rappen)->toBe(31349);
});

test('vat_rate is stamped from business_profile at build time', function () {
    BusinessProfile::current()->update(['default_vat_rate' => 7.70]);

    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect((float) $invoice->vat_rate)->toBe(7.70);

    // Now change business default; existing invoice should not change.
    BusinessProfile::current()->update(['default_vat_rate' => 8.10]);
    expect((float) $invoice->fresh()->vat_rate)->toBe(7.70);
});

test('selected entries are attached to the new invoice (invoice_id set)', function () {
    $e = makeEntry($this->user, $this->project, 'Work', 60);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, collect([$e]),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($e->fresh()->invoice_id)->toBe($invoice->id);
});

test('a created invoice_event is written', function () {
    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->events()->where('kind', 'created')->count())->toBe(1);
});

test('non-billable entries are excluded from line grouping', function () {
    makeEntry($this->user, $this->project, 'Billable', 60, true);
    makeEntry($this->user, $this->project, 'Internal', 60, false);

    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('Billable');
});

test('a number is allocated via InvoiceNumberer', function () {
    makeEntry($this->user, $this->project, 'Work', 60);
    $invoice = $this->svc->buildDraftFromEntries(
        $this->client, $this->project, TimeEntry::all(),
        now()->subDays(1)->toDateString(), now()->toDateString()
    );

    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/');
});
```

- [ ] **Step 2: Run — should fail**

```
host$ ddev artisan test --filter=InvoiceBuilder
```

- [ ] **Step 3: Implement**

`app/Services/Invoicing/InvoiceBuilder.php`:
```php
<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceBuilder
{
    public function __construct(
        private InvoiceNumberer $numberer,
        private QrReferenceGenerator $qr,
    ) {}

    /**
     * Build a draft invoice from selected time entries.
     */
    public function buildDraftFromEntries(
        Client $client,
        ?Project $project,
        Collection $entries,
        string $periodStart,
        string $periodEnd,
    ): Invoice {
        return DB::transaction(function () use ($client, $project, $entries, $periodStart, $periodEnd) {
            $profile = BusinessProfile::current();

            // Filter to billable, unbilled entries that match the period and the (optional) project.
            $eligible = $entries
                ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
                ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
                ->values();

            // Group by description (or task fallback if description empty).
            $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
                ? $e->description
                : ('Task #' . $e->task_id));

            // Allocate number and create header (no QR ref until we know the ID).
            $year = (int) date('Y');
            $number = $this->numberer->nextFor($year);

            $invoice = Invoice::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $profile->default_vat_rate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
            ]);

            // Now that we have an id, fill the QR reference.
            $invoice->qr_reference = $this->qr->generate($invoice->id);

            // Build lines from groups.
            $subtotal = 0;
            $sort = 0;
            foreach ($groups as $description => $bucket) {
                /** @var Collection<int, TimeEntry> $bucket */
                $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
                // Use the project's rate of the first entry in the group (all entries in v1 share a project rate).
                $rate = (int) ($bucket->first()->project->rate_rappen ?? 0);
                $amount = (int) round($hours * $rate);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => $description,
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'vat_exempt' => false,
                    'sort_order' => $sort++,
                ]);

                $subtotal += $amount;
            }

            $vat = (int) round($subtotal * ((float) $invoice->vat_rate) / 100);
            $total = $subtotal + $vat;

            $invoice->subtotal_rappen = $subtotal;
            $invoice->vat_rappen = $vat;
            $invoice->total_rappen = $total;
            $invoice->save();

            // Attach entries.
            TimeEntry::whereIn('id', $eligible->pluck('id'))->update(['invoice_id' => $invoice->id]);

            // Audit event.
            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => [
                    'period' => ['start' => $periodStart, 'end' => $periodEnd],
                    'entries_count' => $eligible->count(),
                ],
            ]);

            return $invoice->fresh(['lines', 'events']);
        });
    }
}
```

- [ ] **Step 4: Run — should pass**

```
host$ ddev artisan test --filter=InvoiceBuilder
```
Expected: 7 PASS.

- [ ] **Step 5: Commit**

```
host$ git add -A
host$ git commit -m "feat(invoicing): InvoiceBuilder builds draft from selected entries with line grouping + VAT math"
```

---

## Task 11: DemoFixturesSeeder

**Files:**
- Create: `database/seeders/DemoFixturesSeeder.php`

Mirrors `design/ernte/project/data.jsx` so a dev environment looks like the design. Run manually with `ddev artisan db:seed --class=DemoFixturesSeeder` — NOT auto-called by `DatabaseSeeder`.

- [ ] **Step 1: Create the seeder**

`database/seeders/DemoFixturesSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DemoFixturesSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first() ?? User::factory()->create();

        DB::transaction(function () use ($user) {
            $clients = collect([
                ['name' => 'Atlas Robotics',         'short_code' => 'AR', 'contact_name' => 'Marit Hesse',    'email' => 'marit@atlas.dev',   'default_rate_rappen' => 14500],
                ['name' => 'Körbel & Söhne GmbH',    'short_code' => 'KS', 'contact_name' => 'Stefan Körbel',  'email' => 's.korbel@korbel.de','default_rate_rappen' => 12000],
                ['name' => 'Northlit Press',         'short_code' => 'NP', 'contact_name' => 'Annie Park',     'email' => 'annie@northlit.co', 'default_rate_rappen' => 13000],
                ['name' => 'Halden Studio',          'short_code' => 'HS', 'contact_name' => 'Ola Halden',     'email' => 'ola@halden.no',     'default_rate_rappen' => 11000],
                ['name' => 'Kelp Forest Co.',        'short_code' => 'KF', 'contact_name' => 'Mira Okafor',    'email' => 'mira@kelp.co',      'default_rate_rappen' => 15000],
                ['name' => 'Private / Internal',     'short_code' => 'PR', 'contact_name' => null,             'email' => null,                'default_rate_rappen' => null],
            ])->map(fn ($c) => Client::firstOrCreate(['short_code' => $c['short_code']], $c));

            $projectDefs = [
                ['code' => 'ATLS-FLT', 'client' => 'AR', 'name' => 'Fleet Console v2',         'budget_hours' => 220, 'budget_amount_rappen' => 3190000, 'rate_rappen' => 14500],
                ['code' => 'KS-ERP',   'client' => 'KS', 'name' => 'ERP Migration',            'budget_hours' => 160, 'budget_amount_rappen' => 1920000, 'rate_rappen' => 12000],
                ['code' => 'ATLS-DOC', 'client' => 'AR', 'name' => 'Developer Docs Portal',    'budget_hours' => 80,  'budget_amount_rappen' => 1160000, 'rate_rappen' => 14500],
                ['code' => 'NL-WEB',   'client' => 'NP', 'name' => 'Marketing Site Refresh',   'budget_hours' => 120, 'budget_amount_rappen' => 1560000, 'rate_rappen' => 13000],
                ['code' => 'KF-BRD',   'client' => 'KF', 'name' => 'Brand System',             'budget_hours' => 60,  'budget_amount_rappen' => 900000,  'rate_rappen' => 15000],
                ['code' => 'HS-IOS',   'client' => 'HS', 'name' => 'iOS App MVP',              'budget_hours' => 100, 'budget_amount_rappen' => 1100000, 'rate_rappen' => 11000],
                ['code' => 'KS-SUP',   'client' => 'KS', 'name' => 'Retainer / Support',       'budget_hours' => 16,  'budget_amount_rappen' => 192000,  'rate_rappen' => 12000, 'retainer' => true],
                ['code' => 'ERNTE',    'client' => 'PR', 'name' => 'ernte (self)',             'budget_hours' => 0,   'budget_amount_rappen' => 0,       'rate_rappen' => 0,     'billable' => false],
            ];

            foreach ($projectDefs as $d) {
                $client = $clients->firstWhere('short_code', $d['client']);
                Project::firstOrCreate(
                    ['code' => $d['code']],
                    [
                        'client_id' => $client->id,
                        'name' => $d['name'],
                        'glyph' => 'alt-' . random_int(0, 4),
                        'status' => 'active',
                        'billable' => $d['billable'] ?? true,
                        'retainer' => $d['retainer'] ?? false,
                        'retainer_hours' => ($d['retainer'] ?? false) ? 16 : null,
                        'retainer_resets_monthly' => $d['retainer'] ?? false,
                        'budget_hours' => $d['budget_hours'],
                        'budget_amount_rappen' => $d['budget_amount_rappen'],
                        'rate_rappen' => $d['rate_rappen'],
                        'started_on' => Carbon::now()->subMonths(random_int(1, 4))->toDateString(),
                        'deadline_on' => Carbon::now()->addMonths(random_int(1, 4))->toDateString(),
                    ]
                );
            }

            $atlasFleet = Project::where('code', 'ATLS-FLT')->first();
            $taskDefs = [
                ['name' => 'Map mode: cluster rendering', 'budget_hours' => 16, 'done' => false],
                ['name' => 'Telemetry side-panel',         'budget_hours' => 20, 'done' => false],
                ['name' => 'Operator role permissions',    'budget_hours' => 12, 'done' => false],
                ['name' => 'PR review queue',              'budget_hours' => 30, 'done' => false],
                ['name' => 'Discovery / spec',             'budget_hours' => 24, 'done' => true],
                ['name' => 'Auth refactor',                'budget_hours' => 18, 'done' => true],
                ['name' => 'Storybook scaffold',           'budget_hours' => 8,  'done' => true],
            ];
            foreach ($taskDefs as $i => $d) {
                Task::firstOrCreate(
                    ['project_id' => $atlasFleet->id, 'name' => $d['name']],
                    ['budget_hours' => $d['budget_hours'], 'done' => $d['done'], 'sort_order' => $i]
                );
            }

            // Today's entries — finished ones.
            $today = Carbon::today();
            $entries = [
                ['project' => 'ATLS-FLT', 'desc' => 'Map mode: cluster rendering', 'start' => '09:12', 'end' => '10:48'],
                ['project' => 'KS-SUP',   'desc' => 'Sync bug — partner export',    'start' => '10:52', 'end' => '11:24'],
                ['project' => 'ATLS-FLT', 'desc' => 'Code review: PR #318',         'start' => '13:30', 'end' => '14:05'],
                ['project' => 'ATLS-DOC', 'desc' => 'Versioned routing prototype',  'start' => '14:18', 'end' => '15:42'],
                ['project' => 'ERNTE',    'desc' => 'Invoice PDF template',         'start' => '15:55', 'end' => '16:22'],
            ];
            foreach ($entries as $e) {
                $p = Project::where('code', $e['project'])->first();
                TimeEntry::create([
                    'user_id' => $user->id,
                    'project_id' => $p->id,
                    'description' => $e['desc'],
                    'started_at' => $today->copy()->setTimeFromTimeString($e['start']),
                    'ended_at' => $today->copy()->setTimeFromTimeString($e['end']),
                    'billable' => (bool) $p->billable,
                ]);
            }
        });
    }
}
```

- [ ] **Step 2: Run the seeder to smoke-test it**

```
host$ ddev artisan db:seed --class=DemoFixturesSeeder
host$ ddev artisan tinker --execute='echo \App\Models\Project::count() . " projects, " . \App\Models\Client::count() . " clients, " . \App\Models\TimeEntry::count() . " entries";'
```
Expected: at least 8 projects, 6 clients, 5 entries.

- [ ] **Step 3: Re-run the seeder to verify idempotency**

```
host$ ddev artisan db:seed --class=DemoFixturesSeeder
```
Expected: counts unchanged (firstOrCreate prevents duplicates). Output: no errors.

- [ ] **Step 4: Commit**

```
host$ git add database/seeders/DemoFixturesSeeder.php
host$ git commit -m "feat(seed): DemoFixturesSeeder mirrors the design's data.jsx"
```

---

## Task 12: Phase 1 end-to-end verification

- [ ] **Step 1: Migrate fresh + run all tests**

```
host$ ddev artisan migrate:fresh --seed
host$ ddev artisan test
```
Expected: all migrations create the 10 tables cleanly; test suite all green.

Approximate test count after Phase 1: 60+ (Phase 0: 36; Phase 1 adds ~30 new tests across schema and services).

- [ ] **Step 2: Sanity check via tinker**

```
host$ ddev artisan db:seed --class=DemoFixturesSeeder
host$ ddev artisan tinker --execute='
$svc = app(\App\Services\Timer\TimerService::class);
$user = \App\Models\User::first();
$p = \App\Models\Project::first();
$e = $svc->start($user, $p, null, "smoke test");
echo "started entry " . $e->id . " — running: " . (\App\Models\TimeEntry::running()->count()) . PHP_EOL;
sleep(1);
$svc->stop($user);
echo "stopped — running: " . (\App\Models\TimeEntry::running()->count()) . PHP_EOL;
'
```
Expected:
```
started entry N — running: 1
stopped — running: 0
```

- [ ] **Step 3: Tag the phase**

```
host$ git tag -a phase-1 -m "Phase 1 (Schema + Domain) complete"
host$ git log --oneline phase-0..phase-1
```
Expected: the full sequence of Phase 1 commits.

No commit for this verification task — the tag is the closing artifact.

---

## What's next (not in this plan)

Phase 2 (HTTP + Vue views) and Phase 3 (production package) will be written as their own plans after Phase 1 lands. Sketch:

- `docs/superpowers/plans/<date>-ernte-phase-2-views.md` — wires the seven Inertia pages to controllers, builds Vue components for the Projects/Timer/Clients/Invoices flows, ships the PDF template + `sprain/swiss-qr-bill` + Browsershot + email send/reminders.
- `docs/superpowers/plans/<date>-ernte-phase-3-deploy.md` — production `docker-compose.yml`, `bin/install` polish for production target, backup command + scheduler wiring.
