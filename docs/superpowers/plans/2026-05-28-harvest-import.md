# Harvest Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A one-time `php artisan harvest:import` command that pulls clients, projects, invoices and estimates from the Harvest API v2 into ernte, preserving Harvest's document numbers, using a wipe-then-import model.

**Architecture:** A thin `HarvestApi` HTTP client (auth + pagination) feeds four focused importer classes (`ClientImporter`, `ProjectImporter`, `InvoiceImporter`, `EstimateImporter`), coordinated by an `ImportRunner` that owns the fetch-then-transaction(wipe→insert→counter-bump) flow. The artisan command handles credentials, the destructive confirmation, dry-run, and summary output. All under `app/Services/Harvest/`, mirroring the codebase's `app/Services/Invoicing|Estimating/` grain and existing console commands.

**Tech Stack:** Laravel 12, Laravel `Http` client, MariaDB (DDEV), Pest with `Http::fake()`.

**Reference (read before starting):** the spec at `docs/superpowers/specs/2026-05-28-harvest-import-design.md`.

**Conventions:**
- Money stored as integer **rappen** (`round(value * 100)`); Harvest sends decimals.
- Console commands: `extends Illuminate\Console\Command`, `handle(): int`, return `self::SUCCESS`/`self::FAILURE`, use `$this->info()/error()`.
- Tests are Pest, `tests/Feature/**`, `RefreshDatabase` applied. **No live API calls — always `Http::fake()`.**
- Run a test: `ddev artisan test --filter='<name>'`.

**Harvest API v2 facts the code relies on:**
- Base `https://api.harvestapp.com/v2`. Required headers: `Authorization: Bearer <token>`, `Harvest-Account-Id: <id>`, `User-Agent`.
- List responses wrap the rows under a key (e.g. `{"clients":[...], "next_page": 2|null, ...}`); follow `next_page`.
- Invoice `state`: `draft|open|paid|closed`. Estimate `state`: `draft|sent|accepted|declined`.
- Amounts: invoice/estimate `amount` is the total (incl. tax); `tax`/`tax2` are percentages, `tax_amount`/`tax2_amount` are money; line items carry `quantity`, `unit_price`, `amount`, `taxed`.

---

## Task 1: Config + HarvestApi client + exception

**Files:**
- Modify: `config/services.php` (add a `harvest` block)
- Create: `app/Services/Harvest/HarvestApiException.php`
- Create: `app/Services/Harvest/HarvestApi.php`
- Test: `tests/Feature/Harvest/HarvestApiTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/HarvestApiTest.php`:

```php
<?php

use App\Services\Harvest\HarvestApi;
use App\Services\Harvest\HarvestApiException;
use Illuminate\Support\Facades\Http;

function api(): HarvestApi
{
    return new HarvestApi('tok-123', 'acct-456', 'https://api.harvestapp.com/v2', 'ernte-test');
}

test('sends the required auth headers', function () {
    Http::fake(['*/clients*' => Http::response(['clients' => [], 'next_page' => null])]);

    api()->clients();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request->hasHeader('Harvest-Account-Id', 'acct-456')
            && $request->hasHeader('User-Agent', 'ernte-test');
    });
});

test('follows pagination via next_page and concatenates rows', function () {
    Http::fake(['*/projects*' => Http::sequence()
        ->push(['projects' => [['id' => 1], ['id' => 2]], 'next_page' => 2])
        ->push(['projects' => [['id' => 3]], 'next_page' => null])]);

    $rows = api()->projects();

    expect($rows)->toHaveCount(3);
    expect($rows->pluck('id')->all())->toBe([1, 2, 3]);
});

test('throws HarvestApiException on a non-2xx response', function () {
    Http::fake(['*/invoices*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(fn () => api()->invoices())->toThrow(HarvestApiException::class);
});

test('retries once on 429 then succeeds', function () {
    Http::fake(['*/estimates*' => Http::sequence()
        ->push(['error' => 'rate limited'], 429, ['Retry-After' => '0'])
        ->push(['estimates' => [['id' => 9]], 'next_page' => null], 200)]);

    $rows = api()->estimates();

    expect($rows)->toHaveCount(1);
    expect($rows->first()['id'])->toBe(9);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='HarvestApiTest'`
Expected: FAIL — `Class "App\Services\Harvest\HarvestApi" not found`.

- [ ] **Step 3: Create the exception**

`app/Services/Harvest/HarvestApiException.php`:

```php
<?php

namespace App\Services\Harvest;

class HarvestApiException extends \RuntimeException
{
}
```

- [ ] **Step 4: Create the HarvestApi client**

`app/Services/Harvest/HarvestApi.php`:

```php
<?php

namespace App\Services\Harvest;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class HarvestApi
{
    public function __construct(
        private string $token,
        private string $accountId,
        private string $baseUrl = 'https://api.harvestapp.com/v2',
        private string $userAgent = 'ernte-import (https://github.com/diluno/di09-ernte)',
    ) {}

    public function clients(): Collection   { return $this->paginate('clients', 'clients'); }
    public function contacts(): Collection  { return $this->paginate('contacts', 'contacts'); }
    public function projects(): Collection  { return $this->paginate('projects', 'projects'); }
    public function invoices(): Collection   { return $this->paginate('invoices', 'invoices'); }
    public function estimates(): Collection  { return $this->paginate('estimates', 'estimates'); }

    /** Fetch every page of a list endpoint, concatenating the rows under $key. */
    private function paginate(string $path, string $key): Collection
    {
        $rows = collect();
        $page = 1;

        do {
            $body = $this->get($path, ['page' => $page, 'per_page' => 100]);
            $rows = $rows->concat($body[$key] ?? []);
            $page = $body['next_page'] ?? null;
        } while ($page !== null);

        return $rows;
    }

    /** Single GET with one retry on HTTP 429 (honouring Retry-After). */
    private function get(string $path, array $query): array
    {
        $response = $this->http()->get($path, $query);

        if ($response->status() === 429) {
            sleep((int) ($response->header('Retry-After') ?: 1));
            $response = $this->http()->get($path, $query);
        }

        if (! $response->successful()) {
            throw new HarvestApiException("Harvest API GET /{$path} failed: HTTP {$response->status()} {$response->body()}");
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'Harvest-Account-Id' => $this->accountId,
                'User-Agent' => $this->userAgent,
            ]);
    }
}
```

- [ ] **Step 5: Add the config block**

In `config/services.php`, add this entry inside the returned array (e.g. after the `browsershot` block):

```php
    'harvest' => [
        'access_token' => env('HARVEST_ACCESS_TOKEN'),
        'account_id' => env('HARVEST_ACCOUNT_ID'),
        'base_url' => env('HARVEST_BASE_URL', 'https://api.harvestapp.com/v2'),
        'user_agent' => env('HARVEST_USER_AGENT', 'ernte-import (https://github.com/diluno/di09-ernte)'),
    ],
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `ddev artisan test --filter='HarvestApiTest'`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Harvest/HarvestApi.php app/Services/Harvest/HarvestApiException.php config/services.php tests/Feature/Harvest/HarvestApiTest.php
git commit -m "feat(harvest): add Harvest API v2 client (auth, pagination, 429 retry)"
```

---

## Task 2: ClientImporter

**Files:**
- Create: `app/Services/Harvest/ClientImporter.php`
- Test: `tests/Feature/Harvest/ClientImporterTest.php`

The importer maps Harvest client rows (plus the contacts list) to ernte `Client`
rows and returns a map of `harvestClientId => Client`. `short_code` is generated
(≤4 uppercased alphanumerics) and de-duplicated within the run.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/ClientImporterTest.php`:

```php
<?php

use App\Models\Client;
use App\Services\Harvest\ClientImporter;

test('maps fields, generates a short code, links the primary contact', function () {
    $clients = [
        ['id' => 10, 'name' => 'Atlas Robotics', 'is_active' => true, 'address' => "Bahnhofstrasse 1\n8001 Zürich"],
    ];
    $contacts = [
        ['id' => 1, 'client' => ['id' => 10], 'first_name' => 'Mira', 'last_name' => 'Okafor', 'email' => 'mira@atlas.test'],
    ];

    $map = (new ClientImporter())->import($clients, $contacts);

    expect($map)->toHaveKey(10);
    $c = $map[10];
    expect($c)->toBeInstanceOf(Client::class);
    expect($c->name)->toBe('Atlas Robotics');
    expect($c->short_code)->toBe('ATLA');
    expect($c->address_line_1)->toBe("Bahnhofstrasse 1\n8001 Zürich");
    expect($c->country)->toBe('CH');
    expect($c->contact_name)->toBe('Mira Okafor');
    expect($c->email)->toBe('mira@atlas.test');
    expect($c->archived_at)->toBeNull();
});

test('de-duplicates short codes across clients with similar names', function () {
    $clients = [
        ['id' => 1, 'name' => 'Atlas Robotics', 'is_active' => true],
        ['id' => 2, 'name' => 'Atlas Logistics', 'is_active' => true],
    ];

    $map = (new ClientImporter())->import($clients, []);

    expect($map[1]->short_code)->toBe('ATLA');
    expect($map[2]->short_code)->not->toBe('ATLA');
    expect(strlen($map[2]->short_code))->toBeLessThanOrEqual(4);
});

test('inactive clients are archived', function () {
    $map = (new ClientImporter())->import([['id' => 5, 'name' => 'Old Co', 'is_active' => false]], []);

    expect($map[5]->archived_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='ClientImporterTest'`
Expected: FAIL — `Class "App\Services\Harvest\ClientImporter" not found`.

- [ ] **Step 3: Create the importer**

`app/Services/Harvest/ClientImporter.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Client;

class ClientImporter
{
    /** @var array<int,bool> short codes already used in this run */
    private array $usedCodes = [];

    /**
     * @param  array<int,array>  $harvestClients
     * @param  array<int,array>  $harvestContacts
     * @return array<int,Client>  map of harvest client id => ernte Client
     */
    public function import(array $harvestClients, array $harvestContacts): array
    {
        $contactByClient = $this->firstContactPerClient($harvestContacts);
        $map = [];

        foreach ($harvestClients as $row) {
            $contact = $contactByClient[$row['id']] ?? null;

            $map[$row['id']] = Client::create([
                'name' => $row['name'],
                'short_code' => $this->uniqueShortCode($row['name']),
                'address_line_1' => $row['address'] ?? null,
                'country' => 'CH',
                'contact_name' => $contact
                    ? trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: null
                    : null,
                'email' => $contact['email'] ?? null,
                'archived_at' => ($row['is_active'] ?? true) ? null : now(),
            ]);
        }

        return $map;
    }

    /** @return array<int,array> first contact keyed by client id */
    private function firstContactPerClient(array $contacts): array
    {
        $byClient = [];
        foreach ($contacts as $contact) {
            $cid = $contact['client']['id'] ?? null;
            if ($cid !== null && ! isset($byClient[$cid])) {
                $byClient[$cid] = $contact;
            }
        }
        return $byClient;
    }

    private function uniqueShortCode(string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'CL', 0, 4));
        $base = $base === '' ? 'CL' : $base;

        if (! isset($this->usedCodes[$base])) {
            return $this->usedCodes[$base] = $base;
        }

        // Collision: replace the last char with digits 2..99, keeping length <= 4.
        $stem = substr($base, 0, 3);
        for ($n = 2; $n <= 99; $n++) {
            $candidate = substr($stem, 0, 4 - strlen((string) $n)) . $n;
            if (! isset($this->usedCodes[$candidate])) {
                return $this->usedCodes[$candidate] = $candidate;
            }
        }

        return $this->usedCodes[$base . uniqid()] = substr($base, 0, 2) . random_int(10, 99);
    }
}
```

> Note: `$this->usedCodes` keys store the codes themselves (value `true` semantics via `isset`); the explicit `= $candidate` returns the code while marking it used.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='ClientImporterTest'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Harvest/ClientImporter.php tests/Feature/Harvest/ClientImporterTest.php
git commit -m "feat(harvest): add ClientImporter (field mapping, short-code dedup, contacts)"
```

---

## Task 3: ProjectImporter

**Files:**
- Create: `app/Services/Harvest/ProjectImporter.php`
- Test: `tests/Feature/Harvest/ProjectImporterTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/ProjectImporterTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Project;
use App\Services\Harvest\ProjectImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client]; // harvest client id 77 => ernte client
});

test('maps fields, links the client, converts rate to rappen', function () {
    $projects = [[
        'id' => 1, 'client' => ['id' => 77], 'name' => 'Online Store', 'code' => 'OS1',
        'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0,
        'budget' => 200.0, 'budget_by' => 'project', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30',
    ]];

    $map = (new ProjectImporter())->import($projects, $this->clientMap);

    $p = $map[1];
    expect($p)->toBeInstanceOf(Project::class);
    expect($p->client_id)->toBe($this->client->id);
    expect($p->name)->toBe('Online Store');
    expect($p->code)->toBe('OS1');
    expect($p->status)->toBe('active');
    expect($p->billable)->toBeTrue();
    expect($p->rate_rappen)->toBe(14500);
    expect($p->budget_hours)->toBe(200);
    expect($p->budget_amount_rappen)->toBe(0);
    expect($p->started_on->toDateString())->toBe('2026-01-01');
});

test('amount-based budget maps to budget_amount_rappen', function () {
    $projects = [[
        'id' => 2, 'client' => ['id' => 77], 'name' => 'Retainer', 'code' => '',
        'is_active' => false, 'is_billable' => false, 'hourly_rate' => null,
        'budget' => 5000.0, 'budget_by' => 'project_cost',
    ]];

    $p = (new ProjectImporter())->import($projects, $this->clientMap)[2];

    expect($p->status)->toBe('archived');
    expect($p->rate_rappen)->toBe(0);
    expect($p->budget_amount_rappen)->toBe(500000);
    expect($p->budget_hours)->toBe(0);
    expect($p->code)->not->toBe(''); // generated from name when blank
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='ProjectImporterTest'`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the importer**

`app/Services/Harvest/ProjectImporter.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Project;

class ProjectImporter
{
    private const AMOUNT_BUDGETS = ['project_cost', 'task_fees'];

    /** @var array<string,bool> */
    private array $usedCodes = [];

    /**
     * @param  array<int,array>   $harvestProjects
     * @param  array<int,Client>  $clientMap  harvest client id => ernte Client
     * @return array<int,Project> harvest project id => ernte Project
     */
    public function import(array $harvestProjects, array $clientMap): array
    {
        $map = [];

        foreach ($harvestProjects as $row) {
            $client = $clientMap[$row['client']['id'] ?? null] ?? null;
            if (! $client) {
                continue; // orphan project with no imported client — skip
            }

            $budgetBy = $row['budget_by'] ?? 'none';
            $budget = (float) ($row['budget'] ?? 0);
            $isAmount = in_array($budgetBy, self::AMOUNT_BUDGETS, true);

            $map[$row['id']] = Project::create([
                'client_id' => $client->id,
                'name' => $row['name'],
                'code' => $this->uniqueCode($row['code'] ?? '', $row['name']),
                'status' => ($row['is_active'] ?? true) ? 'active' : 'archived',
                'billable' => (bool) ($row['is_billable'] ?? false),
                'rate_rappen' => (int) round(((float) ($row['hourly_rate'] ?? 0)) * 100),
                'budget_hours' => $isAmount ? 0 : (int) round($budget),
                'budget_amount_rappen' => $isAmount ? (int) round($budget * 100) : 0,
                'started_on' => $row['starts_on'] ?? null,
                'deadline_on' => $row['ends_on'] ?? null,
                'glyph' => '▦',
            ]);
        }

        return $map;
    }

    private function uniqueCode(string $code, string $name): string
    {
        $base = $code !== ''
            ? $code
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'PRJ', 0, 6));

        $candidate = $base;
        $n = 2;
        while (isset($this->usedCodes[$candidate])) {
            $candidate = $base . '-' . $n++;
        }

        $this->usedCodes[$candidate] = true;
        return $candidate;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='ProjectImporterTest'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Harvest/ProjectImporter.php tests/Feature/Harvest/ProjectImporterTest.php
git commit -m "feat(harvest): add ProjectImporter (client link, rate/budget mapping)"
```

---

## Task 4: InvoiceImporter

**Files:**
- Create: `app/Services/Harvest/InvoiceImporter.php`
- Test: `tests/Feature/Harvest/InvoiceImporterTest.php`

Maps Harvest invoices (+ line items) to ernte `Invoice` + `InvoiceLine` rows.
Preserves the Harvest number, maps state, copies totals verbatim
(`subtotal = total − vat`), writes a `created` event, and returns
`['imported' => int, 'warnings' => string[]]` (warnings for non-CHF currency).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/InvoiceImporterTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Harvest\InvoiceImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client];
});

function harvestInvoice(array $overrides = []): array
{
    return array_merge([
        'id' => 1001, 'client' => ['id' => 77], 'number' => '2025-014',
        'state' => 'open', 'currency' => 'CHF',
        'issue_date' => '2025-03-01', 'due_date' => '2025-03-31',
        'sent_at' => '2025-03-01T09:00:00Z', 'paid_at' => null,
        'tax' => 8.1, 'tax_amount' => 8.1, 'tax2' => null, 'tax2_amount' => null,
        'amount' => 108.1, 'notes' => 'Thanks',
        'line_items' => [
            ['id' => 1, 'description' => 'Consulting', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'taxed' => true],
        ],
    ], $overrides);
}

test('preserves number, maps open->sent, copies totals, writes lines + created event', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice()], $this->clientMap);

    expect($result['imported'])->toBe(1);
    $inv = Invoice::first();
    expect($inv->number)->toBe('2025-014');
    expect($inv->client_id)->toBe($this->client->id);
    expect($inv->project_id)->toBeNull();
    expect($inv->status)->toBe('sent');
    expect($inv->issued_on->toDateString())->toBe('2025-03-01');
    expect($inv->due_on->toDateString())->toBe('2025-03-31');
    expect((float) $inv->vat_rate)->toBe(8.1);
    expect($inv->total_rappen)->toBe(10810);
    expect($inv->vat_rappen)->toBe(810);
    expect($inv->subtotal_rappen)->toBe(10000); // total - vat
    expect($inv->lines)->toHaveCount(1);
    expect($inv->lines->first()->rate_rappen)->toBe(10000);
    expect($inv->lines->first()->vat_exempt)->toBeFalse();
    expect($inv->events()->where('kind', 'created')->count())->toBe(1);
});

test('maps every Harvest state to an ernte status', function () {
    $importer = new InvoiceImporter();
    $importer->import([
        harvestInvoice(['id' => 1, 'number' => 'D1', 'state' => 'draft']),
        harvestInvoice(['id' => 2, 'number' => 'O1', 'state' => 'open']),
        harvestInvoice(['id' => 3, 'number' => 'P1', 'state' => 'paid']),
        harvestInvoice(['id' => 4, 'number' => 'C1', 'state' => 'closed']),
    ], $this->clientMap);

    expect(Invoice::where('number', 'D1')->value('status'))->toBe('draft');
    expect(Invoice::where('number', 'O1')->value('status'))->toBe('sent');
    expect(Invoice::where('number', 'P1')->value('status'))->toBe('paid');
    expect(Invoice::where('number', 'C1')->value('status'))->toBe('void');
});

test('untaxed line items become vat_exempt', function () {
    $inv = harvestInvoice(['line_items' => [
        ['id' => 1, 'description' => 'Reimbursement', 'quantity' => 1.0, 'unit_price' => 50.0, 'amount' => 50.0, 'taxed' => false],
    ]]);

    (new InvoiceImporter())->import([$inv], $this->clientMap);

    expect(Invoice::first()->lines->first()->vat_exempt)->toBeTrue();
});

test('non-CHF invoices are imported with a warning', function () {
    $result = (new InvoiceImporter())->import([harvestInvoice(['currency' => 'USD'])], $this->clientMap);

    expect($result['imported'])->toBe(1);
    expect($result['warnings'])->not->toBeEmpty();
    expect(Invoice::first()->currency)->toBe('USD');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='InvoiceImporterTest'`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the importer**

`app/Services/Harvest/InvoiceImporter.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

class InvoiceImporter
{
    private const STATUS = [
        'draft' => 'draft',
        'open' => 'sent',
        'paid' => 'paid',
        'closed' => 'void',
    ];

    /**
     * @param  array<int,array>   $harvestInvoices
     * @param  array<int,Client>  $clientMap
     * @return array{imported:int, warnings:string[]}
     */
    public function import(array $harvestInvoices, array $clientMap): array
    {
        $imported = 0;
        $warnings = [];

        foreach ($harvestInvoices as $row) {
            $client = $clientMap[$row['client']['id'] ?? null] ?? null;
            if (! $client) {
                $warnings[] = "Skipped invoice {$row['number']}: client not imported.";
                continue;
            }

            $currency = $row['currency'] ?? 'CHF';
            if ($currency !== 'CHF') {
                $warnings[] = "Invoice {$row['number']} is {$currency}; imported as-is (amounts treated as CHF).";
            }

            $total = (int) round(((float) ($row['amount'] ?? 0)) * 100);
            $vat = (int) round((((float) ($row['tax_amount'] ?? 0)) + ((float) ($row['tax2_amount'] ?? 0))) * 100);

            $invoice = Invoice::create([
                'number' => $row['number'],
                'client_id' => $client->id,
                'project_id' => null,
                'status' => self::STATUS[$row['state'] ?? 'draft'] ?? 'draft',
                'issued_on' => $row['issue_date'] ?? null,
                'due_on' => $row['due_date'] ?? null,
                'sent_at' => isset($row['sent_at']) ? Carbon::parse($row['sent_at']) : null,
                'paid_at' => isset($row['paid_at']) ? Carbon::parse($row['paid_at']) : null,
                'currency' => $currency,
                'vat_rate' => (float) ($row['tax'] ?? 0),
                'subtotal_rappen' => $total - $vat,
                'vat_rappen' => $vat,
                'total_rappen' => $total,
                'notes' => $row['notes'] ?? null,
            ]);

            $sort = 0;
            foreach ($row['line_items'] ?? [] as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'] ?? '',
                    'hours' => (float) ($line['quantity'] ?? 0),
                    'rate_rappen' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                    'amount_rappen' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                    'vat_exempt' => ! ($line['taxed'] ?? true),
                    'sort_order' => $sort++,
                ]);
            }

            $invoice->events()->create([
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['source' => 'harvest', 'harvest_id' => $row['id']],
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'warnings' => $warnings];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='InvoiceImporterTest'`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Harvest/InvoiceImporter.php tests/Feature/Harvest/InvoiceImporterTest.php
git commit -m "feat(harvest): add InvoiceImporter (number preserved, verbatim totals, lines)"
```

---

## Task 5: EstimateImporter

**Files:**
- Create: `app/Services/Harvest/EstimateImporter.php`
- Test: `tests/Feature/Harvest/EstimateImporterTest.php`

Same shape as invoices; Harvest estimate `state` maps 1:1, `decided_at` comes from
`accepted_at`/`declined_at`, `valid_until` is null.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/EstimateImporterTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Services\Harvest\EstimateImporter;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientMap = [77 => $this->client];
});

function harvestEstimate(array $overrides = []): array
{
    return array_merge([
        'id' => 5001, 'client' => ['id' => 77], 'number' => 'EST-1001',
        'state' => 'accepted', 'currency' => 'CHF', 'issue_date' => '2025-02-01',
        'sent_at' => '2025-02-01T09:00:00Z', 'accepted_at' => '2025-02-05T10:00:00Z', 'declined_at' => null,
        'tax' => 8.1, 'tax_amount' => 8.1, 'tax2' => null, 'tax2_amount' => null,
        'amount' => 108.1, 'notes' => null,
        'line_items' => [
            ['id' => 1, 'description' => 'Phase 1', 'quantity' => 1.0, 'unit_price' => 100.0, 'amount' => 100.0, 'taxed' => true],
        ],
    ], $overrides);
}

test('preserves number, maps state 1:1, stamps decided_at, copies totals + lines', function () {
    $result = (new EstimateImporter())->import([harvestEstimate()], $this->clientMap);

    expect($result['imported'])->toBe(1);
    $est = Estimate::first();
    expect($est->number)->toBe('EST-1001');
    expect($est->status)->toBe('accepted');
    expect($est->issued_on->toDateString())->toBe('2025-02-01');
    expect($est->valid_until)->toBeNull();
    expect($est->decided_at)->not->toBeNull();
    expect($est->total_rappen)->toBe(10810);
    expect($est->vat_rappen)->toBe(810);
    expect($est->subtotal_rappen)->toBe(10000);
    expect($est->lines)->toHaveCount(1);
    expect($est->events()->where('kind', 'created')->count())->toBe(1);
});

test('declined estimate stamps decided_at from declined_at', function () {
    (new EstimateImporter())->import([harvestEstimate([
        'id' => 2, 'number' => 'EST-2', 'state' => 'declined',
        'accepted_at' => null, 'declined_at' => '2025-02-06T10:00:00Z',
    ])], $this->clientMap);

    $est = Estimate::where('number', 'EST-2')->first();
    expect($est->status)->toBe('declined');
    expect($est->decided_at)->not->toBeNull();
});

test('draft estimate has no decided_at', function () {
    (new EstimateImporter())->import([harvestEstimate([
        'id' => 3, 'number' => 'EST-3', 'state' => 'draft', 'accepted_at' => null, 'declined_at' => null,
    ])], $this->clientMap);

    expect(Estimate::where('number', 'EST-3')->value('decided_at'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='EstimateImporterTest'`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the importer**

`app/Services/Harvest/EstimateImporter.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Support\Carbon;

class EstimateImporter
{
    private const STATUS = [
        'draft' => 'draft',
        'sent' => 'sent',
        'accepted' => 'accepted',
        'declined' => 'declined',
    ];

    /**
     * @param  array<int,array>   $harvestEstimates
     * @param  array<int,Client>  $clientMap
     * @return array{imported:int, warnings:string[]}
     */
    public function import(array $harvestEstimates, array $clientMap): array
    {
        $imported = 0;
        $warnings = [];

        foreach ($harvestEstimates as $row) {
            $client = $clientMap[$row['client']['id'] ?? null] ?? null;
            if (! $client) {
                $warnings[] = "Skipped estimate {$row['number']}: client not imported.";
                continue;
            }

            $currency = $row['currency'] ?? 'CHF';
            if ($currency !== 'CHF') {
                $warnings[] = "Estimate {$row['number']} is {$currency}; imported as-is (amounts treated as CHF).";
            }

            $total = (int) round(((float) ($row['amount'] ?? 0)) * 100);
            $vat = (int) round((((float) ($row['tax_amount'] ?? 0)) + ((float) ($row['tax2_amount'] ?? 0))) * 100);

            $decided = $row['accepted_at'] ?? $row['declined_at'] ?? null;

            $estimate = Estimate::create([
                'number' => $row['number'],
                'client_id' => $client->id,
                'project_id' => null,
                'status' => self::STATUS[$row['state'] ?? 'draft'] ?? 'draft',
                'issued_on' => $row['issue_date'] ?? null,
                'valid_until' => null,
                'sent_at' => isset($row['sent_at']) ? Carbon::parse($row['sent_at']) : null,
                'decided_at' => $decided ? Carbon::parse($decided) : null,
                'currency' => $currency,
                'vat_rate' => (float) ($row['tax'] ?? 0),
                'subtotal_rappen' => $total - $vat,
                'vat_rappen' => $vat,
                'total_rappen' => $total,
                'notes' => $row['notes'] ?? null,
            ]);

            $sort = 0;
            foreach ($row['line_items'] ?? [] as $line) {
                $estimate->lines()->create([
                    'description' => $line['description'] ?? '',
                    'hours' => (float) ($line['quantity'] ?? 0),
                    'rate_rappen' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                    'amount_rappen' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                    'vat_exempt' => ! ($line['taxed'] ?? true),
                    'sort_order' => $sort++,
                ]);
            }

            $estimate->events()->create([
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['source' => 'harvest', 'harvest_id' => $row['id']],
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'warnings' => $warnings];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='EstimateImporterTest'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Harvest/EstimateImporter.php tests/Feature/Harvest/EstimateImporterTest.php
git commit -m "feat(harvest): add EstimateImporter (1:1 status, decided_at, lines)"
```

---

## Task 6: CounterReconciler

**Files:**
- Create: `app/Services/Harvest/CounterReconciler.php`
- Test: `tests/Feature/Harvest/CounterReconcilerTest.php`

After import, bump `invoice_counters` / `estimate_counters` past any imported number
that matches ernte's generated format, so future generated numbers don't collide.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/CounterReconcilerTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\EstimateCounter;
use App\Services\Harvest\CounterReconciler;

beforeEach(function () {
    $this->client = Client::factory()->create();
});

test('bumps the invoice counter to the max matching suffix per year', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2025-007']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2025-003']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-001']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'number' => 'LEGACY-999']); // ignored

    CounterReconciler::reconcileInvoices();

    expect(InvoiceCounter::find(2025)->last_n)->toBe(7);
    expect(InvoiceCounter::find(2026)->last_n)->toBe(1);
});

test('bumps the estimate counter for OF-prefixed numbers only', function () {
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2025-012']);
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'EST-555']); // ignored

    CounterReconciler::reconcileEstimates();

    expect(EstimateCounter::find(2025)->last_n)->toBe(12);
});

test('never lowers an existing counter', function () {
    EstimateCounter::create(['year' => 2025, 'last_n' => 99]);
    Estimate::factory()->create(['client_id' => $this->client->id, 'number' => 'OF-2025-003']);

    CounterReconciler::reconcileEstimates();

    expect(EstimateCounter::find(2025)->last_n)->toBe(99);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='CounterReconcilerTest'`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the reconciler**

`app/Services/Harvest/CounterReconciler.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Estimate;
use App\Models\EstimateCounter;
use App\Models\Invoice;
use App\Models\InvoiceCounter;

class CounterReconciler
{
    public static function reconcileInvoices(): void
    {
        self::reconcile(Invoice::pluck('number'), '/^(\d{4})-(\d+)$/', InvoiceCounter::class);
    }

    public static function reconcileEstimates(): void
    {
        self::reconcile(Estimate::pluck('number'), '/^OF-(\d{4})-(\d+)$/', EstimateCounter::class);
    }

    /**
     * @param  iterable<string>  $numbers
     * @param  class-string<InvoiceCounter|EstimateCounter>  $counterModel
     */
    private static function reconcile(iterable $numbers, string $pattern, string $counterModel): void
    {
        $maxByYear = [];
        foreach ($numbers as $number) {
            if (preg_match($pattern, (string) $number, $m)) {
                $year = (int) $m[1];
                $n = (int) $m[2];
                $maxByYear[$year] = max($maxByYear[$year] ?? 0, $n);
            }
        }

        foreach ($maxByYear as $year => $maxN) {
            $counter = $counterModel::firstOrNew(['year' => $year]);
            $counter->last_n = max((int) ($counter->last_n ?? 0), $maxN);
            $counter->save();
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='CounterReconcilerTest'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Harvest/CounterReconciler.php tests/Feature/Harvest/CounterReconcilerTest.php
git commit -m "feat(harvest): add CounterReconciler (bump number counters past imports)"
```

---

## Task 7: HarvestData + ImportSummary + ImportRunner

**Files:**
- Create: `app/Services/Harvest/HarvestData.php`
- Create: `app/Services/Harvest/ImportSummary.php`
- Create: `app/Services/Harvest/ImportRunner.php`
- Test: `tests/Feature/Harvest/ImportRunnerTest.php`

`ImportRunner::fetch(HarvestApi)` does the network; `ImportRunner::import(HarvestData)`
runs the transaction (wipe → importers → counter bump) and returns an `ImportSummary`.
The wipe order is FK-safe: estimates → invoices → projects → clients, then reset
counters. The time-entry guard is enforced by the command (Task 8), not here.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Harvest/ImportRunnerTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\Project;
use App\Services\Harvest\ClientImporter;
use App\Services\Harvest\EstimateImporter;
use App\Services\Harvest\HarvestData;
use App\Services\Harvest\ImportRunner;
use App\Services\Harvest\InvoiceImporter;
use App\Services\Harvest\ProjectImporter;

function runner(): ImportRunner
{
    return new ImportRunner(new ClientImporter(), new ProjectImporter(), new InvoiceImporter(), new EstimateImporter());
}

function sampleData(): HarvestData
{
    return new HarvestData(
        clients: [['id' => 77, 'name' => 'Atlas Robotics', 'is_active' => true]],
        contacts: [],
        projects: [['id' => 1, 'client' => ['id' => 77], 'name' => 'Web', 'code' => 'WEB', 'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0]],
        invoices: [[
            'id' => 9, 'client' => ['id' => 77], 'number' => '2025-001', 'state' => 'paid', 'currency' => 'CHF',
            'issue_date' => '2025-01-01', 'amount' => 108.1, 'tax' => 8.1, 'tax_amount' => 8.1,
            'line_items' => [['id' => 1, 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'taxed' => true]],
        ]],
        estimates: [],
    );
}

test('import creates rows, returns a summary, and bumps counters', function () {
    $summary = runner()->import(sampleData());

    expect($summary->clients)->toBe(1);
    expect($summary->projects)->toBe(1);
    expect($summary->invoices)->toBe(1);
    expect($summary->estimates)->toBe(0);
    expect(Invoice::first()->number)->toBe('2025-001');
    expect(InvoiceCounter::find(2025)->last_n)->toBe(1);
});

test('import wipes pre-existing clients/projects/invoices/estimates first', function () {
    $old = Client::factory()->create(['name' => 'Old Client']);
    Project::factory()->create(['client_id' => $old->id]);

    runner()->import(sampleData());

    expect(Client::where('name', 'Old Client')->exists())->toBeFalse();
    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeTrue();
    expect(Project::count())->toBe(1);
});

test('a failure inside the transaction rolls back, leaving existing data intact', function () {
    $old = Client::factory()->create(['name' => 'Keep Me']);

    // Estimate with a missing 'number' key triggers a DB error mid-import.
    $bad = new HarvestData(
        clients: [['id' => 77, 'name' => 'Atlas', 'is_active' => true]],
        contacts: [], projects: [], invoices: [],
        estimates: [['id' => 1, 'client' => ['id' => 77], 'state' => 'sent', 'amount' => 0]], // no 'number'
    );

    expect(fn () => runner()->import($bad))->toThrow(\Throwable::class);

    // Rolled back: the wipe was undone, original client still present, no new ones.
    expect(Client::where('name', 'Keep Me')->exists())->toBeTrue();
    expect(Client::where('name', 'Atlas')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='ImportRunnerTest'`
Expected: FAIL — `Class "App\Services\Harvest\ImportRunner" not found`.

- [ ] **Step 3: Create HarvestData**

`app/Services/Harvest/HarvestData.php`:

```php
<?php

namespace App\Services\Harvest;

class HarvestData
{
    public function __construct(
        public array $clients = [],
        public array $contacts = [],
        public array $projects = [],
        public array $invoices = [],
        public array $estimates = [],
    ) {}
}
```

- [ ] **Step 4: Create ImportSummary**

`app/Services/Harvest/ImportSummary.php`:

```php
<?php

namespace App\Services\Harvest;

class ImportSummary
{
    /** @param string[] $warnings */
    public function __construct(
        public int $clients = 0,
        public int $projects = 0,
        public int $invoices = 0,
        public int $estimates = 0,
        public array $warnings = [],
    ) {}
}
```

- [ ] **Step 5: Create ImportRunner**

`app/Services/Harvest/ImportRunner.php`:

```php
<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateCounter;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ImportRunner
{
    public function __construct(
        private ClientImporter $clients,
        private ProjectImporter $projects,
        private InvoiceImporter $invoices,
        private EstimateImporter $estimates,
    ) {}

    /** Network phase — pull everything into memory before touching the DB. */
    public function fetch(HarvestApi $api): HarvestData
    {
        return new HarvestData(
            clients: $api->clients()->all(),
            contacts: $api->contacts()->all(),
            projects: $api->projects()->all(),
            invoices: $api->invoices()->all(),
            estimates: $api->estimates()->all(),
        );
    }

    /** Persistence phase — wipe + insert + counter bump, atomically. */
    public function import(HarvestData $data): ImportSummary
    {
        return DB::transaction(function () use ($data) {
            $this->wipe();

            $clientMap = $this->clients->import($data->clients, $data->contacts);
            $projectMap = $this->projects->import($data->projects, $clientMap);
            $invoiceResult = $this->invoices->import($data->invoices, $clientMap);
            $estimateResult = $this->estimates->import($data->estimates, $clientMap);

            CounterReconciler::reconcileInvoices();
            CounterReconciler::reconcileEstimates();

            return new ImportSummary(
                clients: count($clientMap),
                projects: count($projectMap),
                invoices: $invoiceResult['imported'],
                estimates: $estimateResult['imported'],
                warnings: array_merge($invoiceResult['warnings'], $estimateResult['warnings']),
            );
        });
    }

    /** FK-safe wipe: estimates → invoices → projects → clients, then reset counters. */
    private function wipe(): void
    {
        Estimate::query()->delete();
        Invoice::query()->delete();
        Project::query()->delete();
        Client::query()->delete();
        InvoiceCounter::query()->delete();
        EstimateCounter::query()->delete();
    }
}
```

> Use bulk `delete()` (a single `DELETE` statement per table), **not** `truncate()`:
> the DB-level FK actions still fire on a bulk delete — `cascadeOnDelete` clears
> `*_lines`, `*_events`, `tasks`, and `time_entries`; `nullOnDelete` clears
> `time_entries.invoice_id` — whereas `truncate` ignores FKs and would fail or
> orphan rows. Order matters: clients are deleted last because invoices/projects/
> estimates `restrictOnDelete` against them.

- [ ] **Step 6: Run the test to verify it passes**

Run: `ddev artisan test --filter='ImportRunnerTest'`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Harvest/HarvestData.php app/Services/Harvest/ImportSummary.php app/Services/Harvest/ImportRunner.php tests/Feature/Harvest/ImportRunnerTest.php
git commit -m "feat(harvest): add ImportRunner (fetch + transactional wipe/insert)"
```

---

## Task 8: HarvestImportCommand

**Files:**
- Create: `app/Console/Commands/HarvestImportCommand.php`
- Test: `tests/Feature/Console/HarvestImportCommandTest.php`

The command resolves credentials, builds `HarvestApi`, fetches via `ImportRunner`,
handles `--dry-run`, the destructive confirmation (with a time-entry warning), and
`--force`, then runs the import and prints a summary.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Console/HarvestImportCommandTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeHarvest(): void
{
    Http::fake([
        '*/clients*' => Http::response(['clients' => [['id' => 77, 'name' => 'Atlas Robotics', 'is_active' => true]], 'next_page' => null]),
        '*/contacts*' => Http::response(['contacts' => [], 'next_page' => null]),
        '*/projects*' => Http::response(['projects' => [['id' => 1, 'client' => ['id' => 77], 'name' => 'Web', 'code' => 'WEB', 'is_active' => true, 'is_billable' => true, 'hourly_rate' => 145.0]], 'next_page' => null]),
        '*/invoices*' => Http::response(['invoices' => [[
            'id' => 9, 'client' => ['id' => 77], 'number' => '2025-001', 'state' => 'paid', 'currency' => 'CHF',
            'issue_date' => '2025-01-01', 'amount' => 108.1, 'tax' => 8.1, 'tax_amount' => 8.1,
            'line_items' => [['id' => 1, 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'taxed' => true]],
        ]], 'next_page' => null]),
        '*/estimates*' => Http::response(['estimates' => [], 'next_page' => null]),
    ]);
}

test('errors when credentials are missing', function () {
    config(['services.harvest.access_token' => null, 'services.harvest.account_id' => null]);

    $this->artisan('harvest:import')
        ->expectsOutputToContain('Missing Harvest credentials')
        ->assertExitCode(1);
});

test('--dry-run fetches and reports without writing', function () {
    fakeHarvest();

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(Client::count())->toBe(0);
    expect(Invoice::count())->toBe(0);
});

test('--force imports without prompting and reports a summary', function () {
    fakeHarvest();

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a', '--force' => true])
        ->assertExitCode(0);

    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeTrue();
    expect(Project::count())->toBe(1);
    expect(Invoice::where('number', '2025-001')->exists())->toBeTrue();
});

test('aborts when time entries exist and the user declines the confirmation', function () {
    fakeHarvest();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);

    $this->artisan('harvest:import', ['--token' => 't', '--account' => 'a'])
        ->expectsConfirmation('This will DELETE all clients, projects, invoices and estimates (and 1 time entr(y/ies) + their tasks). Continue?', 'no')
        ->assertExitCode(1);

    expect(Client::where('name', 'Atlas Robotics')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `ddev artisan test --filter='HarvestImportCommandTest'`
Expected: FAIL — command `harvest:import` not registered.

- [ ] **Step 3: Create the command**

`app/Console/Commands/HarvestImportCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use App\Services\Harvest\HarvestApi;
use App\Services\Harvest\HarvestApiException;
use App\Services\Harvest\ImportRunner;
use Illuminate\Console\Command;

class HarvestImportCommand extends Command
{
    protected $signature = 'harvest:import
        {--token= : Harvest personal access token (defaults to HARVEST_ACCESS_TOKEN)}
        {--account= : Harvest account id (defaults to HARVEST_ACCOUNT_ID)}
        {--dry-run : Fetch and report counts without writing anything}
        {--force : Skip the destructive confirmation prompt}';

    protected $description = 'One-time import of clients, projects, invoices and estimates from Harvest.';

    public function handle(ImportRunner $runner): int
    {
        $token = $this->option('token') ?: config('services.harvest.access_token');
        $account = $this->option('account') ?: config('services.harvest.account_id');

        if (! $token || ! $account) {
            $this->error('Missing Harvest credentials. Pass --token and --account, or set HARVEST_ACCESS_TOKEN and HARVEST_ACCOUNT_ID.');
            return self::FAILURE;
        }

        $api = new HarvestApi(
            $token,
            $account,
            config('services.harvest.base_url'),
            config('services.harvest.user_agent'),
        );

        try {
            $this->info('Fetching from Harvest…');
            $data = $runner->fetch($api);
        } catch (HarvestApiException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $counts = sprintf(
            '%d clients, %d projects, %d invoices, %d estimates',
            count($data->clients), count($data->projects), count($data->invoices), count($data->estimates),
        );

        if ($this->option('dry-run')) {
            $this->info("Dry run — would import: {$counts}. Nothing written.");
            return self::SUCCESS;
        }

        if (! $this->confirmDestructive()) {
            $this->warn('Aborted. Nothing was changed.');
            return self::FAILURE;
        }

        $summary = $runner->import($data);

        $this->info(sprintf(
            'Imported %d clients, %d projects, %d invoices, %d estimates.',
            $summary->clients, $summary->projects, $summary->invoices, $summary->estimates,
        ));
        foreach ($summary->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    private function confirmDestructive(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $timeEntries = TimeEntry::count();
        $message = $timeEntries > 0
            ? "This will DELETE all clients, projects, invoices and estimates (and {$timeEntries} time entr(y/ies) + their tasks). Continue?"
            : 'This will DELETE all clients, projects, invoices and estimates in ernte. Continue?';

        return $this->confirm($message);
    }
}
```

> Laravel auto-discovers commands in `app/Console/Commands`, so no manual
> registration is needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev artisan test --filter='HarvestImportCommandTest'`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/HarvestImportCommand.php tests/Feature/Console/HarvestImportCommandTest.php
git commit -m "feat(harvest): add harvest:import command (creds, dry-run, force, confirm)"
```

---

## Task 9: Full-suite verification + manual dry-run check

**Files:** none (verification only)

- [ ] **Step 1: Run the entire suite**

Run: `ddev artisan test --exclude-group browsershot`
Expected: PASS — all existing tests plus the new Harvest tests (≈20 new) green.

Run: `ddev artisan test --group browsershot`
Expected: PASS — invoice/estimate PDF tests still green (untouched by this work).

- [ ] **Step 2: Confirm the command is registered and documented**

Run: `ddev artisan list | grep harvest`
Expected: shows `harvest:import` with its description.

Run: `ddev artisan harvest:import --help`
Expected: shows the `--token`, `--account`, `--dry-run`, `--force` options.

- [ ] **Step 3: Manual dry-run against real Harvest (optional, requires real creds)**

If real Harvest credentials are available, do a **dry run** (writes nothing):
```
ddev artisan harvest:import --token=<PAT> --account=<ID> --dry-run
```
Confirm the reported counts (clients/projects/invoices/estimates) look right before
ever running the real (destructive) import. If credentials aren't available, say so
— the `Http::fake()` tests already exercise the full mapping path end-to-end.

- [ ] **Step 4: Final commit (if any tweaks were needed)**

```bash
git add -A
git commit -m "test(harvest): verify full suite + command registration"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** API client + auth/pagination/429 (Task 1); clients incl.
  contacts + short-code dedup (Task 2); projects incl. rate/budget mapping (Task 3);
  invoices incl. number preservation, state map, verbatim totals, line items,
  `created` event (Task 4); estimates incl. 1:1 status + `decided_at` (Task 5);
  counter bump (Task 6); fetch-then-transaction(wipe→insert→bump) + rollback +
  summary (Task 7); command with creds/dry-run/force/time-entry confirmation
  (Task 8); verification (Task 9). Currency warnings are produced by Tasks 4/5 and
  surfaced by Task 8. The spec's "out of scope" items (time entries, expenses,
  multi-currency conversion, idempotent re-sync, PDF/QR) are intentionally absent.
- **No migrations** — confirmed; `created` events use the existing enum value and a
  JSON payload, no schema change.
- **Type consistency:** importers return `array<int,Model>` maps (clients/projects)
  or `['imported'=>int,'warnings'=>string[]]` (invoices/estimates); `ImportRunner`
  consumes exactly those shapes; `ImportSummary` fields match the command's output.
- **Destructiveness:** the wipe is per-model (fires FK cascade/SET NULL); the
  command gates it behind confirmation with an explicit time-entry/task warning, and
  `--force` for non-interactive use. Fetch precedes the transaction so a network
  failure can't leave the DB wiped-but-empty.
