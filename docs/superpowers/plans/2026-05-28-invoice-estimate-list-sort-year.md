# Invoice & Estimate Lists: Newest-First + Year Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sort the Invoices and Estimates lists newest-first by document date, and show the year in the date columns.

**Architecture:** Change each projection's `index()` ordering to `COALESCE(issued_on, created_at) DESC` (with `id DESC` tiebreaker). Extract the duplicated inline `fmtDate` into a shared `resources/js/formatters/date.js` that includes the year, and wire both list pages to it.

**Tech Stack:** Laravel 12, Inertia v2, Vue 3, MariaDB (DDEV), Pest. Tests: `ddev artisan test`. Build: `ddev npm run build`.

**Spec:** `docs/superpowers/specs/2026-05-28-invoice-estimate-list-sort-year-design.md`

---

## File Structure

- **Modify** `app/Support/InvoiceProjections.php` — `index()` ordering.
- **Modify** `app/Support/EstimateProjections.php` — `index()` ordering.
- **Create** `resources/js/formatters/date.js` — shared `fmtDate(d)` with year.
- **Modify** `resources/js/Pages/Invoices/Index.vue` — use shared formatter, widen date columns.
- **Modify** `resources/js/Pages/Estimates/Index.vue` — same.
- **Tests** in `tests/Feature/Support/InvoiceProjectionsTest.php` and `tests/Feature/Support/EstimateProjectionsTest.php`.

---

## Task 1: Invoice list sorts newest-first by document date

**Files:**
- Modify: `app/Support/InvoiceProjections.php`
- Test: `tests/Feature/Support/InvoiceProjectionsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Support/InvoiceProjectionsTest.php` (the file already imports `Invoice`, `Client`, `InvoiceProjections` and sets `$this->client` in `beforeEach`):

```php
test('index orders by document date (issued_on; drafts by created_at) newest first', function () {
    // Insert so id order differs from date order: 2026 first (id 1), then 2025 (id 2),
    // then a draft with no issue date (id 3, created now).
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2026-001', 'issued_on' => '2026-01-01', 'total_rappen' => 100_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'number' => '2025-001', 'issued_on' => '2025-01-01', 'total_rappen' => 100_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'number' => '2026-900', 'issued_on' => null, 'total_rappen' => 100_00]);

    $rows = InvoiceProjections::index('all');

    // draft (created now) first, then 2026-issued, then 2025-issued.
    expect($rows->pluck('number')->all())->toBe(['2026-900', '2026-001', '2025-001']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="index orders by document date" tests/Feature/Support/InvoiceProjectionsTest.php`
Expected: FAIL — current `orderByDesc('id')` yields `['2026-900', '2025-001', '2026-001']`.

- [ ] **Step 3: Change the ordering**

In `app/Support/InvoiceProjections.php`, change the final return's ordering. Replace:

```php
        return $q->orderByDesc('id')->get()->map(fn (Invoice $i) => [
```

with:

```php
        return $q->orderByRaw('COALESCE(issued_on, created_at) DESC')
            ->orderByDesc('id')
            ->get()->map(fn (Invoice $i) => [
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter="index orders by document date" tests/Feature/Support/InvoiceProjectionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/InvoiceProjections.php tests/Feature/Support/InvoiceProjectionsTest.php
git commit -m "feat(invoices): sort list by document date newest-first"
```

---

## Task 2: Estimate list sorts newest-first by document date

**Files:**
- Modify: `app/Support/EstimateProjections.php`
- Test: `tests/Feature/Support/EstimateProjectionsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Support/EstimateProjectionsTest.php` (the file already imports `Estimate`, `Client`, `EstimateProjections` and sets `$this->client` in `beforeEach`):

```php
test('index orders by document date (issued_on; drafts by created_at) newest first', function () {
    Estimate::factory()->sent()->create(['client_id' => $this->client->id,
        'number' => 'OF-2026-001', 'issued_on' => '2026-01-01', 'total_rappen' => 100_00]);
    Estimate::factory()->sent()->create(['client_id' => $this->client->id,
        'number' => 'OF-2025-001', 'issued_on' => '2025-01-01', 'total_rappen' => 100_00]);
    Estimate::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'number' => 'OF-2026-900', 'issued_on' => null, 'total_rappen' => 100_00]);

    $rows = EstimateProjections::index('all');

    expect($rows->pluck('number')->all())->toBe(['OF-2026-900', 'OF-2026-001', 'OF-2025-001']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter="index orders by document date" tests/Feature/Support/EstimateProjectionsTest.php`
Expected: FAIL — current `orderByDesc('id')` yields `['OF-2026-900', 'OF-2025-001', 'OF-2026-001']`.

- [ ] **Step 3: Change the ordering**

In `app/Support/EstimateProjections.php`, replace:

```php
        return $q->orderByDesc('id')->get()->map(fn (Estimate $e) => [
```

with:

```php
        return $q->orderByRaw('COALESCE(issued_on, created_at) DESC')
            ->orderByDesc('id')
            ->get()->map(fn (Estimate $e) => [
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter="index orders by document date" tests/Feature/Support/EstimateProjectionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/EstimateProjections.php tests/Feature/Support/EstimateProjectionsTest.php
git commit -m "feat(estimates): sort list by document date newest-first"
```

---

## Task 3: Shared date formatter with year + wire both list pages

**Files:**
- Create: `resources/js/formatters/date.js`
- Modify: `resources/js/Pages/Invoices/Index.vue`
- Modify: `resources/js/Pages/Estimates/Index.vue`

No JS test runner exists; verify via build + manual QA.

- [ ] **Step 1: Create the shared formatter**

Create `resources/js/formatters/date.js` with exactly:

```js
// Short date with year, e.g. "28 May 2026". Returns an em dash for null/empty.
export function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}
```

- [ ] **Step 2: `Invoices/Index.vue` — import the shared formatter**

Add the import after the `AppLayout` import line:

```js
import { fmtDate } from '@/formatters/date.js';
```

Delete the local definition (line ~29):

```js
function fmtDate(d)       { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—'; }
```

- [ ] **Step 3: `Invoices/Index.vue` — widen the date columns**

Replace the two date header cells:

```html
          <th class="num" style="width: 100px">Issued</th>
          <th class="num" style="width: 100px">Due</th>
```

with:

```html
          <th class="num" style="width: 120px">Issued</th>
          <th class="num" style="width: 120px">Due</th>
```

- [ ] **Step 4: `Estimates/Index.vue` — import the shared formatter**

Add the import after the `AppLayout` import line:

```js
import { fmtDate } from '@/formatters/date.js';
```

Delete the local definition (line ~29):

```js
function fmtDate(d)       { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—'; }
```

- [ ] **Step 5: `Estimates/Index.vue` — widen the date columns**

Replace the two date header cells:

```html
          <th class="num" style="width: 100px">Issued</th>
          <th class="num" style="width: 100px">Valid until</th>
```

with:

```html
          <th class="num" style="width: 120px">Issued</th>
          <th class="num" style="width: 120px">Valid until</th>
```

- [ ] **Step 6: Build to verify it compiles**

Run: `ddev npm run build`
Expected: clean build, no errors.

- [ ] **Step 7: Manual browser check**

Open the Invoices list and the Estimates list. Confirm: dates now read like `"28 May 2026"` (with year) and don't wrap; rows are ordered newest document-date first with drafts at the top; the year is visible in both the number column and the date columns.

- [ ] **Step 8: Commit**

```bash
git add resources/js/formatters/date.js resources/js/Pages/Invoices/Index.vue resources/js/Pages/Estimates/Index.vue
git commit -m "feat(lists): show year in invoice/estimate date columns via shared formatter"
```

---

## Task 4: Full verification

- [ ] **Step 1: Run the full suite**

Run: `ddev artisan test`
Expected: all pass (prior baseline 295; this plan adds 2 tests).

- [ ] **Step 2: Build assets**

Run: `ddev npm run build`
Expected: clean build.

---

## Self-Review Notes

- **Spec coverage:** invoice sort + test (Task 1), estimate sort + test (Task 2), shared `fmtDate` with year + both pages wired + column widths (Task 3), verification (Task 4). All spec sections mapped.
- **Ordering tests are discriminating:** the chosen insert order makes `orderByDesc('id')` produce a different sequence than `COALESCE(issued_on, created_at) DESC`, so the test genuinely fails before the change and passes after (not a tautology). The draft's `created_at` (now) exceeds the 2026/2025 issue dates, so it sorts first.
- **Name consistency:** the shared export is `fmtDate(d)` — the exact name both pages already call in their templates, so only the definition source changes (import vs local), not the call sites.
- **No placeholders:** every step shows concrete code/commands.
