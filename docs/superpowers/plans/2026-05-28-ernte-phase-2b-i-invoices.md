# Ernte — Phase 2b-i (Invoices core: Index + Create + Show + PDF + QR-bill) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the invoice subsystem end-to-end without outbound email: list invoices (stats + filters), create a draft from billable/unbilled time entries (period picker + entry checklist + editable line grouping with VAT-exempt toggle), view an invoice as a print-faithful document with an activity sidebar, transition it through its lifecycle (issue → paid, or void), and download a Swiss QR-bill PDF rendered by headless Chromium.

**Architecture:** The Phase-1 domain services (`InvoiceBuilder`, `InvoiceNumberer`, `QrReferenceGenerator`) already exist and are tested. This phase refactors `InvoiceBuilder` to split *suggesting* grouped lines (used to pre-fill the Create editor) from *persisting* a draft with the user's edited lines, adds an `InvoiceLifecycle` service for state transitions (`issue`/`markPaid`/`void`, each transactional and event-writing), adds an `InvoicePdfRenderer` (Blade → Browsershot) and a `QrBillRenderer` (the `sprain/swiss-qr-bill` SVG payment part), and exposes everything through a thin `InvoiceController`. Pages are Inertia Vue SFCs consuming pre-aggregated projections — no client-side money math is trusted; the server recomputes all amounts. The same Blade document template backs both the on-screen `Invoices/Show` (via an iframe to a `/preview` route) and the PDF (via `Browsershot::html()`), so the document is defined once.

**Email is explicitly out of scope** for 2b-i. The `send` action *issues* the invoice (stamps dates, renders + caches the PDF, writes events, transitions `draft → sent`) but does **not** email it. Phase 2b-ii wraps the `InvoiceMail` dispatch + queue into that same method; the route and method name (`send`) are introduced now so 2b-ii is purely additive.

**Tech Stack:** Laravel 12, Inertia.js 2.x + Vue 3 Composition API, Pest. Two new composer packages this phase: `spatie/browsershot` (headless-Chromium PDF) and `sprain/swiss-qr-bill` (QR payment part). No new npm packages.

**Source spec:** `docs/superpowers/specs/2026-05-27-ernte-design.md` — §6 invoice flow, §7 UX system, §9 domain rules (#2 money math, #4 entry attachment, #5 QR uniqueness, #6 VAT stamping, #7 PDF determinism).
**Carryover:** `docs/superpowers/phase-2b-carryover.md`.
**Predecessor plan:** `docs/superpowers/plans/2026-05-27-ernte-phase-2a-views.md` (merged, tag `phase-2a`).
**Sibling plan to be written next:** Phase 2b-ii — email send + reminders + overdue-stamp job + Settings/Profile + Reports placeholder + ⌘K palette + keyboard shortcuts + backup command.

---

## Discoveries that change the carryover's assumptions

Read these before starting — they were found by inspecting the actual repo state, and two of them contradict the carryover.

1. **The Phase 2a CSS port is incomplete (not just invoices).** The carryover says "CSS is the verbatim port from `design/ernte/project/styles.css` … Phase 2b should keep hand-rolling and reuse existing classes." In fact `resources/css/base.css` (391 lines) only ported the **chrome** subset of the 750-line design `styles.css`. The compiled bundle (`public/build/assets/app-*.css`) is missing every page-level block: `.table*`, `.stats/.stat*`, `.proj-cell`, `.detail-grid/.detail-main/.detail-side`, `.kv*`, `.heat*`, `.task-*`, `.entry-row*`, `.timer-*`, `.spark*`, `.burndown`, `.client-card`, `.divider-row`, and all `.invoice-*`. These classes are referenced by shipped Phase 2a pages, so those pages currently render with unstyled tables/charts. **Task 2 completes the port** (verbatim from the in-repo `styles.css`). This both unblocks the invoice pages and retroactively styles the Phase 2a pages. The design `styles.css` is canonical and in the repo, so the task cites source line ranges to copy (same convention the Phase 2a plan used for JSX→Vue ports).

2. **Vue pages use hardcoded path strings, not Ziggy `route()`.** The Phase 2a plan's "Conventions" section said to use `route('projects.show', …)`, but every shipped page uses literal paths (`/clients`, `` `/projects/${code}` ``). Follow the **actual** code: use literal paths. (Ziggy is installed but unused in pages.)

3. **The design is a Berlin/EUR/SEPA mock; the spec is Swiss/CHF with German invoice labels.** The design `InvoiceDetail` shows `€`, "VAT 19%", and a SEPA payment block. The carryover flags a `€ → CHF` sweep as "worth doing when the invoice document lands." This plan makes the **invoice pages and the PDF** CHF + German document labels (Rechnung, Betrag, MwSt). It does **not** sweep `€` out of the Projects/Clients/Timer pages — that broader localization stays a known-pending item (call it out again in the 2b-ii carryover). Invoice money helpers format `CHF` with `de-CH` grouping.

4. **`spatie/browsershot` and `sprain/swiss-qr-bill` are not yet installed.** `composer.json` has neither. Task 9 installs them. DDEV's web image already has Chromium at `/usr/bin/chromium` with `BROWSERSHOT_CHROME_PATH` set (per the carryover), so no system setup is needed — `composer require` and go.

5. **`InvoiceBuilder::buildDraftFromEntries()` exists and is green (8 tests).** It auto-groups entries and hardcodes `vat_exempt: false`. The Create flow needs the user to edit lines *before* the first save, so Task 4 refactors the grouping out into `suggestLinesFromEntries()` and adds `createDraft()` that persists the user's submitted lines (with per-line `vat_exempt`). `buildDraftFromEntries()` is kept as a thin wrapper (suggest → create) so its existing tests stay green.

6. **`InvoiceFactory` produces test-only numbers** (`2026-T#####`). When asserting on production number format (`YYYY-NNN`) in lifecycle/builder tests, create invoices through `InvoiceBuilder`/`InvoiceNumberer`, not the factory. The factory is fine for Index/projection tests that don't assert on number format.

---

## File map for Phase 2b-i

Created:

| Path | Responsibility |
|---|---|
| `app/Http/Controllers/InvoiceController.php` | `index/create/store/show/preview/update/send/markPaid/void/pdf` |
| `app/Http/Requests/StoreInvoiceRequest.php` | Validation for `POST /invoices` (period, client, entry_ids, lines) |
| `app/Http/Requests/UpdateInvoiceRequest.php` | Validation for `PATCH /invoices/{invoice}` (draft notes + lines) |
| `app/Services/Invoicing/InvoiceLifecycle.php` | Transactional `issue()`, `markPaid()`, `void()` + event writes |
| `app/Services/Invoicing/QrBillRenderer.php` | Builds a `sprain/swiss-qr-bill` payment part as an HTML/SVG string |
| `app/Services/Invoicing/InvoicePdfRenderer.php` | Renders the invoice Blade to HTML and to a cached PDF via Browsershot |
| `app/Support/InvoiceProjections.php` | Index rows + stats (outstanding/overdue/paid-ytd/avg-days) |
| `resources/views/invoices/pdf.blade.php` | The invoice document (German labels, CHF, QR-bill) — used on-screen and in PDF |
| `resources/js/Pages/Invoices/Create.vue` | Period picker + entry checklist + editable line grouping |
| `resources/js/Pages/Invoices/Show.vue` | Document iframe + activity sidebar + linked entries + actions |
| `tests/Feature/Services/InvoiceLifecycleTest.php` | issue/markPaid/void transitions + void unlocks entries |
| `tests/Feature/Services/QrBillRendererTest.php` | QR payment part contains IBAN + reference; validates |
| `tests/Feature/Http/InvoiceControllerTest.php` | index/create/store/show/update/send/paid/void prop + redirect assertions |
| `tests/Feature/Support/InvoiceProjectionsTest.php` | outstanding/overdue/paid-ytd math + per-client outstanding |

Modified:

| Path | What changes |
|---|---|
| `app/Models/Client.php` | Add `invoices()` hasMany (carryover) |
| `app/Models/Invoice.php` | Add `scopeOutstanding`, `scopePaid`; `hours` accessor; relation tweaks |
| `app/Services/Invoicing/InvoiceBuilder.php` | Extract `suggestLinesFromEntries()`; add `createDraft()`; `buildDraftFromEntries()` delegates |
| `app/Support/ClientProjections.php` | Compute real `outstanding` per client (was hardcoded `0`) |
| `app/Support/DashboardProjections.php` | Compute real `outstanding_amount` (was hardcoded `0.0`) |
| `resources/css/base.css` | Append the missing page-level blocks from design `styles.css` (Discovery #1) |
| `resources/js/Pages/Invoices/Index.vue` | Replace placeholder with the real stats+filters+table page |
| `resources/js/Pages/Projects/Show.vue` | Enable the disabled `+ Invoice` button → `/invoices/new?client=&project=` |
| `resources/js/Pages/Clients/Index.vue` | Make the row link offer an `+ Invoice` affordance → `/invoices/new?client=` |
| `routes/web.php` | Replace the `/invoices` closure with the `InvoiceController` route group |
| `database/seeders/DemoFixturesSeeder.php` | Seed a few demo invoices (draft/sent/overdue/paid) for manual verification |
| `composer.json` / `composer.lock` | Add `spatie/browsershot`, `sprain/swiss-qr-bill` |
| `docs/superpowers/phase-2b-carryover.md` | Update: mark 2b-i items done, refine 2b-ii scope |

---

## Conventions (carried from Phase 2a, plus 2b-i specifics)

- **Branch:** create `phase-2b-i-invoices` from `main` before Task 1.
- **All shell commands run inside DDEV:** `ddev artisan`, `ddev composer`, `ddev npm`, `ddev exec`.
- **Money:** server-side stays in integer rappen; `computeTotals` is the single source of truth. **Never trust client-submitted amounts** — recompute `amount_rappen = round(hours * rate_rappen)` and re-run `computeTotals` server-side. No `_rappen` field is exposed to Vue as a float; projections divide by 100 at the seam.
- **Times:** DB stores UTC; Inertia serializes Carbon to ISO-8601; Vue parses with `new Date(iso)`. Date-only fields (`issued_on`, `due_on`, `period_*`) serialize as `YYYY-MM-DD`.
- **Form requests:** every mutation has a dedicated `app/Http/Requests/*Request.php`; controllers stay thin.
- **Inertia tests:** `Inertia\Testing\AssertableInertia as Assert`; assert component name + prop shape, never rendered HTML. `RefreshDatabase` is already wired in `tests/Pest.php` for the `Feature` suite.
- **`BusinessProfile` is required** by the builder (`BusinessProfile::current()` calls `firstOrFail()`). Tests that hit the builder/lifecycle/PDF must create one in `beforeEach` (see the existing `InvoiceBuilderTest` pattern).
- **Paths in Vue are literal strings** (Discovery #2).
- **Invoice document is German + CHF** (Discovery #3). UI chrome stays English.
- **Commits:** imperative + scoped, one (or a few) per task, same style as Phases 0/1/2a. End each task on green tests + a commit.

---

## Task 0: Branch + baseline

- [ ] **Step 1: Branch off main**

```
host$ git checkout main
host$ git pull
host$ git checkout -b phase-2b-i-invoices main
host$ git status
```
Expected: "On branch phase-2b-i-invoices, nothing to commit, working tree clean".

- [ ] **Step 2: Confirm baseline tests pass**

```
host$ ddev artisan test
```
Expected: the full Phase 2a suite passes (~129 tests per the carryover). Record the number; every later task must keep it green and growing.

- [ ] **Step 3: Confirm Vite builds**

```
host$ ddev npm run build
```
Expected: build succeeds, no missing-import warnings.

No commit — setup only.

---

## Task 1: Carryover — `Client::invoices()` relationship

The Phase-1 carryover deferred this; `/invoices/new?client=…` and the per-client outstanding total need it.

**Files:**
- Modify: `app/Models/Client.php`
- Test: `tests/Feature/Schema/ClientInvoicesRelationshipTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Schema/ClientInvoicesRelationshipTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Invoice;

test('Client has many invoices', function () {
    $client = Client::factory()->create();
    Invoice::factory()->count(2)->create(['client_id' => $client->id]);
    Invoice::factory()->create(); // different client

    expect($client->invoices)->toHaveCount(2);
    expect($client->invoices->first())->toBeInstanceOf(Invoice::class);
});

test('Client::invoices returns a HasMany relation', function () {
    $client = Client::factory()->create();
    expect($client->invoices())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=ClientInvoicesRelationship
```
Expected: FAIL "Call to undefined method App\Models\Client::invoices()".

- [ ] **Step 3: Add the relationship**

In `app/Models/Client.php`, next to `projects()`:
```php
public function invoices()
{
    return $this->hasMany(Invoice::class);
}
```

- [ ] **Step 4: Run — expect PASS**

```
host$ ddev artisan test --filter=ClientInvoicesRelationship
```
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```
host$ git add app/Models/Client.php tests/Feature/Schema/ClientInvoicesRelationshipTest.php
host$ git commit -m "feat(model): Client::invoices relationship"
```

---

## Task 2: Complete the design CSS port (Discovery #1)

Append the page-level blocks from `design/ernte/project/styles.css` that are missing from `resources/css/base.css`. This is a verbatim copy of in-repo canonical source — no invention. It unblocks every invoice page and retroactively styles the Phase 2a tables/charts.

**Files:**
- Modify: `resources/css/base.css`

- [ ] **Step 1: Identify exactly what's missing**

Run the selector diff to confirm the gap before copying:
```
host$ sel() { grep -oE '^[^{]+\{' "$1" | sed 's/{.*//; s/[[:space:]]*$//' | sort -u; }
host$ comm -23 <(sel design/ernte/project/styles.css) <(sel resources/css/base.css)
```
Expected: a list including `.table*`, `.stats/.stat*`, `.proj-cell`, `.detail-grid/.detail-main/.detail-side`, `.kv*`, `.heat*`, `.task-*`, `.entry-row*`, `.timer-*`, `.spark*`, `.burndown`, `.client-card`, `.divider-row`, `.invoice-*`. Ignore token-level entries (`:root`, `[data-theme="dark"]`, `[data-density="compact"]`, `html, body, #root`) — those already live in `tokens.css`/`app.css`; do **not** copy them.

- [ ] **Step 2: Append the missing blocks verbatim**

Copy these contiguous ranges from `design/ernte/project/styles.css` and append them to the end of `resources/css/base.css`, in this order. These ranges contain only page-level classes (the chrome blocks above line 377 are already in `base.css`):

- **Tables + content extras:** `styles.css` lines **377–486** (section "Tables" through the end of the content block — `.table-wrap`, `.table*`, `.proj-cell`, `.stats`, `.stat*`, `.delta.up/.down`, plus any `.divider-row`/`.client-card` in range).
- **Project Detail + Tasks:** lines **508–583** (`.detail-grid/.detail-main/.detail-side`, `.kv*`, `.task-list`, `.task-row`, `.task-*`).
- **Timer page:** lines **584–631** (`.timer-hero`, `.timer-stage`, `.timer-display*`, `.timer-meta`, `.entry-row*`).
- **Invoice detail:** lines **632–685** (`.invoice-page`, `.invoice-doc-wrap`, `.invoice-doc`, `.invoice-side`, `.invoice-h`, `.invoice-line*`, `.invoice-totals*`).
- **Clients + sparkline + heat:** lines **686–713** (`.client-card*`, `.spark*`, `.heat*`).
- **Scrollbars for invoice panes:** lines **738–745** (`.invoice-doc-wrap`/`.invoice-side` scrollbar rules), if not already covered by the content/sidebar scrollbar rules in `base.css`.

After pasting, re-run the Step-1 diff. Expected: the only remaining differences are the token-level false positives. If a real page-level selector is still missing, locate it in `styles.css` and append it too. If a selector got duplicated (already in `base.css`), delete the duplicate you just pasted.

- [ ] **Step 3: Verify the CSS variables referenced all exist**

The pasted blocks reference `var(--bg-hover)` (table row hover). Confirm it resolves:
```
host$ grep -n "bg-hover" resources/css/tokens.css design/ernte/project/styles.css
```
If `--bg-hover` is **not** defined in `tokens.css`, add it to both theme blocks in `resources/css/tokens.css` using the design's value (grep `styles.css` for its `:root` definition; it is `--bg-hover` in the paper/dark token sets). Expected end state: every `var(--…)` used by the pasted CSS is defined in `tokens.css`.

- [ ] **Step 4: Build + eyeball Phase 2a pages**

```
host$ ddev npm run build
host$ ddev artisan migrate:fresh --seed
host$ ddev artisan db:seed --class=DemoFixturesSeeder
host$ ddev npm run dev   # leave running
```
Visit `https://ernte.ddev.site/projects` and `/clients`. Expected: the project/client **tables now have borders, padding, hover, right-aligned numeric columns**, the stats strip is laid out in columns, and `/projects/{code}` shows a styled burn-down + heatmap + key-value detail list. No console errors.

- [ ] **Step 5: Full suite still green (CSS is non-functional to tests, but confirm no build/import breakage)**

```
host$ ddev artisan test
```
Expected: unchanged from Task 0.

- [ ] **Step 6: Commit**

```
host$ git add resources/css/base.css resources/css/tokens.css
host$ git commit -m "fix(ui): complete the design CSS port (tables, stats, detail, invoice, charts)"
```

---

## Task 3: Invoice scopes + real outstanding/paid projections

Wire the two carryover placeholders (`ClientProjections.outstanding = 0`, `DashboardProjections.stats.outstanding_amount = 0.0`) to real data, and add the `InvoiceProjections` helper the Index page will consume. Outstanding = invoices with `status='sent'` (overdue is `sent` + past-due, still `sent`). Paid-YTD = `status='paid'` issued this calendar year.

**Files:**
- Modify: `app/Models/Invoice.php`
- Create: `app/Support/InvoiceProjections.php`
- Modify: `app/Support/ClientProjections.php`
- Modify: `app/Support/DashboardProjections.php`
- Test: `tests/Feature/Support/InvoiceProjectionsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/InvoiceProjectionsTest.php`:
```php
<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Support\ClientProjections;
use App\Support\DashboardProjections;
use App\Support\InvoiceProjections;
use App\Models\User;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
});

test('stats sum outstanding (sent), overdue, and paid-this-year', function () {
    // sent, not yet due -> outstanding only
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_00]);
    // sent, past due -> outstanding AND overdue
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->subDays(3)->toDateString(), 'total_rappen' => 200_00]);
    // paid this year -> paid_ytd only
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->startOfYear()->addDays(5)->toDateString(), 'total_rappen' => 500_00]);
    // draft -> none
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft', 'total_rappen' => 999_00]);

    $stats = InvoiceProjections::stats();

    expect($stats['outstanding'])->toBe(300.0);   // 100 + 200
    expect($stats['overdue'])->toBe(200.0);
    expect($stats['paid_ytd'])->toBe(500.0);
});

test('index rows expose number, client, total, hours, status, overdue flag', function () {
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2026-014', 'due_on' => now()->subDay()->toDateString(), 'total_rappen' => 428_000]);
    \App\Models\InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 12.5]);
    \App\Models\InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 17.0]);

    $rows = InvoiceProjections::index();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['number'])->toBe('2026-014');
    expect($row['client']['name'])->toBe('Atlas Robotics');
    expect($row['total'])->toBe(4280.0);
    expect($row['hours'])->toBe(29.5);
    expect($row['status'])->toBe('sent');
    expect($row['overdue'])->toBeTrue();
});

test('index filter narrows by status; overdue is a virtual filter over sent', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft']);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'due_on' => now()->subDay()->toDateString()]);

    expect(InvoiceProjections::index('draft'))->toHaveCount(1);
    expect(InvoiceProjections::index('overdue'))->toHaveCount(1);
    expect(InvoiceProjections::index('sent'))->toHaveCount(1);
});

test('per-client outstanding lands on ClientProjections rows', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'total_rappen' => 150_00]);

    $row = ClientProjections::index()->firstWhere('id', $this->client->id);
    expect($row['outstanding'])->toBe(150.0);
});

test('global outstanding_amount lands on DashboardProjections stats', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'total_rappen' => 321_00]);

    $stats = DashboardProjections::stats(User::factory()->create());
    expect($stats['outstanding_amount'])->toBe(321.0);
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceProjections
```
Expected: FAIL — `InvoiceProjections` class missing; `outstanding`/`outstanding_amount` still 0.

- [ ] **Step 3: Add scopes + `hours` accessor to `Invoice`**

In `app/Models/Invoice.php`, add inside the class (after `getOverdueAttribute`):
```php
public function scopeOutstanding($q) { return $q->where('status', 'sent'); }
public function scopePaid($q)        { return $q->where('status', 'paid'); }

public function getHoursAttribute(): float
{
    return round((float) $this->lines->sum('hours'), 2);
}
```

- [ ] **Step 4: Create `InvoiceProjections`**

`app/Support/InvoiceProjections.php`:
```php
<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InvoiceProjections
{
    /**
     * Invoice list rows for /invoices.
     *
     * $filter: 'all' | 'draft' | 'sent' | 'overdue' | 'paid' | 'void'.
     * 'overdue' is virtual: status='sent' AND due_on < today.
     *
     * @return Collection<int, array>
     */
    public static function index(string $filter = 'all', ?string $search = null): Collection
    {
        $q = Invoice::query()
            ->with(['client:id,name', 'project:id,name', 'lines:id,invoice_id,hours']);

        if ($filter === 'overdue') {
            $q->where('status', 'sent')->whereDate('due_on', '<', Carbon::today());
        } elseif (in_array($filter, ['draft', 'sent', 'paid', 'void'], true)) {
            $q->where('status', $filter);
        }

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('number', 'like', "%{$search}%")
                  ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $q->orderByDesc('id')->get()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'overdue' => $i->overdue,
            'issued_on' => $i->issued_on?->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'hours' => round((float) $i->lines->sum('hours'), 2),
            'total' => round($i->total_rappen / 100, 2),
            'client' => ['id' => $i->client->id, 'name' => $i->client->name],
            'project_name' => $i->project?->name,
        ]);
    }

    /** Top-of-page summary numbers in CHF. */
    public static function stats(): array
    {
        $outstanding = (int) Invoice::outstanding()->sum('total_rappen');

        $overdue = (int) Invoice::query()
            ->where('status', 'sent')
            ->whereDate('due_on', '<', Carbon::today())
            ->sum('total_rappen');

        $paidYtd = (int) Invoice::query()
            ->where('status', 'paid')
            ->whereYear('issued_on', Carbon::now()->year)
            ->sum('total_rappen');

        // Average days from issue to payment over paid invoices (null if none).
        $avg = Invoice::query()
            ->where('status', 'paid')
            ->whereNotNull('issued_on')
            ->whereNotNull('paid_at')
            ->selectRaw('AVG(DATEDIFF(paid_at, issued_on)) AS d')
            ->value('d');

        return [
            'outstanding' => round($outstanding / 100, 2),
            'overdue' => round($overdue / 100, 2),
            'paid_ytd' => round($paidYtd / 100, 2),
            'avg_days_to_pay' => $avg !== null ? (int) round((float) $avg) : null,
            'count' => Invoice::count(),
        ];
    }

    /** Outstanding (sent) total in rappen, keyed by client_id. */
    public static function outstandingByClient(): Collection
    {
        return Invoice::outstanding()
            ->selectRaw('client_id, SUM(total_rappen) AS rappen')
            ->groupBy('client_id')
            ->pluck('rappen', 'client_id');
    }
}
```

- [ ] **Step 5: Wire `ClientProjections.outstanding`**

In `app/Support/ClientProjections.php`, replace the hardcoded placeholder. Add near the top of `index()` (after `$hoursYtd = …`):
```php
$outstanding = \App\Support\InvoiceProjections::outstandingByClient();
```
Then change the mapped row's outstanding line from:
```php
'outstanding' => 0,                             // Phase 2b
```
to:
```php
'outstanding' => round(((int) ($outstanding[$c->id] ?? 0)) / 100, 2),
```

- [ ] **Step 6: Wire `DashboardProjections.stats.outstanding_amount`**

In `app/Support/DashboardProjections.php`, change the stats return line from:
```php
'outstanding_amount' => 0.0,        // populated in Phase 2b
```
to:
```php
'outstanding_amount' => \App\Support\InvoiceProjections::stats()['outstanding'],
```

- [ ] **Step 7: Run — expect PASS**

```
host$ ddev artisan test --filter=InvoiceProjections
host$ ddev artisan test --filter=ClientControllerTest
host$ ddev artisan test --filter=ProjectControllerTest
```
Expected: new tests PASS; the existing Client/Project controller tests still PASS (they assert `outstanding`/`outstanding_amount` keys exist, now non-zero where invoices exist — they used no invoices, so values stay 0).

- [ ] **Step 8: Commit**

```
host$ git add app/Models/Invoice.php app/Support/InvoiceProjections.php app/Support/ClientProjections.php app/Support/DashboardProjections.php tests/Feature/Support/InvoiceProjectionsTest.php
host$ git commit -m "feat(invoices): outstanding/overdue/paid projections + per-client outstanding"
```

---

## Task 4: `InvoiceBuilder` refactor — suggest lines vs. create draft

Split the auto-grouping (used to pre-fill the Create editor) from persistence (which must accept the user's edited lines with per-line `vat_exempt`). Keep `buildDraftFromEntries()` green by delegating.

**Files:**
- Modify: `app/Services/Invoicing/InvoiceBuilder.php`
- Test: `tests/Feature/Services/InvoiceBuilderTest.php` (extend; keep existing tests)

- [ ] **Step 1: Write the new failing tests**

Append to `tests/Feature/Services/InvoiceBuilderTest.php`:
```php
test('suggestLinesFromEntries groups by description and sums hours/amount', function () {
    $e1 = makeEntry($this->user, $this->project, 'PR review', 60);
    $e2 = makeEntry($this->user, $this->project, 'PR review', 30);
    makeEntry($this->user, $this->project, 'Telemetry', 120);

    $lines = $this->svc->suggestLinesFromEntries(TimeEntry::all(), $this->project);

    expect($lines)->toHaveCount(2);
    $pr = collect($lines)->firstWhere('description', 'PR review');
    expect($pr['hours'])->toBe(1.5);
    expect($pr['rate_rappen'])->toBe(14500);
    expect($pr['amount_rappen'])->toBe(21750);
    expect($pr['vat_exempt'])->toBeFalse();
    expect($pr['entry_ids'])->toEqualCanonicalizing([$e1->id, $e2->id]);
});

test('createDraft persists submitted lines with per-line vat_exempt and recomputes amounts', function () {
    $e = makeEntry($this->user, $this->project, 'Work', 120);

    $invoice = $this->svc->createDraft(
        client: $this->client,
        project: $this->project,
        periodStart: now()->subDays(7)->toDateString(),
        periodEnd: now()->toDateString(),
        lines: [
            ['description' => 'Consulting',     'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
            ['description' => 'Reimbursement',  'hours' => 1.0, 'rate_rappen' => 5000,  'vat_exempt' => true],
        ],
        entryIds: [$e->id],
    );

    expect($invoice->status)->toBe('draft');
    expect($invoice->lines)->toHaveCount(2);

    $consulting = $invoice->lines->firstWhere('description', 'Consulting');
    expect($consulting->amount_rappen)->toBe(29000);          // recomputed server-side: 2 * 14500
    expect($consulting->vat_exempt)->toBeFalse();

    $reimb = $invoice->lines->firstWhere('description', 'Reimbursement');
    expect($reimb->amount_rappen)->toBe(5000);
    expect($reimb->vat_exempt)->toBeTrue();

    // subtotal = 34000; vat = 8.10% of taxable 29000 = 2349; total = 36349
    expect($invoice->subtotal_rappen)->toBe(34000);
    expect($invoice->vat_rappen)->toBe(2349);
    expect($invoice->total_rappen)->toBe(36349);

    expect($e->fresh()->invoice_id)->toBe($invoice->id);
    expect($invoice->qr_reference)->toMatch('/^\d{27}$/');
    expect($invoice->number)->toMatch('/^\d{4}-\d{3}$/');
    expect($invoice->events()->where('kind', 'created')->count())->toBe(1);
});

test('createDraft ignores client-submitted amount_rappen (anti-tamper)', function () {
    $invoice = $this->svc->createDraft(
        client: $this->client, project: null,
        periodStart: now()->subDay()->toDateString(), periodEnd: now()->toDateString(),
        lines: [['description' => 'X', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false, 'amount_rappen' => 999999]],
        entryIds: [],
    );
    expect($invoice->lines->first()->amount_rappen)->toBe(10000);
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceBuilderTest
```
Expected: the 3 new tests FAIL (methods missing); the existing 8 still PASS.

- [ ] **Step 3: Refactor `InvoiceBuilder`**

Replace the body of `app/Services/Invoicing/InvoiceBuilder.php` from `buildDraftFromEntries()` downward with the following (keep the file header, imports, and `computeTotals()` exactly as they are — add `use Illuminate\Support\Arr;` to the imports):

```php
    /**
     * Group eligible entries into suggested invoice lines for the Create editor.
     * Pure read — does not persist anything.
     *
     * @return array<int, array{description:string, hours:float, rate_rappen:int, amount_rappen:int, vat_exempt:bool, entry_ids:int[]}>
     */
    public function suggestLinesFromEntries(Collection $entries, ?Project $project): array
    {
        $eligible = $entries
            ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
            ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
            ->values();

        $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
            ? $e->description
            : ('Task #' . $e->task_id));

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
                'vat_exempt' => false,
                'entry_ids' => $bucket->pluck('id')->all(),
            ];
        }

        return $lines;
    }

    /**
     * Persist a draft invoice from the user's edited lines and the selected entry ids.
     * Recomputes every line's amount and the invoice totals server-side (never trusts client math).
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}>  $lines
     * @param  int[]  $entryIds
     */
    public function createDraft(
        Client $client,
        ?Project $project,
        string $periodStart,
        string $periodEnd,
        array $lines,
        array $entryIds,
    ): Invoice {
        return DB::transaction(function () use ($client, $project, $periodStart, $periodEnd, $lines, $entryIds) {
            $profile = BusinessProfile::current();

            $number = $this->numberer->nextFor((int) date('Y'));

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

            $invoice->qr_reference = $this->qr->generate($invoice->id);

            $lineAmounts = [];
            $vatExempts  = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount
                $exempt = (bool) ($line['vat_exempt'] ?? false);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
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

            $totals = self::computeTotals($lineAmounts, $vatExempts, (float) $invoice->vat_rate);
            $invoice->subtotal_rappen = $totals['subtotal_rappen'];
            $invoice->vat_rappen      = $totals['vat_rappen'];
            $invoice->total_rappen    = $totals['total_rappen'];
            $invoice->save();

            if (! empty($entryIds)) {
                TimeEntry::whereIn('id', $entryIds)
                    ->whereNull('invoice_id')
                    ->where('billable', true)
                    ->update(['invoice_id' => $invoice->id]);
            }

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => [
                    'period' => ['start' => $periodStart, 'end' => $periodEnd],
                    'entries_count' => count($entryIds),
                ],
            ]);

            return $invoice->fresh(['lines', 'events']);
        });
    }

    /**
     * Back-compat convenience: auto-group entries and persist (used by existing tests/callers).
     */
    public function buildDraftFromEntries(
        Client $client,
        ?Project $project,
        Collection $entries,
        string $periodStart,
        string $periodEnd,
    ): Invoice {
        $suggested = $this->suggestLinesFromEntries($entries, $project);
        $entryIds = Arr::flatten(array_map(fn ($l) => $l['entry_ids'], $suggested));

        return $this->createDraft($client, $project, $periodStart, $periodEnd, $suggested, $entryIds);
    }
}
```

- [ ] **Step 4: Run — expect ALL builder tests PASS**

```
host$ ddev artisan test --filter=InvoiceBuilderTest
```
Expected: 11 tests PASS (8 existing + 3 new).

- [ ] **Step 5: Commit**

```
host$ git add app/Services/Invoicing/InvoiceBuilder.php tests/Feature/Services/InvoiceBuilderTest.php
host$ git commit -m "refactor(invoices): split suggestLines from createDraft; per-line vat_exempt"
```

---

## Task 5: `InvoiceLifecycle` — markPaid + void (transactional, event-writing)

`void()` is the Phase-1 carryover bug fix: it must flip `status='void'` **and** null the linked entries' `invoice_id` so they re-enter "unbilled" scopes. `markPaid()` stamps `paid_at`. `issue()` lands in Task 9 (it needs the PDF renderer). All transitions write an `invoice_events` row.

**Files:**
- Create: `app/Services/Invoicing/InvoiceLifecycle.php`
- Test: `tests/Feature/Services/InvoiceLifecycleTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/InvoiceLifecycleTest.php`:
```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
    $this->builder = app(InvoiceBuilder::class);
    $this->lifecycle = app(InvoiceLifecycle::class);
});

function draftWithEntry(): array
{
    $start = now()->subDays(2);
    $entry = TimeEntry::factory()->create([
        'user_id' => test()->user->id, 'project_id' => test()->project->id,
        'description' => 'Work', 'started_at' => $start, 'ended_at' => (clone $start)->addHour(),
        'billable' => true,
    ]);
    $invoice = test()->builder->buildDraftFromEntries(
        test()->client, test()->project, TimeEntry::all(),
        now()->subDays(7)->toDateString(), now()->toDateString()
    );
    return [$invoice, $entry];
}

test('markPaid transitions sent -> paid and stamps paid_at + event', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent', 'issued_on' => now()->subDays(3), 'due_on' => now()->addDays(27), 'sent_at' => now()->subDays(3)]);

    test()->lifecycle->markPaid($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->paid_at)->not->toBeNull();
    expect($invoice->events()->where('kind', 'paid')->count())->toBe(1);
});

test('markPaid is rejected unless the invoice is sent', function () {
    [$invoice] = draftWithEntry(); // draft
    expect(fn () => test()->lifecycle->markPaid($invoice))
        ->toThrow(\DomainException::class);
});

test('void clears linked entries invoice_id so they return to unbilled', function () {
    [$invoice, $entry] = draftWithEntry();
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
    expect(TimeEntry::unbilled()->billable()->count())->toBe(0);

    test()->lifecycle->void($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('void');
    expect($entry->fresh()->invoice_id)->toBeNull();
    expect(TimeEntry::unbilled()->billable()->count())->toBe(1);   // re-invoiceable again
    expect($invoice->events()->where('kind', 'voided')->count())->toBe(1);
});

test('void works on a sent invoice too', function () {
    [$invoice, $entry] = draftWithEntry();
    $invoice->update(['status' => 'sent', 'issued_on' => now(), 'due_on' => now()->addDays(30)]);

    test()->lifecycle->void($invoice);

    expect($invoice->fresh()->status)->toBe('void');
    expect($entry->fresh()->invoice_id)->toBeNull();
});

test('voiding does not free the number', function () {
    [$invoice] = draftWithEntry();
    $number = $invoice->number;
    test()->lifecycle->void($invoice);
    expect($invoice->fresh()->number)->toBe($number);
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceLifecycle
```
Expected: FAIL — `InvoiceLifecycle` missing.

- [ ] **Step 3: Create `InvoiceLifecycle` (markPaid + void; issue stub added in Task 9)**

`app/Services/Invoicing/InvoiceLifecycle.php`:
```php
<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;

class InvoiceLifecycle
{
    /** sent -> paid. */
    public function markPaid(Invoice $invoice): void
    {
        if ($invoice->status !== 'sent') {
            throw new \DomainException("Only a sent invoice can be marked paid (status: {$invoice->status}).");
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $this->event($invoice, 'paid');
        });
    }

    /** draft|sent -> void; releases linked entries so they can be re-invoiced. */
    public function void(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            throw new \DomainException("Cannot void a {$invoice->status} invoice.");
        }

        DB::transaction(function () use ($invoice) {
            TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->update(['status' => 'void']);
            $this->event($invoice, 'voided');
        });
    }

    private function event(Invoice $invoice, string $kind, array $payload = null): void
    {
        InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'kind' => $kind,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
```
(Note: `markPaid` allows paid only from `sent`. `void` rejects `paid`/`void`. The `kind` enum already includes `paid` and `voided` — see the `create_invoice_events` migration.)

- [ ] **Step 4: Run — expect PASS**

```
host$ ddev artisan test --filter=InvoiceLifecycle
```
Expected: 5 tests PASS.

- [ ] **Step 5: Commit**

```
host$ git add app/Services/Invoicing/InvoiceLifecycle.php tests/Feature/Services/InvoiceLifecycleTest.php
host$ git commit -m "feat(invoices): InvoiceLifecycle markPaid + void (void releases entries)"
```

---

## Task 6: `InvoiceController@index` + `Invoices/Index` page

Replace the placeholder closure with a real controller and the stats+filters+table page (ported from the design's `InvoicesView`, CHF).

**Files:**
- Create: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php`
- Replace: `resources/js/Pages/Invoices/Index.vue`
- Test: `tests/Feature/Http/InvoiceControllerTest.php`

- [ ] **Step 1: Write the failing controller test**

`tests/Feature/Http/InvoiceControllerTest.php`:
```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create(['name' => 'Atlas Robotics', 'short_code' => 'AR']);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'billable' => true, 'rate_rappen' => 14500]);
});

test('GET /invoices renders Invoices/Index with rows + stats + counts', function () {
    $inv = Invoice::factory()->create(['client_id' => $this->client->id, 'number' => '2026-001',
        'status' => 'sent', 'due_on' => now()->addDays(10)->toDateString(), 'total_rappen' => 100_00]);
    InvoiceLine::factory()->create(['invoice_id' => $inv->id, 'hours' => 5.0]);

    $this->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->has('invoices', 1, fn (Assert $r) => $r
                ->where('number', '2026-001')
                ->where('client.name', 'Atlas Robotics')
                ->where('total', 100.0)
                ->where('hours', 5.0)
                ->where('status', 'sent')
                ->where('overdue', false)
                ->etc())
            ->has('stats', fn (Assert $s) => $s
                ->where('outstanding', 100.0)
                ->has('overdue')->has('paid_ytd')->has('avg_days_to_pay')->etc())
            ->has('counts', fn (Assert $c) => $c
                ->where('all', 1)->has('draft')->has('sent')->has('overdue')->has('paid')->etc())
            ->where('filters.filter', 'all'));
});

test('GET /invoices?filter=overdue narrows to past-due sent invoices', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->subDay()->toDateString()]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent', 'due_on' => now()->addDay()->toDateString()]);

    $this->get('/invoices?filter=overdue')
        ->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
});

test('unauthenticated /invoices redirects to login', function () {
    auth()->logout();
    $this->get('/invoices')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceControllerTest
```
Expected: FAIL — route renders the placeholder closure (`component('Invoices/Index')` passes but `invoices`/`stats` props are absent).

- [ ] **Step 3: Create the controller (index only; other methods added in Tasks 7–9)**

`app/Http/Controllers/InvoiceController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceProjections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Invoices/Index', [
            'invoices' => InvoiceProjections::index($filter, $search)->values(),
            'stats'    => InvoiceProjections::stats(),
            'counts'   => [
                'all'     => Invoice::count(),
                'draft'   => Invoice::where('status', 'draft')->count(),
                'sent'    => Invoice::where('status', 'sent')->count(),
                'overdue' => Invoice::where('status', 'sent')->whereDate('due_on', '<', now()->toDateString())->count(),
                'paid'    => Invoice::where('status', 'paid')->count(),
                'void'    => Invoice::where('status', 'void')->count(),
            ],
            'filters'  => ['filter' => $filter, 'q' => $search],
        ]);
    }
}
```

- [ ] **Step 4: Replace the `/invoices` route**

In `routes/web.php`, add the import at the top:
```php
use App\Http\Controllers\InvoiceController;
```
Replace this line:
```php
Route::get('/invoices', fn () => Inertia::render('Invoices/Index'))->name('invoices.index');
```
with:
```php
Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
```

- [ ] **Step 5: Replace `Invoices/Index.vue`**

`resources/js/Pages/Invoices/Index.vue` — port `design/ernte/project/views.jsx` lines 583–677 (`InvoicesView`), consuming real props, CHF money:
```vue
<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sparkline from '@/Components/Sparkline.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoices: { type: Array, required: true },
  stats:    { type: Object, required: true },
  counts:   { type: Object, required: true },
  filters:  { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
const filter = computed(() => props.filters.filter ?? 'all');

function setFilter(f) {
  router.get('/invoices', { filter: f, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}
let t = null;
function onSearch() {
  if (t) clearTimeout(t);
  t = setTimeout(() => router.get('/invoices', { filter: filter.value, q: search.value || undefined }, { preserveState: true, preserveScroll: true }), 250);
}

function fmtMoney(v)      { return 'CHF ' + Number(v).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtMoneyShort(v) { return 'CHF ' + Math.round(v).toLocaleString('de-CH'); }
function fmtDate(d)       { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—'; }

const TABS = computed(() => [
  { id: 'all',     label: 'All',     count: props.counts.all },
  { id: 'draft',   label: 'Draft',   count: props.counts.draft },
  { id: 'sent',    label: 'Sent',    count: props.counts.sent },
  { id: 'overdue', label: 'Overdue', count: props.counts.overdue },
  { id: 'paid',    label: 'Paid',    count: props.counts.paid },
]);
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">~ / invoices</div>
      <h1 class="page-title">
        Invoices
        <span class="meta">{{ counts.all }} total<span class="ascii-dot">·</span>FY {{ new Date().getFullYear() }}</span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/invoices/new" class="btn primary">+ New invoice</Link>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">Outstanding</div>
      <div class="val" style="color: var(--rust)">{{ fmtMoneyShort(stats.outstanding) }}</div>
      <div class="delta">{{ counts.sent }} sent</div>
    </div>
    <div class="stat">
      <div class="label">Overdue</div>
      <div class="val" style="color: var(--red)">{{ fmtMoneyShort(stats.overdue) }}</div>
      <div class="delta down">{{ counts.overdue }} invoice(s)</div>
    </div>
    <div class="stat">
      <div class="label">Paid YTD</div>
      <div class="val">{{ fmtMoneyShort(stats.paid_ytd) }}</div>
    </div>
    <div class="stat">
      <div class="label">Avg days to pay</div>
      <div class="val">{{ stats.avg_days_to_pay ?? '—' }}<span class="unit">days</span></div>
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
          <th class="pad-l" style="width: 140px">Invoice</th>
          <th style="width: 240px">Client</th>
          <th class="num" style="width: 100px">Issued</th>
          <th class="num" style="width: 100px">Due</th>
          <th class="num" style="width: 80px">Hours</th>
          <th class="num" style="width: 140px">Total</th>
          <th style="width: 120px">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="inv in invoices" :key="inv.id" @click="router.visit(`/invoices/${inv.number}`)">
          <td class="pad-l strong">
            <span class="mono-tag" style="padding: 2px 6px; color: var(--ink); border-color: var(--border-strong)">#{{ inv.number }}</span>
          </td>
          <td>{{ inv.client.name }}</td>
          <td class="num">{{ fmtDate(inv.issued_on) }}</td>
          <td class="num" :style="{ color: inv.overdue ? 'var(--red)' : undefined }">{{ fmtDate(inv.due_on) }}</td>
          <td class="num">{{ inv.hours.toFixed(1) }}h</td>
          <td class="num strong">{{ fmtMoney(inv.total) }}</td>
          <td><span class="badge dot" :class="inv.overdue ? 'overdue' : inv.status">{{ inv.overdue ? 'overdue' : inv.status }}</span></td>
        </tr>
        <tr v-if="invoices.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">No invoices match this filter.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

- [ ] **Step 6: Run — expect PASS + build**

```
host$ ddev artisan test --filter=InvoiceControllerTest
host$ ddev npm run build
```
Expected: 3 tests PASS; build clean.

- [ ] **Step 7: Commit**

```
host$ git add app/Http/Controllers/InvoiceController.php routes/web.php resources/js/Pages/Invoices/Index.vue tests/Feature/Http/InvoiceControllerTest.php
host$ git commit -m "feat(invoices): Index controller + stats/filters/table page"
```

---

## Task 7: `create` + `store` + `Invoices/Create` page

The create flow: GET `/invoices/new?client=&project=&from=&to=` shows the period range (default previous calendar month) and a checklist of billable/unbilled entries in range, pre-grouped into editable lines; POST `/invoices` persists via `InvoiceBuilder::createDraft` and redirects to the new draft's detail page. The design has no Create mock — this page is built from spec §6, reusing existing classes + a small scoped style block.

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Create: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Invoices/Create.vue`
- Test: extend `tests/Feature/Http/InvoiceControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/InvoiceControllerTest.php`:
```php
test('GET /invoices/new defaults to previous month and lists billable unbilled entries', function () {
    $prevMonth = now()->subMonthNoOverflow();
    $inRange = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'In range',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(9, 0),
        'ended_at'   => $prevMonth->copy()->startOfMonth()->addDays(5)->setTime(11, 0),
        'billable' => true,
    ]);
    // out of range (this month) — excluded by default period
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'This month',
        'started_at' => now()->startOfMonth()->addDay(), 'ended_at' => now()->startOfMonth()->addDay()->addHour(),
        'billable' => true,
    ]);
    // non-billable — excluded
    TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Internal',
        'started_at' => $prevMonth->copy()->startOfMonth()->addDays(6), 'ended_at' => $prevMonth->copy()->startOfMonth()->addDays(6)->addHour(),
        'billable' => false,
    ]);

    $this->get("/invoices/new?client={$this->client->id}&project={$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('client.id', $this->client->id)
            ->where('project.id', $this->project->id)
            ->has('period.start')->has('period.end')
            ->has('entries', 1, fn (Assert $e) => $e->where('description', 'In range')->etc())
            ->has('suggested_lines', 1, fn (Assert $l) => $l->where('description', 'In range')->where('hours', 2.0)->etc()));
});

test('POST /invoices creates a draft from submitted lines and redirects to its detail', function () {
    $entry = TimeEntry::factory()->create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'description' => 'Work',
        'started_at' => now()->subDays(20), 'ended_at' => now()->subDays(20)->addHours(2), 'billable' => true,
    ]);

    $res = $this->post('/invoices', [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [$entry->id],
        'lines' => [
            ['description' => 'Work', 'hours' => 2.0, 'rate_rappen' => 14500, 'vat_exempt' => false],
        ],
    ]);

    $invoice = Invoice::latest('id')->first();
    $res->assertRedirect("/invoices/{$invoice->number}");
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->total_rappen)->toBe(31349); // 29000 + 8.10%
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
});

test('POST /invoices requires at least one line', function () {
    $this->post('/invoices', [
        'client_id' => $this->client->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'entry_ids' => [],
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceControllerTest
```
Expected: the 3 new tests FAIL (no `create`/`store` + no routes).

- [ ] **Step 3: Create `StoreInvoiceRequest`**

`app/Http/Requests/StoreInvoiceRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; } // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'entry_ids' => 'array',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 4: Add `create` + `store` to `InvoiceController`**

Add these imports to `app/Http/Controllers/InvoiceController.php`:
```php
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
```
Add these methods:
```php
public function create(Request $request, InvoiceBuilder $builder): Response
{
    $client = Client::findOrFail($request->integer('client'));
    $project = $request->filled('project') ? Project::find($request->integer('project')) : null;

    $start = $request->filled('from')
        ? Carbon::parse($request->string('from'))->startOfDay()
        : Carbon::now()->subMonthNoOverflow()->startOfMonth();
    $end = $request->filled('to')
        ? Carbon::parse($request->string('to'))->endOfDay()
        : Carbon::now()->subMonthNoOverflow()->endOfMonth();

    $entries = TimeEntry::query()
        ->with(['project:id,name,code,rate_rappen', 'task:id,name'])
        ->where('billable', true)
        ->whereNull('invoice_id')
        ->whereBetween('started_at', [$start, $end])
        ->when($project, fn ($q) => $q->where('project_id', $project->id),
            fn ($q) => $q->whereIn('project_id', $client->projects()->pluck('id')))
        ->orderBy('started_at')
        ->get();

    return Inertia::render('Invoices/Create', [
        'client' => $client->only('id', 'name', 'short_code'),
        'project' => $project?->only('id', 'name', 'code', 'rate_rappen'),
        'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        'entries' => $entries->map(fn (TimeEntry $e) => [
            'id' => $e->id,
            'description' => $e->description !== '' ? $e->description : ('Task #' . $e->task_id),
            'project' => ['id' => $e->project->id, 'name' => $e->project->name, 'code' => $e->project->code],
            'hours' => round($e->duration_seconds / 3600, 2),
            'started_at' => $e->started_at->toIso8601String(),
            'rate' => (int) round(($e->project->rate_rappen ?? 0) / 100),
        ]),
        'suggested_lines' => collect($builder->suggestLinesFromEntries($entries, $project))
            ->map(fn ($l) => [
                'description' => $l['description'],
                'hours' => $l['hours'],
                'rate' => (int) round($l['rate_rappen'] / 100),
                'rate_rappen' => $l['rate_rappen'],
                'vat_exempt' => $l['vat_exempt'],
                'entry_ids' => $l['entry_ids'],
            ])->values(),
    ]);
}

public function store(StoreInvoiceRequest $request, InvoiceBuilder $builder): RedirectResponse
{
    $data = $request->validated();
    $client = Client::findOrFail($data['client_id']);
    $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

    $invoice = $builder->createDraft(
        client: $client,
        project: $project,
        periodStart: $data['period_start'],
        periodEnd: $data['period_end'],
        lines: $data['lines'],
        entryIds: $data['entry_ids'] ?? [],
    );

    return redirect("/invoices/{$invoice->number}")->with('success', "Draft {$invoice->number} created.");
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, in the `auth` group, add (place above the `/invoices/{invoice:number}` show route you'll add in Task 8 so `new` is not swallowed by the wildcard):
```php
Route::get ('/invoices/new', [InvoiceController::class, 'create'])->name('invoices.create');
Route::post('/invoices',     [InvoiceController::class, 'store'])->name('invoices.store');
```

- [ ] **Step 6: Create `Invoices/Create.vue`**

`resources/js/Pages/Invoices/Create.vue` — built from spec §6; reuses `.page-head`, `.table`, `.field`, `.btn`:
```vue
<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  client: { type: Object, required: true },
  project: { type: Object, default: null },
  period: { type: Object, required: true },
  entries: { type: Array, required: true },
  suggested_lines: { type: Array, required: true },
});

// Period: reload the page with new from/to to re-query eligible entries.
const from = ref(props.period.start);
const to = ref(props.period.end);
function reloadPeriod() {
  router.get('/invoices/new', {
    client: props.client.id,
    project: props.project?.id || undefined,
    from: from.value,
    to: to.value,
  }, { preserveState: false });
}

// Entry checklist: all selected by default.
const selected = reactive(Object.fromEntries(props.entries.map((e) => [e.id, true])));
const selectedIds = computed(() => props.entries.filter((e) => selected[e.id]).map((e) => e.id));

// Editable lines, seeded from the server's suggested grouping.
const lines = ref(props.suggested_lines.map((l, i) => ({
  key: i, description: l.description, hours: l.hours, rate: l.rate, vat_exempt: l.vat_exempt,
})));
let nextKey = props.suggested_lines.length;

function addLine() { lines.value.push({ key: nextKey++, description: '', hours: 0, rate: props.project?.rate_rappen ? Math.round(props.project.rate_rappen / 100) : 0, vat_exempt: false }); }
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

const lineAmount = (l) => Math.round(Number(l.hours) * Number(l.rate) * 100) / 1; // CHF*100 → rappen-ish for display
function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const VAT_RATE = 8.1;
const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * VAT_RATE / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: props.client.id,
    project_id: props.project?.id ?? null,
    period_start: from.value,
    period_end: to.value,
    entry_ids: selectedIds.value,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
    })),
  })).post('/invoices');
}
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">~ / invoices</Link><span class="ascii-dot">/</span><span>new</span>
      </div>
      <h1 class="page-title">
        New invoice
        <span class="meta">{{ client.name }}<span v-if="project" class="ascii-dot">·</span><span v-if="project">{{ project.name }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/invoices" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || lines.length === 0" @click="save">Create draft</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
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
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one or widen the period.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Entries in period</h3>
      <div style="display: flex; gap: 12px; align-items: end; margin-bottom: 12px">
        <label class="field"><span>From</span><input type="date" v-model="from" @change="reloadPeriod" /></label>
        <label class="field"><span>To</span><input type="date" v-model="to" @change="reloadPeriod" /></label>
        <span class="dim" style="font-size: var(--fs-xs)">Changing the period re-queries billable, unbilled entries. Lines above are not auto-updated — edit them to match.</span>
      </div>
      <table class="table">
        <thead><tr><th class="pad-l check"></th><th>Entry</th><th>Project</th><th class="num">Hours</th></tr></thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td class="pad-l check"><input type="checkbox" v-model="selected[e.id]" /></td>
            <td>{{ e.description }}</td>
            <td class="dim">{{ e.project.code }}</td>
            <td class="num">{{ e.hours.toFixed(2) }}h</td>
          </tr>
          <tr v-if="entries.length === 0"><td colspan="4" class="pad-l muted" style="padding: 16px">No billable, unbilled entries in this period.</td></tr>
        </tbody>
      </table>
    </div>

    <aside>
      <h3 class="section-title">Totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ VAT_RATE }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        {{ selectedIds.length }} entr(y/ies) will be attached to this invoice and removed from "unbilled".
        Server recomputes all amounts on save.
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
.field input { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field input:focus { outline: none; border-color: var(--accent); }
</style>
```

- [ ] **Step 7: Run — expect PASS + build**

```
host$ ddev artisan test --filter=InvoiceControllerTest
host$ ddev npm run build
```
Expected: all InvoiceControllerTest tests PASS; build clean.

- [ ] **Step 8: Commit**

```
host$ git add app/Http/Controllers/InvoiceController.php app/Http/Requests/StoreInvoiceRequest.php routes/web.php resources/js/Pages/Invoices/Create.vue tests/Feature/Http/InvoiceControllerTest.php
host$ git commit -m "feat(invoices): create flow — period picker + entry checklist + line editor"
```

---

## Task 8: `show` + `preview` + `update` + `markPaid` + `void` + `Invoices/Show` page

The detail page: a document (iframe to `/preview`, the Blade fragment) + an activity sidebar (from `invoice_events`) + linked entries + actions. `markPaid`/`void` call `InvoiceLifecycle`. `update` allows editing a draft's notes + lines. (`send` and the PDF route land in Task 9 — the Show page's "Send"/"Download PDF" buttons are wired here but the routes are added in Task 9; gate them so tests pass.)

> The `/preview` Blade is the same `resources/views/invoices/pdf.blade.php` created in Task 9. To keep this task shippable before the Blade exists, Step 4 ships a **minimal interim** `preview` that renders an inline document fragment; Task 9 replaces the view path with the real Blade. Mark this clearly in the code with a one-line comment.

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Create: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Invoices/Show.vue`
- Test: extend `tests/Feature/Http/InvoiceControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/InvoiceControllerTest.php`:
```php
use App\Models\InvoiceEvent;
use App\Services\Invoicing\InvoiceBuilder;

function makeDraft(): Invoice
{
    $start = now()->subDays(20);
    $entry = TimeEntry::factory()->create([
        'user_id' => test()->user->id, 'project_id' => test()->project->id, 'description' => 'Work',
        'started_at' => $start, 'ended_at' => (clone $start)->addHours(2), 'billable' => true,
    ]);
    return app(InvoiceBuilder::class)->buildDraftFromEntries(
        test()->client, test()->project, TimeEntry::all(),
        now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()
    );
}

test('GET /invoices/{number} renders Invoices/Show with invoice + lines + events', function () {
    $inv = makeDraft();

    $this->get("/invoices/{$inv->number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Show')
            ->where('invoice.number', $inv->number)
            ->where('invoice.status', 'draft')
            ->has('invoice.lines', 1)
            ->has('events', 1, fn (Assert $e) => $e->where('kind', 'created')->etc())
            ->has('linked_entries')
            ->where('preview_url', "/invoices/{$inv->number}/preview"));
});

test('GET /invoices/{number}/preview returns raw HTML (not Inertia)', function () {
    $inv = makeDraft();
    $res = $this->get("/invoices/{$inv->number}/preview");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/html');
    $res->assertSee($inv->number, false);
});

test('PATCH /invoices/{id} edits a draft notes + lines and recomputes totals', function () {
    $inv = makeDraft();
    $this->patch("/invoices/{$inv->id}", [
        'notes' => 'Thanks for your business.',
        'lines' => [['description' => 'Edited', 'hours' => 1.0, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    ])->assertRedirect("/invoices/{$inv->number}");

    $inv->refresh();
    expect($inv->notes)->toBe('Thanks for your business.');
    expect($inv->lines)->toHaveCount(1);
    expect($inv->subtotal_rappen)->toBe(10000);
    expect($inv->total_rappen)->toBe(10810);
});

test('PATCH is rejected once the invoice is not a draft', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent']);
    $this->patch("/invoices/{$inv->id}", ['notes' => 'x'])->assertStatus(403);
});

test('POST /invoices/{id}/paid marks a sent invoice paid', function () {
    $inv = makeDraft();
    $inv->update(['status' => 'sent', 'issued_on' => now()->subDay(), 'due_on' => now()->addDays(29)]);
    $this->post("/invoices/{$inv->id}/paid")->assertRedirect();
    expect($inv->fresh()->status)->toBe('paid');
});

test('POST /invoices/{id}/void voids and releases entries', function () {
    $inv = makeDraft();
    $this->post("/invoices/{$inv->id}/void")->assertRedirect();
    expect($inv->fresh()->status)->toBe('void');
    expect(TimeEntry::unbilled()->billable()->count())->toBe(1);
});
```

- [ ] **Step 2: Run — expect FAIL**

```
host$ ddev artisan test --filter=InvoiceControllerTest
```
Expected: the 6 new tests FAIL (methods + routes missing).

- [ ] **Step 3: Create `UpdateInvoiceRequest`**

`app/Http/Requests/UpdateInvoiceRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only drafts are editable.
        return $this->route('invoice')->status === 'draft';
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
(`authorize()` returning false yields a 403 — matches the "PATCH rejected once not a draft" test.)

- [ ] **Step 4: Add `show`, `preview`, `update`, `markPaid`, `void` to the controller**

Add imports to `app/Http/Controllers/InvoiceController.php`:
```php
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Invoicing\InvoiceBuilder; // already imported in Task 7
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Http\Response as HttpResponse;
```
Add methods:
```php
public function show(Invoice $invoice): Response
{
    $invoice->load(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order'), 'events' => fn ($q) => $q->orderByDesc('occurred_at')]);

    $linked = $invoice->timeEntries()
        ->selectRaw('COUNT(*) AS n, COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))),0) AS secs')
        ->first();

    return Inertia::render('Invoices/Show', [
        'invoice' => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'overdue' => $invoice->overdue,
            'client' => $invoice->client->only('id', 'name'),
            'project_name' => $invoice->project?->name,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'subtotal' => round($invoice->subtotal_rappen / 100, 2),
            'vat' => round($invoice->vat_rappen / 100, 2),
            'total' => round($invoice->total_rappen / 100, 2),
            'vat_rate' => (float) $invoice->vat_rate,
            'notes' => $invoice->notes,
            'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'description' => $l->description,
                'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                'amount' => round($l->amount_rappen / 100, 2), 'vat_exempt' => (bool) $l->vat_exempt,
            ]),
        ],
        'events' => $invoice->events->map(fn ($e) => [
            'kind' => $e->kind,
            'occurred_at' => $e->occurred_at->toIso8601String(),
            'payload' => $e->payload,
        ]),
        'linked_entries' => ['count' => (int) $linked->n, 'hours' => round(((int) $linked->secs) / 3600, 1)],
        'preview_url' => "/invoices/{$invoice->number}/preview",
        'pdf_url' => "/invoices/{$invoice->number}/pdf",
    ]);
}

public function preview(Invoice $invoice): HttpResponse
{
    $invoice->load(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);
    // Task 9 swaps this for the real `invoices.pdf` Blade once it exists.
    return response()->view('invoices.pdf', [
        'invoice' => $invoice,
        'profile' => \App\Models\BusinessProfile::current(),
        'qrBillHtml' => '', // filled by InvoicePdfRenderer in Task 9
    ]);
}

public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
{
    $data = $request->validated();

    \Illuminate\Support\Facades\DB::transaction(function () use ($data, $invoice) {
        if (array_key_exists('notes', $data)) {
            $invoice->notes = $data['notes'];
        }
        if (! empty($data['lines'])) {
            $invoice->lines()->delete();
            $lineAmounts = []; $vatExempts = []; $sort = 0;
            foreach ($data['lines'] as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);
                $exempt = (bool) ($line['vat_exempt'] ?? false);
                $invoice->lines()->create([
                    'description' => $line['description'], 'hours' => $hours,
                    'rate_rappen' => $rate, 'amount_rappen' => $amount,
                    'vat_exempt' => $exempt, 'sort_order' => $sort++,
                ]);
                $lineAmounts[] = $amount; $vatExempts[] = $exempt;
            }
            $totals = InvoiceBuilder::computeTotals($lineAmounts, $vatExempts, (float) $invoice->vat_rate);
            $invoice->subtotal_rappen = $totals['subtotal_rappen'];
            $invoice->vat_rappen = $totals['vat_rappen'];
            $invoice->total_rappen = $totals['total_rappen'];
        }
        $invoice->save();
    });

    return redirect("/invoices/{$invoice->number}")->with('success', 'Draft updated.');
}

public function markPaid(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
{
    try {
        $lifecycle->markPaid($invoice);
    } catch (\DomainException $e) {
        return back()->with('error', $e->getMessage());
    }
    return back()->with('success', "Invoice {$invoice->number} marked paid.");
}

public function void(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
{
    try {
        $lifecycle->void($invoice);
    } catch (\DomainException $e) {
        return back()->with('error', $e->getMessage());
    }
    return back()->with('success', "Invoice {$invoice->number} voided.");
}
```

- [ ] **Step 5: Create a minimal interim Blade so `preview` resolves**

`resources/views/invoices/pdf.blade.php` (interim — Task 9 replaces the body with the full German/CHF/QR document):
```blade
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Rechnung {{ $invoice->number }}</title></head>
<body>
  <h1>Rechnung #{{ $invoice->number }}</h1>
  <p>{{ $invoice->client->name }}</p>
  <table>
    <tbody>
      @foreach ($invoice->lines as $line)
        <tr><td>{{ $line->description }}</td><td>{{ number_format($line->amount_rappen / 100, 2) }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <p>Total: CHF {{ number_format($invoice->total_rappen / 100, 2) }}</p>
  {!! $qrBillHtml !!}
</body>
</html>
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add (after the `new`/`store` routes from Task 7):
```php
Route::get  ('/invoices/{invoice:number}',          [InvoiceController::class, 'show'])->name('invoices.show');
Route::get  ('/invoices/{invoice:number}/preview',  [InvoiceController::class, 'preview'])->name('invoices.preview');
Route::patch('/invoices/{invoice}',                 [InvoiceController::class, 'update'])->name('invoices.update');
Route::post ('/invoices/{invoice}/paid',            [InvoiceController::class, 'markPaid'])->name('invoices.paid');
Route::post ('/invoices/{invoice}/void',            [InvoiceController::class, 'void'])->name('invoices.void');
```

- [ ] **Step 7: Create `Invoices/Show.vue`**

`resources/js/Pages/Invoices/Show.vue` — ported from `design/ernte/project/views.jsx` lines 695–819 (`InvoiceDetail`), but the document body is the `/preview` iframe (single source of truth) and the sidebar is real `events`/`linked_entries`:
```vue
<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  invoice: { type: Object, required: true },
  events: { type: Array, required: true },
  linked_entries: { type: Object, required: true },
  preview_url: { type: String, required: true },
  pdf_url: { type: String, required: true },
});

const isDraft = computed(() => props.invoice.status === 'draft');
const isSent = computed(() => props.invoice.status === 'sent');
const statusLabel = computed(() => props.invoice.overdue ? 'overdue' : props.invoice.status);

function send()    { router.post(`/invoices/${props.invoice.id}/send`,  {}, { preserveScroll: true }); }
function markPaid(){ router.post(`/invoices/${props.invoice.id}/paid`,  {}, { preserveScroll: true }); }
function voidIt()  { router.post(`/invoices/${props.invoice.id}/void`,  {}, { preserveScroll: true }); }

const EVENT_LABEL = {
  created: 'Created', sent: 'Sent', reminded: 'Reminder sent', paid: 'Marked paid',
  pdf_generated: 'Generated PDF', voided: 'Voided', overdue_stamped: 'Marked overdue',
};
function fmtWhen(iso) { return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
</script>

<template>
  <div class="page-head">
    <div>
      <div class="crumb">
        <Link href="/invoices">~ / invoices</Link><span class="ascii-dot">/</span><span>#{{ invoice.number }}</span>
      </div>
      <h1 class="page-title">
        Invoice #{{ invoice.number }}
        <span class="meta">{{ invoice.client.name }}<span class="ascii-dot">·</span><span class="badge dot" :class="statusLabel">{{ statusLabel }}</span></span>
      </h1>
    </div>
    <div style="display: flex; gap: 8px">
      <a :href="pdf_url" class="btn">Download PDF</a>
      <button v-if="isDraft" class="btn primary" @click="send">Send</button>
      <button v-else-if="isSent" class="btn primary" @click="markPaid">Mark paid</button>
      <button v-if="invoice.status !== 'paid' && invoice.status !== 'void'" class="btn ghost" @click="voidIt">Void</button>
    </div>
  </div>

  <div class="invoice-page">
    <div class="invoice-doc-wrap">
      <iframe :src="preview_url" title="Invoice document" style="width: 100%; height: 1100px; border: 1px solid var(--border); background: #fff"></iframe>
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

      <h3 class="section-title" style="margin-top: 24px">Linked entries</h3>
      <div style="font-size: var(--fs-sm); color: var(--ink-2)">
        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border)">
          <span>{{ linked_entries.hours }}h</span><span class="muted">{{ linked_entries.count }} entries</span>
        </div>
      </div>
    </aside>
  </div>
</template>
```
(Note: the `Send` button posts to `/invoices/{id}/send`, whose route is added in Task 9. Until then, clicking it 404s — acceptable mid-build; Task 9 closes it. The button is only shown for drafts.)

- [ ] **Step 8: Run — expect PASS + build**

```
host$ ddev artisan test --filter=InvoiceControllerTest
host$ ddev npm run build
```
Expected: all InvoiceControllerTest tests PASS; build clean.

- [ ] **Step 9: Commit**

```
host$ git add app/Http/Controllers/InvoiceController.php app/Http/Requests/UpdateInvoiceRequest.php routes/web.php resources/views/invoices/pdf.blade.php resources/js/Pages/Invoices/Show.vue tests/Feature/Http/InvoiceControllerTest.php
host$ git commit -m "feat(invoices): Show page + preview + draft update + markPaid/void actions"
```

---

## Task 9: PDF + Swiss QR-bill + `send`/issue action

Install Browsershot + swiss-qr-bill, build `QrBillRenderer` (SVG payment part) and `InvoicePdfRenderer` (Blade → HTML → cached PDF), write the full German/CHF document Blade, add `InvoiceLifecycle::issue()` (transition draft→sent, stamp dates, render+cache PDF, write `sent` + `pdf_generated` events — **no email**), and wire the `send` + `pdf` controller routes.

**Files:**
- Modify: `composer.json` / `composer.lock` (via `ddev composer require`)
- Create: `app/Services/Invoicing/QrBillRenderer.php`
- Create: `app/Services/Invoicing/InvoicePdfRenderer.php`
- Modify: `app/Services/Invoicing/InvoiceLifecycle.php` (add `issue()`)
- Rewrite: `resources/views/invoices/pdf.blade.php`
- Modify: `app/Http/Controllers/InvoiceController.php` (`preview` uses renderer; add `send`, `pdf`)
- Modify: `routes/web.php`
- Test: `tests/Feature/Services/QrBillRendererTest.php`; extend `InvoiceLifecycleTest`, `InvoiceControllerTest`

- [ ] **Step 1: Install the packages**

```
host$ ddev composer require spatie/browsershot sprain/swiss-qr-bill
host$ ddev composer show spatie/browsershot sprain/swiss-qr-bill | grep -E "name|versions"
```
Expected: both install. Note the resolved `sprain/swiss-qr-bill` major version — the API below targets v4.x. If the class paths differ, the canonical reference is `vendor/sprain/swiss-qr-bill/example/` (run `ls vendor/sprain/swiss-qr-bill/example`).

- [ ] **Step 2: Write the QR renderer test**

`tests/Feature/Services/QrBillRendererTest.php`:
```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Invoicing\QrBillRenderer;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte GmbH', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001',
        'city' => 'Zürich', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        // Sprain library test QR-IBAN (IID in 30000–31999 range):
        'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->client = Client::factory()->create([
        'name' => 'Atlas Robotics', 'address_line_1' => 'Friedrichstrasse 47',
        'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH',
    ]);
});

test('renders a valid QR payment part containing the IBAN and reference', function () {
    $invoice = Invoice::factory()->create([
        'client_id' => $this->client->id, 'number' => '2026-014',
        'total_rappen' => 428_000, 'currency' => 'CHF',
        'qr_reference' => '000000000000000000000000146', // 27 digits (id=14 padded + check via generator in practice)
    ]);

    $html = app(QrBillRenderer::class)->html($invoice);

    expect($html)->toBeString()->not->toBe('');
    // The amount appears formatted; the QRR reference appears grouped or raw somewhere in the slip.
    expect($html)->toContain('CHF');
});

test('uses a plain IBAN with no reference when qr_iban is absent', function () {
    BusinessProfile::current()->update(['qr_iban' => null, 'iban' => 'CH9300762011623852957']);
    $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'total_rappen' => 100_00, 'currency' => 'CHF', 'qr_reference' => null]);

    $html = app(QrBillRenderer::class)->html($invoice);
    expect($html)->toBeString()->not->toBe('');
});
```

- [ ] **Step 3: Create `QrBillRenderer`**

`app/Services/Invoicing/QrBillRenderer.php` (targets `sprain/swiss-qr-bill` v4 API):
```php
<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Sprain\SwissQrBill as QrBill;

class QrBillRenderer
{
    /** Build the QR payment part as an HTML/SVG string for embedding in the invoice document. */
    public function html(Invoice $invoice): string
    {
        $profile = BusinessProfile::current();
        $qrBill = QrBill\QrBill::create();

        // Creditor (us).
        $qrBill->setCreditor(
            QrBill\DataGroup\Element\CombinedAddress::create(
                $profile->name ?: 'Creditor',
                trim(($profile->address_line_1 ?? '') . ' ' . ($profile->address_line_2 ?? '')) ?: '-',
                trim(($profile->postal_code ?? '') . ' ' . ($profile->city ?? '')) ?: '-',
                $profile->country ?: 'CH',
            )
        );

        // QR-IBAN ⇒ QRR reference; plain IBAN ⇒ no reference (NON).
        $useQrIban = ! empty($profile->qr_iban);
        $iban = $useQrIban ? $profile->qr_iban : $profile->iban;
        $qrBill->setCreditorInformation(
            QrBill\DataGroup\Element\CreditorInformation::create($iban ?: 'CH4431999123000889012')
        );

        // Debtor (client).
        $client = $invoice->client;
        $qrBill->setUltimateDebtor(
            QrBill\DataGroup\Element\CombinedAddress::create(
                $client->name,
                trim(($client->address_line_1 ?? '') . ' ' . ($client->address_line_2 ?? '')) ?: '-',
                trim(($client->postal_code ?? '') . ' ' . ($client->city ?? '')) ?: '-',
                $client->country ?: 'CH',
            )
        );

        // Amount.
        $qrBill->setPaymentAmountInformation(
            QrBill\DataGroup\Element\PaymentAmountInformation::create(
                $invoice->currency ?: 'CHF',
                round($invoice->total_rappen / 100, 2),
            )
        );

        // Reference.
        if ($useQrIban && $invoice->qr_reference) {
            $qrBill->setPaymentReference(
                QrBill\DataGroup\Element\PaymentReference::create(
                    QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
                    $invoice->qr_reference,
                )
            );
        } else {
            $qrBill->setPaymentReference(
                QrBill\DataGroup\Element\PaymentReference::create(
                    QrBill\DataGroup\Element\PaymentReference::TYPE_NON,
                    null,
                )
            );
        }

        // Additional info: the invoice number, so the payer can reconcile.
        $qrBill->setAdditionalInformation(
            QrBill\DataGroup\Element\AdditionalInformation::create("Rechnung {$invoice->number}")
        );

        $violations = $qrBill->getViolations();
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $v) { $messages[] = $v->getMessage(); }
            throw new \RuntimeException('Invalid QR bill: ' . implode('; ', $messages));
        }

        $output = new QrBill\PaymentPart\Output\HtmlOutput\HtmlOutput($qrBill, 'de');

        return $output->setPrintable(false)->getPaymentPart();
    }
}
```

- [ ] **Step 4: Run the QR test**

```
host$ ddev artisan test --filter=QrBillRenderer
```
Expected: both tests PASS. If a class path is wrong, fix it against `vendor/sprain/swiss-qr-bill/example/` and re-run.

- [ ] **Step 5: Create `InvoicePdfRenderer`**

`app/Services/Invoicing/InvoicePdfRenderer.php`:
```php
<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class InvoicePdfRenderer
{
    public function __construct(private QrBillRenderer $qr) {}

    /** Render the invoice document to an HTML string (used by /preview and the PDF). */
    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return View::make('invoices.pdf', [
            'invoice' => $invoice,
            'profile' => BusinessProfile::current(),
            'qrBillHtml' => $this->qr->html($invoice),
        ])->render();
    }

    /** Render to a cached PDF on the local disk; returns the storage-relative path. */
    public function pdf(Invoice $invoice): string
    {
        $relative = "invoices/{$invoice->number}.pdf";
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0775, true);

        $shot = Browsershot::html($this->html($invoice))
            ->format('A4')
            ->showBackground()
            ->margins(12, 12, 12, 12);

        if ($path = env('BROWSERSHOT_CHROME_PATH')) {
            $shot->setChromePath($path);
        }

        $shot->save($absolute);

        $invoice->update(['pdf_path' => $relative]);

        return $relative;
    }
}
```

- [ ] **Step 6: Write the full document Blade (German, CHF, QR)**

Rewrite `resources/views/invoices/pdf.blade.php` — A4 document with German labels, CHF money, business profile + client blocks, line table, totals, and the QR payment slip at the bottom. Inline CSS only (Browsershot renders a standalone document):
```blade
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Rechnung {{ $invoice->number }}</title>
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
    .qr { margin-top: 48px; border-top: 1px dashed #999; padding-top: 12px; }
    .foot { margin-top: 28px; font-size: 10px; color: #6b6b6b; }
  </style>
</head>
@php
  $money = fn ($rappen) => 'CHF ' . number_format($rappen / 100, 2, '.', "'");
@endphp
<body>
  <div class="head">
    <div>
      <div class="label">Rechnung</div>
      <h1>#{{ $invoice->number }}</h1>
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
      <div class="label">Rechnung an</div>
      <div style="font-weight: 600">{{ $invoice->client->name }}</div>
      <div style="color: #3d3d3d">{{ $invoice->client->contact_name }}</div>
      <div style="color: #6b6b6b">{{ $invoice->client->address_line_1 }}</div>
      <div style="color: #6b6b6b">{{ $invoice->client->postal_code }} {{ $invoice->client->city }}</div>
    </div>
    <div>
      <div class="label">Ausgestellt</div>
      <div style="font-weight: 600">{{ $invoice->issued_on?->format('d.m.Y') ?? '—' }}</div>
      <div class="label" style="margin-top: 14px">Fällig</div>
      <div style="font-weight: 600">{{ $invoice->due_on?->format('d.m.Y') ?? '—' }}</div>
    </div>
    <div>
      @if ($invoice->project)
        <div class="label">Projekt</div>
        <div style="font-weight: 600">{{ $invoice->project->name }}</div>
      @endif
      <div class="label" style="margin-top: 14px">Periode</div>
      <div style="color: #3d3d3d">{{ $invoice->period_start?->format('d.m.') }} – {{ $invoice->period_end?->format('d.m.Y') }}</div>
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
      @foreach ($invoice->lines as $line)
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
    <div>Zwischensumme</div><div class="v">{{ $money($invoice->subtotal_rappen) }}</div>
    <div>MwSt {{ rtrim(rtrim(number_format((float) $invoice->vat_rate, 2), '0'), '.') }}%</div><div class="v">{{ $money($invoice->vat_rappen) }}</div>
    <div class="grand-l">Total</div><div class="v grand">{{ $money($invoice->total_rappen) }}</div>
  </div>

  @if ($invoice->notes)
    <div class="foot">{{ $invoice->notes }}</div>
  @endif

  <div class="foot">
    <div>Zahlbar innert 30 Tagen.</div>
    @if ($profile->email)<div>{{ $profile->email }}</div>@endif
  </div>

  <div class="qr">
    {!! $qrBillHtml !!}
  </div>
</body>
</html>
```

- [ ] **Step 7: Point `preview` at the renderer**

In `app/Http/Controllers/InvoiceController.php`, replace the interim `preview()` body with:
```php
public function preview(Invoice $invoice, InvoicePdfRenderer $renderer): HttpResponse
{
    return response($renderer->html($invoice))->header('Content-Type', 'text/html');
}
```
Add the import:
```php
use App\Services\Invoicing\InvoicePdfRenderer;
```

- [ ] **Step 8: Add `InvoiceLifecycle::issue()`**

In `app/Services/Invoicing/InvoiceLifecycle.php`, inject the renderer and add `issue()`:
```php
use App\Services\Invoicing\InvoicePdfRenderer;
```
Add a constructor + method:
```php
public function __construct(private InvoicePdfRenderer $pdf) {}

/**
 * draft -> sent: stamp issued/due dates, render + cache the PDF, write events.
 * NOTE: email dispatch is added in Phase 2b-ii — this method intentionally does not mail.
 */
public function issue(Invoice $invoice): void
{
    if ($invoice->status !== 'draft') {
        throw new \DomainException("Only a draft can be sent (status: {$invoice->status}).");
    }

    DB::transaction(function () use ($invoice) {
        $invoice->update([
            'status' => 'sent',
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
            'sent_at' => now(),
        ]);
        $invoice->refresh();

        $path = $this->pdf->pdf($invoice);

        $this->event($invoice, 'pdf_generated', ['path' => $path]);
        $this->event($invoice, 'sent');
    });
}
```

- [ ] **Step 9: Add `send` + `pdf` to the controller**

Add methods to `InvoiceController`:
```php
public function send(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
{
    try {
        $lifecycle->issue($invoice);
    } catch (\DomainException $e) {
        return back()->with('error', $e->getMessage());
    }
    return back()->with('success', "Invoice {$invoice->number} issued.");
}

public function pdf(Invoice $invoice, InvoicePdfRenderer $renderer): \Symfony\Component\HttpFoundation\BinaryFileResponse
{
    $relative = $invoice->pdf_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($invoice->pdf_path)
        ? $invoice->pdf_path
        : $renderer->pdf($invoice);

    return response()->download(
        \Illuminate\Support\Facades\Storage::disk('local')->path($relative),
        "Rechnung-{$invoice->number}.pdf",
    );
}
```
Add the lifecycle import if not present:
```php
use App\Services\Invoicing\InvoiceLifecycle;
```

- [ ] **Step 10: Add routes**

In `routes/web.php`, add:
```php
Route::post('/invoices/{invoice}/send',         [InvoiceController::class, 'send'])->name('invoices.send');
Route::get ('/invoices/{invoice:number}/pdf',   [InvoiceController::class, 'pdf'])->name('invoices.pdf');
```

- [ ] **Step 11: Extend tests for issue + send + pdf**

Append to `tests/Feature/Services/InvoiceLifecycleTest.php`:
```php
test('issue transitions draft -> sent, stamps dates, writes pdf_generated + sent events', function () {
    BusinessProfile::current()->update(['qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    test()->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    [$invoice] = draftWithEntry();

    test()->lifecycle->issue($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('sent');
    expect($invoice->issued_on)->not->toBeNull();
    expect($invoice->due_on?->toDateString())->toBe(now()->addDays(30)->toDateString());
    expect($invoice->pdf_path)->not->toBeNull();
    expect($invoice->events()->where('kind', 'sent')->count())->toBe(1);
    expect($invoice->events()->where('kind', 'pdf_generated')->count())->toBe(1);
})->group('browsershot');

test('issue is rejected unless draft', function () {
    [$invoice] = draftWithEntry();
    $invoice->update(['status' => 'sent']);
    expect(fn () => test()->lifecycle->issue($invoice))->toThrow(\DomainException::class);
});
```
Append to `tests/Feature/Http/InvoiceControllerTest.php`:
```php
test('POST /invoices/{id}/send issues the draft', function () {
    BusinessProfile::current()->update(['qr_iban' => 'CH4431999123000889012', 'address_line_1' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich']);
    $this->client->update(['address_line_1' => 'Friedrichstrasse 47', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
    $inv = makeDraft();
    $this->post("/invoices/{$inv->id}/send")->assertRedirect();
    expect($inv->fresh()->status)->toBe('sent');
})->group('browsershot');
```
(The `browsershot` group lets CI without Chromium exclude these via `--exclude-group=browsershot`; inside DDEV they run.)

- [ ] **Step 12: Run the suite (inside DDEV, Chromium present)**

```
host$ ddev artisan test --filter=QrBillRenderer
host$ ddev artisan test --filter=InvoiceLifecycle
host$ ddev artisan test --filter=InvoiceControllerTest
```
Expected: all PASS, including the `browsershot`-grouped tests (DDEV has Chromium). If a Browsershot test fails with a Chromium-not-found error, confirm `BROWSERSHOT_CHROME_PATH=/usr/bin/chromium` is set in the web container (`ddev exec printenv BROWSERSHOT_CHROME_PATH`).

- [ ] **Step 13: Manual PDF check**

```
host$ ddev npm run dev   # if not already running
```
Seed a profile with a QR-IBAN if the bootstrap one is empty:
```
host$ ddev artisan tinker --execute="App\Models\BusinessProfile::current()->update(['name'=>'Ernte GmbH','address_line_1'=>'Bahnhofstrasse 1','postal_code'=>'8001','city'=>'Zürich','qr_iban'=>'CH4431999123000889012']);"
```
Open an invoice in the browser → "Download PDF". Expected: a one-page A4 PDF with German labels, CHF totals, and a Swiss QR slip at the bottom. Open `/invoices/{number}/preview` directly — same document renders in the browser.

- [ ] **Step 14: Commit**

```
host$ git add composer.json composer.lock app/Services/Invoicing/QrBillRenderer.php app/Services/Invoicing/InvoicePdfRenderer.php app/Services/Invoicing/InvoiceLifecycle.php resources/views/invoices/pdf.blade.php app/Http/Controllers/InvoiceController.php routes/web.php tests/Feature/Services/QrBillRendererTest.php tests/Feature/Services/InvoiceLifecycleTest.php tests/Feature/Http/InvoiceControllerTest.php
host$ git commit -m "feat(invoices): PDF via Browsershot + Swiss QR-bill + issue/send action"
```

---

## Task 10: Wire entry points into the invoice flow

Enable the `+ Invoice` affordances that Phase 2a stubbed, so users can reach `/invoices/new` from a project or client.

**Files:**
- Modify: `resources/js/Pages/Projects/Show.vue`
- Modify: `resources/js/Pages/Clients/Index.vue`

- [ ] **Step 1: Enable the Projects/Show "+ Invoice" button**

In `resources/js/Pages/Projects/Show.vue`, replace the disabled button:
```vue
<button class="btn primary" disabled title="Phase 2b">+ Invoice</button>
```
with a link that carries the client + project:
```vue
<Link :href="`/invoices/new?client=${project.client.id}&project=${project.id}`" class="btn primary">+ Invoice</Link>
```
(`project.client.id` and `project.id` are already in the `project` prop — see `ProjectDetail::payload`. If `project.id` is absent from the payload, add `'id' => $project->id` to that projection; verify with `grep -n "'id'" app/Support/ProjectDetail.php`.)

- [ ] **Step 2: Add a per-row "+ Invoice" affordance to Clients/Index**

In `resources/js/Pages/Clients/Index.vue`, add a trailing actions cell. In `<thead>` add a header:
```vue
<th class="pad-r" style="width: 90px"></th>
```
and in the row, after the Activity `<td>`, add:
```vue
<td class="pad-r">
  <Link :href="`/invoices/new?client=${c.id}`" class="btn ghost" style="padding: 2px 8px" @click.stop>+ Invoice</Link>
</td>
```
(`@click.stop` so it doesn't trigger any row navigation.)

- [ ] **Step 3: Build + manual click-through**

```
host$ ddev npm run build
```
Visit `/projects/{code}` → click "+ Invoice" → lands on `/invoices/new` pre-filled with that client + project, previous month's entries listed. From `/clients` → a row's "+ Invoice" → `/invoices/new` pre-filled with the client (no project). Create a draft → redirected to the new invoice's detail.

- [ ] **Step 4: Full suite + commit**

```
host$ ddev artisan test
host$ git add resources/js/Pages/Projects/Show.vue resources/js/Pages/Clients/Index.vue
host$ git commit -m "feat(invoices): wire + Invoice entry points from Projects/Show + Clients/Index"
```

---

## Task 11: Demo invoices seeder + full verification

Seed a handful of invoices across statuses so the Index/Show pages have realistic content for manual review, then run the whole suite + a build.

**Files:**
- Modify: `database/seeders/DemoFixturesSeeder.php`

- [ ] **Step 1: Append invoice fixtures to the seeder**

At the end of the `DB::transaction(...)` closure in `database/seeders/DemoFixturesSeeder.php` (after the entries loop), add a block that creates one draft from real unbilled entries plus a couple of issued/paid invoices via the builder + lifecycle. Add the imports at the top of the file:
```php
use App\Models\BusinessProfile;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;
```
Then, after the entries loop and still inside the transaction:
```php
            // A business profile is required by the invoice builder.
            BusinessProfile::firstOrCreate(['id' => 1], [
                'name' => 'Ernte GmbH', 'address_line_1' => 'Bahnhofstrasse 1',
                'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH',
                'qr_iban' => 'CH4431999123000889012',
                'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'reminder_days_after_due' => 7,
            ]);

            if (Invoice::count() === 0) {
                $atlas = Client::where('short_code', 'AR')->first();
                $fleet = Project::where('code', 'ATLS-FLT')->first();

                // Backdated billable entries to invoice from.
                for ($i = 0; $i < 6; $i++) {
                    TimeEntry::create([
                        'user_id' => $user->id, 'project_id' => $fleet->id,
                        'description' => ['Map mode', 'Telemetry side-panel', 'Operator permissions'][$i % 3],
                        'started_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays($i + 1)->setTime(9, 0),
                        'ended_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays($i + 1)->setTime(13, 0),
                        'billable' => true,
                    ]);
                }

                $builder = app(InvoiceBuilder::class);
                $lifecycle = app(InvoiceLifecycle::class);

                // One draft.
                $builder->buildDraftFromEntries(
                    $atlas, $fleet, TimeEntry::where('project_id', $fleet->id)->whereNull('invoice_id')->where('billable', true)->get(),
                    Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                    Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                );

                // One issued (sent) invoice — skip PDF render in seeding to avoid Chromium dependency.
                $sent = $builder->createDraft(
                    $atlas, $fleet,
                    Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
                    Carbon::now()->subMonths(2)->endOfMonth()->toDateString(),
                    [['description' => 'Sprint work', 'hours' => 40, 'rate_rappen' => 14500, 'vat_exempt' => false]],
                    [],
                );
                $sent->update(['status' => 'sent', 'issued_on' => Carbon::now()->subDays(40)->toDateString(), 'due_on' => Carbon::now()->subDays(10)->toDateString(), 'sent_at' => Carbon::now()->subDays(40)]);
            }
```
(Seeding deliberately does **not** call `lifecycle->issue()` — that would invoke Browsershot. The `$lifecycle` import is left for parity but the sent invoice is stamped directly; remove the unused import if your linter complains.)

- [ ] **Step 2: Re-seed and verify**

```
host$ ddev artisan migrate:fresh --seed
host$ ddev artisan db:seed --class=DemoFixturesSeeder
host$ ddev npm run dev
```
Visit `/invoices`. Expected: the stats strip shows non-zero Outstanding, the table lists a draft + a sent (overdue) invoice, filters narrow correctly, and clicking a row opens the document + activity sidebar. `/clients` and `/projects` now show non-zero Outstanding columns.

- [ ] **Step 3: Whole suite + build**

```
host$ ddev artisan test
host$ ddev npm run build
```
Expected: every test passes (Phase 2a baseline + all 2b-i additions), build clean.

- [ ] **Step 4: Commit**

```
host$ git add database/seeders/DemoFixturesSeeder.php
host$ git commit -m "chore(seed): demo invoices (draft + sent) + business profile"
```

---

## Task 12: Update the carryover for Phase 2b-ii

Record what 2b-i shipped and refine what's left, so the 2b-ii plan starts from accurate ground.

**Files:**
- Modify: `docs/superpowers/phase-2b-carryover.md`

- [ ] **Step 1: Edit the carryover**

Update `docs/superpowers/phase-2b-carryover.md`:
- Mark **done in 2b-i:** `Client::invoices()`; `Invoice` void (entry-clearing) + markPaid; `InvoiceBuilder` suggest/create split with per-line `vat_exempt`; outstanding/paid projections wired (no longer hardcoded `0`); Invoices Index/Create/Show; PDF via Browsershot; Swiss QR-bill; CSS port completed (Discovery #1).
- Re-scope **2b-ii:** email send (`InvoiceMail` + `emails` queue, dispatched inside `InvoiceLifecycle::issue()`), reminder job (`ernte:invoices:remind`), daily overdue-stamp job, Settings/Profile, Reports placeholder, ⌘K palette + `/api/search`, keyboard shortcuts, backup command.
- Add **new known-pending items:** (a) the `€ → CHF` localization sweep still applies to Projects/Clients/Timer pages (invoice pages + PDF are CHF, the rest are not); (b) `sprain/swiss-qr-bill` SCOR reference for the plain-IBAN case is stubbed as `NON` — implement SCOR if a non-QR-IBAN profile is used in anger; (c) PDF re-render on draft edit (spec §9 #7 "PDF determinism") — currently `pdf_path` is cached at issue time and the `pdf` route re-renders only if the file is missing; decide whether editing a draft (which can't happen post-`sent`) needs invalidation (it doesn't today, since only drafts are editable and drafts have no cached PDF).

- [ ] **Step 2: Commit**

```
host$ git add docs/superpowers/phase-2b-carryover.md
host$ git commit -m "docs: carryover updates after Phase 2b-i (invoices core)"
```

---

## Self-review (run against the spec before handing off for execution)

**Spec coverage (§6–7):**
- §6 Numbering — `InvoiceNumberer` (existing) used by `createDraft`; gapless within year, void doesn't free number (Task 5 test). ✓
- §6 Create from entries — Task 7 GET `/invoices/new` (period default previous month, billable+unbilled checklist, grouping into editable lines), POST `/invoices`, entries attached, draft status. ✓
- §6 VAT — per-invoice `vat_rate` stamped from profile (existing `createDraft`), per-line `vat_exempt` (Task 4), `computeTotals` (existing). ✓
- §6 PDF — Blade `invoices/pdf.blade.php` reused on-screen via `/preview` iframe + Browsershot (Task 9). ✓
- §6 QR-Rechnung — `QrBillRenderer` (Task 9), QRR when qr_iban else NON, embedded SVG. ✓ (SCOR for plain IBAN deferred — noted in Task 12.)
- §6 Statuses — draft (editable, Task 8 update), draft→sent (issue, Task 9, **no email** — deferred), sent→paid (Task 5/8), void releases entries (Task 5/8), overdue computed (existing accessor + Index virtual filter). ✓
- §6 Sending — issue renders PDF + writes events; **email is 2b-ii** (called out in Goal + Task 12). ✓ (scoped out by design)
- §6 Reminders — **2b-ii.** (scoped out)
- §7 UX — Index stats/filters/table (Task 6, ported from `InvoicesView`); Show document + activity sidebar + linked entries (Task 8, ported from `InvoiceDetail`); Create built from spec (Task 7). ⌘K palette + keyboard map are **2b-ii.** ✓
- §9 domain rules — #2 money math (`computeTotals`, server recompute), #4 entry attachment (void test), #5 QR uniqueness (unique column + generator, builder test), #6 VAT stamping (existing builder test). #7 PDF determinism noted in Task 12. ✓

**Placeholder scan:** No "TBD"/"add error handling"/"similar to Task N". The CSS task cites in-repo canonical source line ranges (project convention, not a placeholder). The interim Blade in Task 8 is explicitly replaced in Task 9. The QR API is concrete with a fallback pointer to `vendor/.../example`.

**Type/name consistency:** `InvoiceBuilder::suggestLinesFromEntries()` / `createDraft()` / `buildDraftFromEntries()` used consistently across Tasks 4/7/8/11. `InvoiceLifecycle::issue()`/`markPaid()`/`void()` consistent across Tasks 5/8/9. `InvoicePdfRenderer::html()`/`pdf()` consistent across Tasks 8/9. `InvoiceProjections::index()`/`stats()`/`outstandingByClient()` consistent across Tasks 3/6. Route names (`invoices.*`) and paths consistent.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-28-ernte-phase-2b-i-invoices.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using `superpowers:executing-plans`, batched with checkpoints.

**Which approach?**
