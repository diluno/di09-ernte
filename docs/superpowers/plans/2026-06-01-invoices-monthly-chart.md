# Invoices Monthly Chart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a stacked monthly bar chart (Open vs Paid amounts per issue month) with chart-only prev/next year navigation to the Invoices index page.

**Architecture:** A new `InvoiceProjections::monthlyIssued($year)` aggregation feeds an additive `invoiceChart` Inertia prop on `InvoiceController@index` (driven by a `chart_year` query param). A presentational hand-rolled SVG component `InvoiceBarChart.vue` renders it; year navigation is an Inertia partial reload (`only: ['invoiceChart']`) so the table/filter state is untouched.

**Tech Stack:** Laravel + Inertia + Vue 3 (`<script setup>`), hand-rolled inline SVG (no charting library, follows `Sparkline.vue`), Pest. Run artisan/npm via `ddev`.

---

## Context the engineer needs

- Invoice statuses: `draft`, `sent`, `paid`, `void`. **Paid** = `paid`; **Open** = `sent` (unpaid; overdue is just sent-past-due, so it folds into Open). `draft`/`void` excluded.
- Amounts are stored in **rappen** (`total_rappen`); divide by 100 for CHF. Bucket by `issued_on`.
- `InvoiceProjections` (`app/Support/InvoiceProjections.php`) is the aggregation home; it already imports `App\Models\Invoice` and `Illuminate\Support\Carbon`.
- The Vue charting pattern is hand-rolled SVG in computed properties — see `resources/js/Components/Sparkline.vue`. There is **no JS test harness**; Vue is verified by `ddev exec npm run build` + manual check. Data correctness is covered by backend Pest tests.
- Money formatting helper: `import { formatChf } from '@/formatters/money.js'` → `formatChf(25000)` ⇒ `"CHF25'000"` (0 fraction digits; Swiss `'` separator).
- The Invoices page is laid out as horizontal **bands**: `.stats` (full-bleed, `border-bottom`), then `.filter-row` (padding `10px 28px`, `border-bottom`), then the table. The chart becomes a new band between `.stats` and `.filter-row`.
- Invoice factory states: `Invoice::factory()->create([...])` with explicit `status`, `issued_on`, `total_rappen` (see existing tests in `tests/Feature/Support/InvoiceProjectionsTest.php`). Tests there set `$this->client` in `beforeEach`.

## File structure

- **Modify** `app/Support/InvoiceProjections.php` — add `monthlyIssued(int $year): array`.
- **Modify** `tests/Feature/Support/InvoiceProjectionsTest.php` — add `monthlyIssued` tests.
- **Modify** `app/Http/Controllers/InvoiceController.php` — add `chart_year` read + `invoiceChart` prop.
- **Modify** `tests/Feature/Http/InvoiceControllerTest.php` — assert the `invoiceChart` prop.
- **Modify** `resources/js/Components/Icon.vue` — add `arrow-left` / `arrow-right`.
- **Create** `resources/js/Components/InvoiceBarChart.vue` — the SVG chart (presentational).
- **Modify** `resources/js/Pages/Invoices/Index.vue` — accept prop, render the chart band, handle year nav.
- **Modify** `resources/css/base.css` — `.ibc` band styles (or scope them in the component; this plan scopes them in the component, so base.css is left unchanged).

---

## Task 1: `InvoiceProjections::monthlyIssued()`

Aggregate issued amounts per month, split into open/paid, with year bounds.

**Files:**
- Modify: `app/Support/InvoiceProjections.php`
- Test: `tests/Feature/Support/InvoiceProjectionsTest.php`

- [ ] **Step 1: Write the failing tests**

Append these tests to `tests/Feature/Support/InvoiceProjectionsTest.php` (the file already has `use App\Support\InvoiceProjections;`, `use App\Models\Invoice;`, and a `beforeEach` setting `$this->client`):

```php
test('monthlyIssued buckets sent into open and paid into paid by issue month, in CHF', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2026-01-10', 'total_rappen' => 24_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-01-20', 'due_on' => '2026-02-19', 'total_rappen' => 5_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-04-03', 'due_on' => '2026-05-03', 'total_rappen' => 13_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);

    expect($chart['months'])->toHaveCount(12);
    expect($chart['months'][0])->toBe(['label' => 'Jan', 'open' => 5000.0, 'paid' => 24000.0]);
    expect($chart['months'][1])->toBe(['label' => 'Feb', 'open' => 0.0, 'paid' => 0.0]);
    expect($chart['months'][3])->toBe(['label' => 'Apr', 'open' => 13000.0, 'paid' => 0.0]);
});

test('monthlyIssued excludes draft and void, and buckets by issued_on not paid_at', function () {
    // Paid in Feb (paid_at) but ISSUED in Jan -> counts in January's paid segment.
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2026-01-15', 'paid_at' => '2026-02-15', 'total_rappen' => 9_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'draft',
        'issued_on' => '2026-03-01', 'total_rappen' => 1_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'void',
        'issued_on' => '2026-03-01', 'total_rappen' => 1_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);

    expect($chart['months'][0]['paid'])->toBe(9000.0);
    expect($chart['months'][2])->toBe(['label' => 'Mar', 'open' => 0.0, 'paid' => 0.0]);
});

test('monthlyIssued respects the requested year and reports min/max year bounds', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => '2024-06-01', 'total_rappen' => 1_000_00]);
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'sent',
        'issued_on' => '2026-06-01', 'due_on' => '2026-07-01', 'total_rappen' => 2_000_00]);

    $chart = InvoiceProjections::monthlyIssued(2026);
    expect($chart['months'][5])->toBe(['label' => 'Jun', 'open' => 2000.0, 'paid' => 0.0]);
    expect($chart['min_year'])->toBe(2024);
    expect($chart['max_year'])->toBe((int) now()->year);

    $empty = InvoiceProjections::monthlyIssued(2025);
    expect(collect($empty['months'])->sum('open'))->toBe(0.0);
    expect(collect($empty['months'])->sum('paid'))->toBe(0.0);
});

test('monthlyIssued falls min_year back to the requested year when there are no invoices', function () {
    $chart = InvoiceProjections::monthlyIssued(2026);
    expect($chart['min_year'])->toBe(2026);
    expect($chart['max_year'])->toBe((int) now()->year);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php artisan test tests/Feature/Support/InvoiceProjectionsTest.php`
Expected: FAIL with `Call to undefined method App\Support\InvoiceProjections::monthlyIssued()`.

- [ ] **Step 3: Implement `monthlyIssued`**

Add this method to `app/Support/InvoiceProjections.php` (e.g. after `stats()`):

```php
    /**
     * Stacked monthly totals (CHF) for invoices issued in $year, split open vs paid,
     * plus the navigable year bounds. Used by the Invoices index chart.
     *
     * open = status 'sent' (unpaid; overdue folds in); paid = status 'paid'.
     * draft/void are excluded. Bucketed by issued_on month.
     */
    public static function monthlyIssued(int $year): array
    {
        $rows = Invoice::query()
            ->whereIn('status', ['sent', 'paid'])
            ->whereYear('issued_on', $year)
            ->selectRaw('MONTH(issued_on) AS m, status, SUM(total_rappen) AS cents')
            ->groupBy('m', 'status')
            ->get();

        $open = array_fill(1, 12, 0);
        $paid = array_fill(1, 12, 0);
        foreach ($rows as $r) {
            $month = (int) $r->m;
            if ($r->status === 'paid') {
                $paid[$month] += (int) $r->cents;
            } else {
                $open[$month] += (int) $r->cents;
            }
        }

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = [
                'label' => $labels[$m - 1],
                'open' => round($open[$m] / 100, 2),
                'paid' => round($paid[$m] / 100, 2),
            ];
        }

        $minIssuedYear = Invoice::query()
            ->whereIn('status', ['sent', 'paid'])
            ->whereNotNull('issued_on')
            ->selectRaw('MIN(YEAR(issued_on)) AS y')
            ->value('y');

        $currentYear = (int) Carbon::now()->year;

        return [
            'year' => $year,
            'min_year' => $minIssuedYear !== null ? (int) $minIssuedYear : $year,
            'max_year' => $currentYear,
            'months' => $months,
        ];
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `ddev exec php artisan test tests/Feature/Support/InvoiceProjectionsTest.php`
Expected: PASS (all tests in the file, including the 4 new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Support/InvoiceProjections.php tests/Feature/Support/InvoiceProjectionsTest.php
git commit -m "feat(invoices): monthlyIssued aggregation for the issued chart"
```

---

## Task 2: Controller `invoiceChart` prop

Expose the aggregation as an additive Inertia prop driven by `chart_year`.

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php:29-47`
- Test: `tests/Feature/Http/InvoiceControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Add these tests to `tests/Feature/Http/InvoiceControllerTest.php` (it already imports `App\Models\Invoice`, `Inertia\Testing\AssertableInertia as Assert`, and a `beforeEach` setting `$this->client`):

```php
test('GET /invoices passes an invoiceChart prop for the current year', function () {
    Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'paid',
        'issued_on' => now()->startOfYear()->toDateString(), 'total_rappen' => 12_000_00]);

    $this->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoiceChart.year', (int) now()->year)
            ->where('invoiceChart.max_year', (int) now()->year)
            ->has('invoiceChart.months', 12)
            ->etc()
        );
});

test('GET /invoices?chart_year=2025 returns chart data for that year', function () {
    $this->get('/invoices?chart_year=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoiceChart.year', 2025)
            ->etc()
        );
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php artisan test tests/Feature/Http/InvoiceControllerTest.php`
Expected: FAIL — `invoiceChart` prop does not exist.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/InvoiceController.php`, replace the `index` method body (lines 29-47) with:

```php
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;
        $chartYear = $request->integer('chart_year', now()->year);

        return Inertia::render('Invoices/Index', [
            'invoices' => InvoiceProjections::index($filter, $search),
            'stats' => InvoiceProjections::stats(),
            'counts' => [
                'all' => Invoice::count(),
                'draft' => Invoice::where('status', 'draft')->count(),
                'sent' => Invoice::where('status', 'sent')->count(),
                'overdue' => Invoice::where('status', 'sent')->whereDate('due_on', '<', now()->toDateString())->count(),
                'paid' => Invoice::where('status', 'paid')->count(),
                'void' => Invoice::where('status', 'void')->count(),
            ],
            'filters' => ['filter' => $filter, 'q' => $search],
            'invoiceChart' => InvoiceProjections::monthlyIssued($chartYear),
        ]);
    }
```

(`$request->integer()` returns an int and applies the default when the param is absent.)

- [ ] **Step 4: Run to verify they pass**

Run: `ddev exec php artisan test tests/Feature/Http/InvoiceControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php tests/Feature/Http/InvoiceControllerTest.php
git commit -m "feat(invoices): invoiceChart prop on the index page"
```

---

## Task 3: `InvoiceBarChart.vue` component

Presentational SVG chart with year nav and legend. Also adds two arrow icons.

**Files:**
- Modify: `resources/js/Components/Icon.vue`
- Create: `resources/js/Components/InvoiceBarChart.vue`

- [ ] **Step 1: Add arrow icons to the Icon map**

In `resources/js/Components/Icon.vue`, add two imports after `import IconLeaf from '~icons/pixelarticons/leaf';`:

```js
import IconArrowLeft from '~icons/pixelarticons/arrow-left';
import IconArrowRight from '~icons/pixelarticons/arrow-right';
```

And add two entries to the `MAP` object after `leaf: IconLeaf,`:

```js
  'arrow-left': IconArrowLeft,
  'arrow-right': IconArrowRight,
```

- [ ] **Step 2: Create the component**

Create `resources/js/Components/InvoiceBarChart.vue` with exactly:

```vue
<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import { formatChf } from '@/formatters/money.js';

const props = defineProps({
  year:    { type: Number, required: true },
  minYear: { type: Number, required: true },
  maxYear: { type: Number, required: true },
  months:  { type: Array,  required: true }, // [{ label, open, paid }]
});
const emit = defineEmits(['update:year']);

// SVG coordinate space (scaled responsively via viewBox).
const W = 1000, H = 260;
const PAD_L = 82, PAD_R = 16, PAD_T = 12, PAD_B = 26;
const plotW = W - PAD_L - PAD_R;
const plotH = H - PAD_T - PAD_B;
const baseline = H - PAD_B;

// Round an axis max up to a "nice" value giving ~targetLines gridlines.
function niceScale(value, targetLines = 5) {
  if (value <= 0) return { max: 1000, step: 250 };
  const raw = value / targetLines;
  const pow = Math.pow(10, Math.floor(Math.log10(raw)));
  let step = 10 * pow;
  for (const c of [1, 2, 2.5, 5]) {
    if (c * pow >= raw) { step = c * pow; break; }
  }
  return { max: Math.ceil(value / step) * step, step };
}

const maxTotal = computed(() => Math.max(0, ...props.months.map((m) => m.open + m.paid)));
const scale = computed(() => niceScale(maxTotal.value));

const gridlines = computed(() => {
  const { max, step } = scale.value;
  const out = [];
  for (let v = step; v <= max + 0.5; v += step) {
    out.push({ value: v, y: baseline - (v / max) * plotH });
  }
  return out;
});

const slot = computed(() => plotW / 12);
const barW = computed(() => Math.min(46, slot.value * 0.5));

const bars = computed(() => props.months.map((m, i) => {
  const cx = PAD_L + slot.value * i + slot.value / 2;
  const paidH = (m.paid / scale.value.max) * plotH;
  const openH = (m.open / scale.value.max) * plotH;
  return {
    label: m.label,
    cx,
    x: cx - barW.value / 2,
    paid: { y: baseline - paidH, h: paidH },
    open: { y: baseline - paidH - openH, h: openH },
  };
}));

const canPrev = computed(() => props.year > props.minYear);
const canNext = computed(() => props.year < props.maxYear);
function prev() { if (canPrev.value) emit('update:year', props.year - 1); }
function next() { if (canNext.value) emit('update:year', props.year + 1); }
</script>

<template>
  <section class="ibc">
    <header class="ibc__head">
      <div class="ibc__nav">
        <button class="btn ibc__arrow" :disabled="!canPrev" aria-label="Previous year" @click="prev"><Icon name="arrow-left" /></button>
        <button class="btn ibc__arrow" :disabled="!canNext" aria-label="Next year" @click="next"><Icon name="arrow-right" /></button>
        <h3 class="ibc__title">Invoices issued in {{ year }}</h3>
      </div>
      <div class="ibc__legend">
        <span class="ibc__key"><span class="ibc__sw ibc__sw--open" /> Open</span>
        <span class="ibc__key"><span class="ibc__sw ibc__sw--paid" /> Paid</span>
      </div>
    </header>

    <svg class="ibc__svg" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="xMidYMid meet"
         role="img" :aria-label="`Monthly invoiced amounts for ${year}`">
      <g class="ibc__grid">
        <line :x1="PAD_L" :x2="W - PAD_R" :y1="baseline" :y2="baseline" />
        <template v-for="g in gridlines" :key="g.value">
          <line :x1="PAD_L" :x2="W - PAD_R" :y1="g.y" :y2="g.y" />
          <text :x="PAD_L - 10" :y="g.y + 4" text-anchor="end">{{ formatChf(g.value) }}</text>
        </template>
      </g>
      <g v-for="b in bars" :key="b.label">
        <rect v-if="b.paid.h > 0" class="ibc__bar ibc__bar--paid" :x="b.x" :y="b.paid.y" :width="barW" :height="b.paid.h" />
        <rect v-if="b.open.h > 0" class="ibc__bar ibc__bar--open" :x="b.x" :y="b.open.y" :width="barW" :height="b.open.h" />
        <text class="ibc__mlabel" :x="b.cx" :y="H - 8" text-anchor="middle">{{ b.label }}</text>
      </g>
    </svg>
  </section>
</template>

<style scoped>
.ibc { padding: 16px 28px; border-bottom: 1px solid var(--border); background: var(--paper); }
.ibc__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.ibc__nav { display: flex; align-items: center; gap: 8px; }
.ibc__arrow { padding: 5px 9px; }
.ibc__arrow:disabled { opacity: 0.4; cursor: default; }
.ibc__title { font-size: var(--fs-md); font-weight: 700; margin: 0 0 0 6px; letter-spacing: -0.01em; }
.ibc__legend { display: flex; gap: 16px; font-size: var(--fs-xs); color: var(--ink-2); }
.ibc__key { display: inline-flex; align-items: center; gap: 6px; }
.ibc__sw { width: 12px; height: 12px; display: inline-block; }
.ibc__sw--paid { background: var(--forest); }
.ibc__sw--open { background: color-mix(in srgb, var(--forest) 45%, var(--paper)); }
.ibc__svg { width: 100%; height: auto; display: block; }
.ibc__grid line { stroke: var(--border); stroke-width: 1; }
.ibc__grid text { fill: var(--ink-3); font-size: 11px; font-variant-numeric: tabular-nums; }
.ibc__mlabel { fill: var(--ink-3); font-size: 12px; }
.ibc__bar--paid { fill: var(--forest); }
.ibc__bar--open { fill: color-mix(in srgb, var(--forest) 45%, var(--paper)); }
</style>
```

- [ ] **Step 3: Build to verify it compiles**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors (the component isn't mounted yet; this just checks it compiles).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Icon.vue resources/js/Components/InvoiceBarChart.vue
git commit -m "feat(invoices): InvoiceBarChart SVG component + arrow icons"
```

---

## Task 4: Wire the chart into the Invoices page

Render the chart band between the stats strip and the filter row; navigate years via a partial reload.

**Files:**
- Modify: `resources/js/Pages/Invoices/Index.vue`

- [ ] **Step 1: Import the component, accept the prop, add year state + handler**

In `resources/js/Pages/Invoices/Index.vue`:

(a) Add the import after `import Pagination from '@/Components/Pagination.vue';`:

```js
import InvoiceBarChart from '@/Components/InvoiceBarChart.vue';
```

(b) Add `invoiceChart` to `defineProps`. Change:

```js
const props = defineProps({
  invoices: { type: Object, required: true },
  stats:    { type: Object, required: true },
  counts:   { type: Object, required: true },
  filters:  { type: Object, required: true },
});
```

to:

```js
const props = defineProps({
  invoices:     { type: Object, required: true },
  stats:        { type: Object, required: true },
  counts:       { type: Object, required: true },
  filters:      { type: Object, required: true },
  invoiceChart: { type: Object, required: true },
});
```

(c) Add the year-nav handler after the `onSearch` function (after line 28's closing brace):

```js
function changeChartYear(year) {
  router.reload({
    only: ['invoiceChart'],
    data: { chart_year: year },
    preserveState: true,
    preserveScroll: true,
  });
}
```

- [ ] **Step 2: Render the chart band between `.stats` and `.filter-row`**

In the template, insert the chart immediately after the closing `</div>` of the `.stats` block (line 77) and before `<div class="filter-row">`:

```html
  <InvoiceBarChart
    :year="invoiceChart.year"
    :min-year="invoiceChart.min_year"
    :max-year="invoiceChart.max_year"
    :months="invoiceChart.months"
    @update:year="changeChartYear"
  />
```

- [ ] **Step 3: Build**

Run: `ddev exec npm run build`
Expected: `✓ built` with no errors; `Index-*.js` for invoices rebuilt.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Invoices/Index.vue
git commit -m "feat(invoices): render monthly chart band with year navigation"
```

---

## Task 5: Verification

**Files:** none (verification only)

- [ ] **Step 1: Run the backend test suites touched**

Run: `ddev exec php artisan test tests/Feature/Support/InvoiceProjectionsTest.php tests/Feature/Http/InvoiceControllerTest.php`
Expected: all PASS.

- [ ] **Step 2: Manually verify** (`ddev launch /invoices`, with a few `sent` and `paid` invoices issued across months this year, plus at least one in a prior year)

  - The chart band sits between the stats strip and the filter tabs.
  - Title reads "Invoices issued in {current year}"; Open/Paid legend top-right.
  - Bars are stacked Paid (dark) on the bottom, Open (lighter) on top; months with no issued invoices show no bar; y-axis gridlines show CHF amounts (e.g. `CHF25'000`).
  - ← navigates to the prior year and the bars/labels update (table + filters unchanged, no full page reload); → returns; arrows disable at `min_year` / `max_year`.
  - A year with no invoices shows just the axis.

- [ ] **Step 3: No commit** (verification only). If issues are found, return to the relevant task.

---

## Self-review notes

- **Spec coverage:** `monthlyIssued` aggregation incl. open/paid split, draft/void exclusion, issued_on bucketing, 12 months, year + min/max bounds (Task 1) ✓; controller `chart_year` + additive `invoiceChart` prop, no clamping (Task 2) ✓; SVG component with title/arrows/legend/gridlines/stacked bars + nice y-scale + Swiss CHF labels (Task 3) ✓; placement between stats and filter row + chart-only partial-reload navigation preserving table state (Task 4) ✓; backend tests + build/manual verification (all tasks + Task 5) ✓.
- **Naming consistency:** prop `invoiceChart` with keys `year`/`min_year`/`max_year`/`months`(`label`/`open`/`paid`) is produced by `monthlyIssued` (Task 1), asserted in Task 2, consumed in Task 3/4. Component props use camelCase `minYear`/`maxYear` bound from snake_case `min_year`/`max_year` via `:min-year`/`:max-year`. Event `update:year` matches `@update:year`.
- **No backend schema changes**; aggregation is read-only.
