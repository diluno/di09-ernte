# iOS Companion — Slice 2a‑ii: Billing Read API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add read-only JSON endpoints for billing — `GET /api/invoices` + `GET /api/invoices/{number}` and `GET /api/estimates` + `GET /api/estimates/{number}` — so the iOS app can show invoice/estimate status, amounts, and detail.

**Architecture:** Index endpoints reuse the existing paginated projections (`InvoiceProjections::index/stats`, `EstimateProjections::index/stats`). For detail, we extract the invoice/estimate detail array that the web `show` controllers already build inline into `InvoiceProjections::detail()` / `EstimateProjections::detail()`, then have **both** the web controllers and the new API controllers use it (DRY — no drift between web and API). Existing web `show` tests guard the shape.

**Tech Stack:** Laravel 12, Sanctum 4, Pest 4. All commands via DDEV (`ddev artisan …`).

**Conventions:** Pest + `RefreshDatabase`. Factories: `Invoice::factory()` / `Estimate::factory()` auto-create a `Client`; `InvoiceLine::factory()` / `EstimateLine::factory()` exist; `Invoice::factory()->sent()`/`->paid()`. Authenticate API tests with `Laravel\Sanctum\Sanctum::actingAs($user)`. New routes go inside the existing `auth:sanctum` group in `routes/api.php`; controllers under `App\Http\Controllers\Api`, imported at the top.

**JSON shape rationale:** Reuse projection arrays (consistent with Slices 1a and 2a‑i) rather than new `JsonResource` classes. Index endpoints return the paginator object (Laravel serializes it to `{current_page, data, last_page, total, …}`) plus a `stats` block.

---

## Task 1: Extract `InvoiceProjections::detail()` and reuse it in the web controller

**Files:**
- Modify: `app/Support/InvoiceProjections.php`
- Modify: `app/Http/Controllers/InvoiceController.php`

This is a refactor with no behavior change; the existing `tests/Feature/Http/InvoiceControllerTest.php` show test guards the shape.

- [ ] **Step 1: Add the `detail()` method to `InvoiceProjections`**

Add `use App\Models\InvoiceLine;` to the imports (if not present), then add this method to the class:

```php
    /** Full single-invoice detail array (shared by the web show page and the API). */
    public static function detail(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'project', 'recurringInvoice:id,title', 'lines']);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'overdue' => $invoice->overdue,
            'title' => $invoice->title,
            'client' => $invoice->client->only('id', 'name'),
            'project_name' => $invoice->project?->name,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'subtotal' => round($invoice->subtotal_rappen / 100, 2),
            'vat' => round($invoice->vat_rappen / 100, 2),
            'total' => round($invoice->total_rappen / 100, 2),
            'vat_rate' => (float) $invoice->vat_rate,
            'notes' => $invoice->notes,
            'recurring' => $invoice->recurringInvoice
                ? ['id' => $invoice->recurringInvoice->id, 'title' => $invoice->recurringInvoice->title]
                : null,
            'lines' => $invoice->lines->sortBy('sort_order')->values()->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'description' => $l->description,
                'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                'amount' => round($l->amount_rappen / 100, 2),
            ])->all(),
        ];
    }
```

- [ ] **Step 2: Replace the inline `invoice` array in `InvoiceController@show`**

In `app/Http/Controllers/InvoiceController.php`, the `show` method currently builds `'invoice' => [ … big inline array … ]`. Replace ONLY that `'invoice' => [...]` value with:

```php
            'invoice' => \App\Support\InvoiceProjections::detail($invoice),
```

Leave the surrounding `->load([...])`, `$linked`, `'events'`, `'linked_entries'`, `'preview_url'`, and `'pdf_url'` exactly as they are. (The controller pre-loads relations; `detail()`'s `loadMissing` is then a no-op there, and self-sufficient when called from the API.)

- [ ] **Step 3: Verify the web invoice tests still pass (shape unchanged)**

Run: `ddev artisan test tests/Feature/Http/InvoiceControllerTest.php`
Expected: PASS — the show test asserts the same `invoice` shape; no behavior change.

- [ ] **Step 4: Commit**

```bash
git add app/Support/InvoiceProjections.php app/Http/Controllers/InvoiceController.php
git commit -m "refactor(invoices): extract InvoiceProjections::detail() shared by web + api"
```

---

## Task 2: `Api\InvoiceController` — index + detail

**Files:**
- Create: `app/Http/Controllers/Api/InvoiceController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/InvoiceApiTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Api/InvoiceApiTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/invoices requires authentication', function () {
    $this->getJson('/api/invoices')->assertUnauthorized();
});

test('GET /api/invoices returns a paginated list and stats', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $inv = Invoice::factory()->sent()->create(['client_id' => $client->id, 'number' => '2026-001']);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 5.5]);

    $this->getJson('/api/invoices')
        ->assertOk()
        ->assertJsonStructure([
            'invoices' => [
                'current_page', 'last_page', 'total',
                'data' => [['id', 'number', 'title', 'status', 'overdue', 'total', 'hours', 'client' => ['id', 'name']]],
            ],
            'stats' => ['outstanding', 'overdue', 'paid_ytd', 'count'],
        ])
        ->assertJsonPath('invoices.data.0.number', '2026-001')
        ->assertJsonPath('invoices.total', 1);
});

test('GET /api/invoices?filter=overdue narrows to overdue sent invoices', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create();
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->subDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);

    $this->getJson('/api/invoices?filter=overdue')
        ->assertOk()
        ->assertJsonPath('invoices.total', 1);
});

test('GET /api/invoices/{number} returns the detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $inv = Invoice::factory()->sent()->create([
        'client_id' => $client->id, 'number' => '2026-007', 'title' => 'March work',
        'subtotal_rappen' => 100000, 'vat_rappen' => 8100, 'total_rappen' => 108100,
    ]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'description' => 'Dev', 'hours' => 5, 'rate_rappen' => 14500, 'amount_rappen' => 72500]);

    $this->getJson('/api/invoices/2026-007')
        ->assertOk()
        ->assertJsonStructure([
            'id', 'number', 'status', 'overdue', 'title', 'client' => ['id', 'name'],
            'issued_on', 'due_on', 'subtotal', 'vat', 'total', 'vat_rate', 'notes',
            'lines' => [['id', 'description', 'hours', 'rate', 'amount']],
        ])
        ->assertJsonPath('number', '2026-007')
        ->assertJsonPath('total', 1081.0)
        ->assertJsonPath('lines.0.description', 'Dev');
});

test('GET /api/invoices/{number} returns 404 for an unknown number', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/invoices/9999-999')->assertNotFound();
});

test('GET /api/invoices/{number} requires authentication', function () {
    $inv = Invoice::factory()->create(['number' => '2026-009']);
    $this->getJson("/api/invoices/{$inv->number}")->assertUnauthorized();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev artisan test tests/Feature/Http/Api/InvoiceApiTest.php`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\InvoiceProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'invoices' => InvoiceProjections::index($filter, $search),
            'stats'    => InvoiceProjections::stats(),
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(InvoiceProjections::detail($invoice));
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, add `use App\Http\Controllers\Api\InvoiceController;` at the top, and inside the `auth:sanctum` group:

```php
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice:number}', [InvoiceController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `ddev artisan test tests/Feature/Http/Api/InvoiceApiTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/InvoiceController.php routes/api.php tests/Feature/Http/Api/InvoiceApiTest.php
git commit -m "feat(api): invoices list + detail endpoints"
```

---

## Task 3: Extract `EstimateProjections::detail()` and reuse it in the web controller

**Files:**
- Modify: `app/Support/EstimateProjections.php`
- Modify: `app/Http/Controllers/EstimateController.php`

- [ ] **Step 1: Add the `detail()` method to `EstimateProjections`**

Add `use App\Models\EstimateLine;` to the imports (if not present), then add:

```php
    /** Full single-estimate detail array (shared by the web show page and the API). */
    public static function detail(Estimate $estimate): array
    {
        $estimate->loadMissing(['client', 'project', 'convertedInvoice:id,number', 'lines']);

        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'status' => $estimate->status,
            'expired' => $estimate->expired,
            'title' => $estimate->title,
            'client' => $estimate->client->only('id', 'name'),
            'project_name' => $estimate->project?->name,
            'issued_on' => $estimate->issued_on?->toDateString(),
            'valid_until' => $estimate->valid_until?->toDateString(),
            'subtotal' => round($estimate->subtotal_rappen / 100, 2),
            'vat' => round($estimate->vat_rappen / 100, 2),
            'total' => round($estimate->total_rappen / 100, 2),
            'vat_rate' => (float) $estimate->vat_rate,
            'notes' => $estimate->notes,
            'lines' => $estimate->lines->sortBy('sort_order')->values()->map(fn (EstimateLine $l) => [
                'id' => $l->id, 'description' => $l->description,
                'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                'amount' => round($l->amount_rappen / 100, 2),
            ])->all(),
            'converted_invoice' => $estimate->convertedInvoice
                ? ['id' => $estimate->convertedInvoice->id, 'number' => $estimate->convertedInvoice->number]
                : null,
        ];
    }
```

- [ ] **Step 2: Replace the inline `estimate` array in `EstimateController@show`**

In `app/Http/Controllers/EstimateController.php`, replace ONLY the `'estimate' => [ … inline array … ]` value with:

```php
            'estimate' => \App\Support\EstimateProjections::detail($estimate),
```

Leave the `->load([...])`, `'events'`, `'preview_url'`, and `'pdf_url'` keys as they are.

- [ ] **Step 3: Verify the web estimate tests still pass**

Run: `ddev artisan test tests/Feature/Http/EstimateControllerTest.php`
Expected: PASS — same shape, no behavior change.

- [ ] **Step 4: Commit**

```bash
git add app/Support/EstimateProjections.php app/Http/Controllers/EstimateController.php
git commit -m "refactor(estimates): extract EstimateProjections::detail() shared by web + api"
```

---

## Task 4: `Api\EstimateController` — index + detail

**Files:**
- Create: `app/Http/Controllers/Api/EstimateController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Api/EstimateApiTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Api/EstimateApiTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('GET /api/estimates requires authentication', function () {
    $this->getJson('/api/estimates')->assertUnauthorized();
});

test('GET /api/estimates returns a paginated list and stats', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $est = Estimate::factory()->create(['client_id' => $client->id, 'number' => 'OF-2026-001', 'status' => 'sent']);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'hours' => 3.0]);

    $this->getJson('/api/estimates')
        ->assertOk()
        ->assertJsonStructure([
            'estimates' => [
                'current_page', 'last_page', 'total',
                'data' => [['id', 'number', 'title', 'status', 'expired', 'total', 'hours', 'client' => ['id', 'name']]],
            ],
            'stats',
        ])
        ->assertJsonPath('estimates.data.0.number', 'OF-2026-001')
        ->assertJsonPath('estimates.total', 1);
});

test('GET /api/estimates?filter=accepted narrows to accepted estimates', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create();
    Estimate::factory()->create(['client_id' => $client->id, 'status' => 'accepted']);
    Estimate::factory()->create(['client_id' => $client->id, 'status' => 'sent']);

    $this->getJson('/api/estimates?filter=accepted')
        ->assertOk()
        ->assertJsonPath('estimates.total', 1);
});

test('GET /api/estimates/{number} returns the detail payload', function () {
    Sanctum::actingAs(User::factory()->create());
    $client = Client::factory()->create(['name' => 'Atlas']);
    $est = Estimate::factory()->create([
        'client_id' => $client->id, 'number' => 'OF-2026-007', 'title' => 'Proposal', 'status' => 'sent',
        'subtotal_rappen' => 50000, 'vat_rappen' => 4050, 'total_rappen' => 54050,
    ]);
    EstimateLine::factory()->create(['estimate_id' => $est->id, 'description' => 'Scope', 'hours' => 4, 'rate_rappen' => 12500, 'amount_rappen' => 50000]);

    $this->getJson('/api/estimates/OF-2026-007')
        ->assertOk()
        ->assertJsonStructure([
            'id', 'number', 'status', 'expired', 'title', 'client' => ['id', 'name'],
            'issued_on', 'valid_until', 'subtotal', 'vat', 'total', 'vat_rate', 'notes',
            'lines' => [['id', 'description', 'hours', 'rate', 'amount']],
            'converted_invoice',
        ])
        ->assertJsonPath('number', 'OF-2026-007')
        ->assertJsonPath('total', 540.5)
        ->assertJsonPath('lines.0.description', 'Scope');
});

test('GET /api/estimates/{number} returns 404 for an unknown number', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/estimates/OF-9999-999')->assertNotFound();
});

test('GET /api/estimates/{number} requires authentication', function () {
    $est = Estimate::factory()->create(['number' => 'OF-2026-009']);
    $this->getJson("/api/estimates/{$est->number}")->assertUnauthorized();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev artisan test tests/Feature/Http/Api/EstimateApiTest.php`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/EstimateController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Support\EstimateProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'estimates' => EstimateProjections::index($filter, $search),
            'stats'     => EstimateProjections::stats(),
        ]);
    }

    public function show(Estimate $estimate): JsonResponse
    {
        return response()->json(EstimateProjections::detail($estimate));
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, add `use App\Http\Controllers\Api\EstimateController;` at the top, and inside the `auth:sanctum` group:

```php
    Route::get('/estimates', [EstimateController::class, 'index']);
    Route::get('/estimates/{estimate:number}', [EstimateController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `ddev artisan test tests/Feature/Http/Api/EstimateApiTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/EstimateController.php routes/api.php tests/Feature/Http/Api/EstimateApiTest.php
git commit -m "feat(api): estimates list + detail endpoints"
```

---

## Task 5: Regression + route review

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `ddev artisan test`
Expected: PASS — new billing API tests PLUS all pre-existing tests (especially the web invoice/estimate show tests, which prove the `detail()` extraction kept the shape).

- [ ] **Step 2: Route review**

Run: `ddev artisan route:list --path=api`
Expected to now include:
- `GET api/invoices`, `GET api/invoices/{invoice}`
- `GET api/estimates`, `GET api/estimates/{estimate}`
(plus the previously-added auth/me/timer/projects routes)

---

## Self-Review Notes

- **Spec coverage:** Completes Slice 2's backend — invoices and estimates list + detail. With 2a‑i (projects), all read endpoints for the status/Billing tabs now exist.
- **DRY refactor:** `detail()` is extracted and reused by both web and API; the web `show` tests guard against shape drift. The web controllers keep their extra keys (events, linked_entries, urls) — only the core invoice/estimate array is shared.
- **Deviation from spec — Resources:** Reuses projection arrays instead of `JsonResource` classes (consistent with Slices 1a/2a‑i). Index endpoints return the paginator (serialized to `{current_page, data, …}`).
- **Type consistency:** All API controller methods return `JsonResponse`; detail routes bind by `number` (`{invoice:number}` / `{estimate:number}`), matching the web routes. `detail()` uses `loadMissing` + `sortBy('sort_order')` so it is correct whether or not relations were pre-loaded.
