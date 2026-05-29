# Recurring Invoices (ii) — UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Prerequisite:** Plan (i) backend is merged — `RecurringInvoice`/`RecurringInvoiceLine` models, `BillingPeriod`, `RecurringInvoiceGenerator`, factories, and the migration are in place.

**Goal:** Add the operator-facing UI to create, edit, pause/resume, delete, and manually run recurring-invoice schedules, plus a back-link on generated invoices.

**Architecture:** A `RecurringInvoiceController` (resource-style + `pause`/`resume`/`run`) backed by two form requests. Three Inertia/Vue pages (`Index`, `Create`, `Edit`) mirroring the existing Estimates pages and their line editor. A new sidebar nav entry. The controller derives `anchor_day` from the chosen run date and uses `BillingPeriod::nextRunOnOrAfter` to enforce the no-backfill rule on create/update/resume.

**Tech Stack:** Laravel 12, Inertia 2, Vue 3, Pest 4, Vite.

**Spec:** `docs/superpowers/specs/2026-05-29-recurring-invoices-design.md`

> **Vite manifest gotcha (important):** Inertia page-render feature tests 500 unless the asserted page's `.vue` is present in the built Vite manifest. **Build assets (`ddev npm run build`) after creating the Vue pages and before running the page-render tests in Task 8.** Tasks are ordered so the pages exist before those tests run.

> **Test runner:** use `ddev artisan test --filter=…` (fall back to `php artisan test` if `ddev` is unavailable).

---

## File structure

- Create: `app/Http/Requests/StoreRecurringInvoiceRequest.php`
- Create: `app/Http/Requests/UpdateRecurringInvoiceRequest.php`
- Create: `app/Http/Controllers/RecurringInvoiceController.php`
- Modify: `routes/web.php` (recurring routes, inside the `auth` group)
- Modify: `resources/js/Components/Sidebar.vue` (nav entry)
- Create: `resources/js/Pages/RecurringInvoices/Index.vue`
- Create: `resources/js/Pages/RecurringInvoices/Create.vue`
- Create: `resources/js/Pages/RecurringInvoices/Edit.vue`
- Modify: `app/Http/Controllers/InvoiceController.php` (expose `recurring_invoice` on `show`)
- Modify: `resources/js/Pages/Invoices/Show.vue` (back-link)
- Test: `tests/Feature/Http/RecurringInvoiceControllerTest.php`

---

### Task 1: Form requests

**Files:**
- Create: `app/Http/Requests/StoreRecurringInvoiceRequest.php`
- Create: `app/Http/Requests/UpdateRecurringInvoiceRequest.php`

- [ ] **Step 1: Write `StoreRecurringInvoiceRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; } // single-user app

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $this->input('client_id')))],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'cadence' => 'required|in:monthly,quarterly,half-yearly,yearly',
            'next_run_on' => 'required|date',
            'vat_rate' => 'required|numeric|min:0|max:100',
            'auto_send' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 2: Write `UpdateRecurringInvoiceRequest`** (identical rules — the Edit form submits full state)

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $this->input('client_id')))],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'cadence' => 'required|in:monthly,quarterly,half-yearly,yearly',
            'next_run_on' => 'required|date',
            'vat_rate' => 'required|numeric|min:0|max:100',
            'auto_send' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate_rappen' => 'required|integer|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/StoreRecurringInvoiceRequest.php app/Http/Requests/UpdateRecurringInvoiceRequest.php
git commit -m "feat(recurring): add store/update form requests"
```

---

### Task 2: Controller

**Files:**
- Create: `app/Http/Controllers/RecurringInvoiceController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringInvoiceRequest;
use App\Http\Requests\UpdateRecurringInvoiceRequest;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use App\Support\BillingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecurringInvoiceController extends Controller
{
    public function index(): Response
    {
        $schedules = RecurringInvoice::query()
            ->with('client:id,name')
            ->withCount('invoices')
            ->orderByRaw('paused_at IS NOT NULL')   // active first
            ->orderBy('next_run_on')
            ->get()
            ->map(fn (RecurringInvoice $s) => [
                'id' => $s->id,
                'client' => $s->client->only('id', 'name'),
                'title' => $s->title,
                'cadence' => $s->cadence,
                'next_run_on' => $s->next_run_on?->toDateString(),
                'auto_send' => $s->auto_send,
                'paused' => $s->isPaused(),
                'invoices_count' => $s->invoices_count,
            ]);

        return Inertia::render('RecurringInvoices/Index', ['schedules' => $schedules]);
    }

    public function create(): Response
    {
        return Inertia::render('RecurringInvoices/Create', $this->formData() + [
            'default_vat_rate' => (float) BusinessProfile::current()->default_vat_rate,
        ]);
    }

    public function store(StoreRecurringInvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $first = Carbon::parse($data['next_run_on']);
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today());

        DB::transaction(function () use ($data, $first, $nextRun) {
            $schedule = RecurringInvoice::create([
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'currency' => BusinessProfile::current()->default_currency ?? 'CHF',
                'vat_rate' => $data['vat_rate'],
                'cadence' => $data['cadence'],
                'anchor_day' => $first->day,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
            ]);
            $this->syncLines($schedule, $data['lines']);
        });

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule created.');
    }

    public function edit(RecurringInvoice $recurringInvoice): Response
    {
        $recurringInvoice->load(['lines' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('RecurringInvoices/Edit', $this->formData() + [
            'schedule' => [
                'id' => $recurringInvoice->id,
                'client_id' => $recurringInvoice->client_id,
                'project_id' => $recurringInvoice->project_id,
                'title' => $recurringInvoice->title,
                'notes' => $recurringInvoice->notes,
                'cadence' => $recurringInvoice->cadence,
                'next_run_on' => $recurringInvoice->next_run_on?->toDateString(),
                'vat_rate' => (float) $recurringInvoice->vat_rate,
                'auto_send' => $recurringInvoice->auto_send,
                'lines' => $recurringInvoice->lines->map(fn (RecurringInvoiceLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => (int) round($l->rate_rappen / 100),
                    'vat_exempt' => (bool) $l->vat_exempt,
                ])->values(),
            ],
        ]);
    }

    public function update(UpdateRecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $data = $request->validated();
        $first = Carbon::parse($data['next_run_on']);
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today());

        DB::transaction(function () use ($data, $first, $nextRun, $recurringInvoice) {
            $recurringInvoice->update([
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'vat_rate' => $data['vat_rate'],
                'cadence' => $data['cadence'],
                'anchor_day' => $first->day,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
            ]);
            $recurringInvoice->lines()->delete();
            $this->syncLines($recurringInvoice, $data['lines']);
        });

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule updated.');
    }

    public function pause(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurringInvoice->update(['paused_at' => now()]);

        return back()->with('success', 'Schedule paused.');
    }

    public function resume(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        // Snap the next run forward so resuming never backfills missed periods.
        $next = BillingPeriod::nextRunOnOrAfter(
            $recurringInvoice->cadence,
            Carbon::parse($recurringInvoice->next_run_on),
            Carbon::today(),
        );
        $recurringInvoice->update(['paused_at' => null, 'next_run_on' => $next->toDateString()]);

        return back()->with('success', 'Schedule resumed.');
    }

    public function run(RecurringInvoice $recurringInvoice, RecurringInvoiceGenerator $generator): RedirectResponse
    {
        $invoice = $generator->generate($recurringInvoice, Carbon::parse($recurringInvoice->next_run_on));

        return redirect("/invoices/{$invoice->number}")
            ->with('success', "Generated invoice {$invoice->number} from the recurring schedule.");
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        // Lines cascade; generated invoices are kept (recurring_invoice_id nulls via FK).
        $recurringInvoice->delete();

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule deleted.');
    }

    /** @param array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}> $lines */
    private function syncLines(RecurringInvoice $schedule, array $lines): void
    {
        $sort = 0;
        foreach ($lines as $line) {
            $schedule->lines()->create([
                'description' => (string) $line['description'],
                'hours' => round((float) $line['hours'], 2),
                'rate_rappen' => (int) $line['rate_rappen'],
                'vat_exempt' => (bool) ($line['vat_exempt'] ?? false),
                'sort_order' => $sort++,
            ]);
        }
    }

    /** Shared client/project option lists for the Create/Edit forms. */
    private function formData(): array
    {
        return [
            'clients' => Client::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values(),
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/RecurringInvoiceController.php
git commit -m "feat(recurring): add RecurringInvoiceController"
```

---

### Task 3: Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Register the import** at the top of `routes/web.php` with the other controller imports:

```php
use App\Http\Controllers\RecurringInvoiceController;
```

- [ ] **Step 2: Add routes** inside the `Route::middleware('auth')->group(...)` block, right after the estimates routes:

```php
    Route::get('/recurring-invoices', [RecurringInvoiceController::class, 'index'])->name('recurring.index');
    Route::get('/recurring-invoices/new', [RecurringInvoiceController::class, 'create'])->name('recurring.create');
    Route::post('/recurring-invoices', [RecurringInvoiceController::class, 'store'])->name('recurring.store');
    Route::get('/recurring-invoices/{recurringInvoice}/edit', [RecurringInvoiceController::class, 'edit'])->name('recurring.edit');
    Route::patch('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'update'])->name('recurring.update');
    Route::post('/recurring-invoices/{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause'])->name('recurring.pause');
    Route::post('/recurring-invoices/{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume'])->name('recurring.resume');
    Route::post('/recurring-invoices/{recurringInvoice}/run', [RecurringInvoiceController::class, 'run'])->name('recurring.run');
    Route::delete('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'destroy'])->name('recurring.destroy');
```

- [ ] **Step 3: Verify routes register**

Run: `ddev artisan route:list --name=recurring`
Expected: nine `recurring.*` routes listed.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php
git commit -m "feat(recurring): add recurring-invoices routes"
```

---

### Task 4: Sidebar nav entry

**Files:**
- Modify: `resources/js/Components/Sidebar.vue`

- [ ] **Step 1: Add the nav entry** in the `NAV` computed array, right after the `estimates` entry:

```js
  { id: 'recurring', href: '/recurring-invoices', label: 'Recurring', icon: 'repeat', count: null },
```

> If the icon name `repeat` is not available in the pixelarticons set used by `Icon.vue`, use `'calendar'` (already used elsewhere) instead.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Sidebar.vue
git commit -m "feat(recurring): add sidebar nav entry"
```

---

### Task 5: Index page

**Files:**
- Create: `resources/js/Pages/RecurringInvoices/Index.vue`

- [ ] **Step 1: Write the page**

```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  schedules: { type: Array, default: () => [] },
});

const CADENCE_LABEL = {
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  'half-yearly': 'Half-yearly',
  yearly: 'Yearly',
};

function pause(id) { router.post(`/recurring-invoices/${id}/pause`); }
function resume(id) { router.post(`/recurring-invoices/${id}/resume`); }
function run(id) {
  if (confirm('Generate the next invoice for this schedule now?')) {
    router.post(`/recurring-invoices/${id}/run`);
  }
}
function destroy(id) {
  if (confirm('Delete this recurring schedule? Past invoices are kept.')) {
    router.delete(`/recurring-invoices/${id}`);
  }
}
</script>

<template>
  <Head title="Recurring invoices" />

  <div class="page-head">
    <div>
      <div class="crumb"><span>~ / recurring</span></div>
      <h1 class="page-title">Recurring invoices</h1>
    </div>
    <Link href="/recurring-invoices/new" class="btn primary">New schedule</Link>
  </div>

  <div style="padding: 0 28px 28px">
    <table class="table">
      <thead>
        <tr>
          <th class="pad-l">Client</th>
          <th>Title</th>
          <th>Cadence</th>
          <th>Next run</th>
          <th>Send</th>
          <th class="num">Invoices</th>
          <th style="width: 220px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in schedules" :key="s.id" :class="{ paused: s.paused }">
          <td class="pad-l">{{ s.client.name }}</td>
          <td>{{ s.title || '—' }}</td>
          <td>{{ CADENCE_LABEL[s.cadence] }}</td>
          <td>{{ s.next_run_on }}</td>
          <td>
            <span v-if="s.auto_send" class="badge">auto-send</span>
            <span v-else class="dim" style="font-size: var(--fs-xs)">draft</span>
            <span v-if="s.paused" class="badge warn" style="margin-left: 6px">paused</span>
          </td>
          <td class="num">{{ s.invoices_count }}</td>
          <td style="text-align: right">
            <Link :href="`/recurring-invoices/${s.id}/edit`" class="btn ghost xs">Edit</Link>
            <button v-if="s.paused" class="btn ghost xs" @click="resume(s.id)">Resume</button>
            <button v-else class="btn ghost xs" @click="pause(s.id)">Pause</button>
            <button class="btn ghost xs" @click="run(s.id)">Generate now</button>
            <button class="btn ghost xs danger" @click="destroy(s.id)">Delete</button>
          </td>
        </tr>
        <tr v-if="schedules.length === 0">
          <td colspan="7" class="pad-l muted" style="padding: 24px">
            No recurring schedules yet. Create one to bill retainers, hosting, or maintenance automatically.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.paused { opacity: 0.55; }
.badge { font-size: var(--fs-xs); border: 1px solid var(--border-strong); padding: 1px 6px; border-radius: 3px; }
.badge.warn { color: var(--rust); border-color: var(--rust); }
.btn.xs { padding: 2px 8px; font-size: var(--fs-xs); margin-left: 4px; }
.btn.danger { color: var(--red); }
</style>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/RecurringInvoices/Index.vue
git commit -m "feat(recurring): add Index page"
```

---

### Task 6: Create and Edit pages

**Files:**
- Create: `resources/js/Pages/RecurringInvoices/Create.vue`
- Create: `resources/js/Pages/RecurringInvoices/Edit.vue`

These mirror `resources/js/Pages/Estimates/Create.vue` (same line-editor table and `.cell-input`/`.field` styles), adding cadence, next-run date, VAT rate, and an auto-send toggle.

- [ ] **Step 1: Write `Create.vue`**

```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  clients: { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] },
  default_vat_rate: { type: Number, default: 8.1 },
});

const clientId = ref(null);
const projectId = ref(null);
const title = ref('');
const notes = ref('');
const cadence = ref('monthly');
const nextRunOn = ref(new Date().toISOString().slice(0, 10));
const vatRate = ref(props.default_vat_rate);
const autoSend = ref(false);

const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
const selectedProject = computed(() => props.projects.find((p) => p.id === projectId.value) ?? null);
watch(clientId, () => { projectId.value = null; });

const lines = ref([]);
let nextKey = 0;
function addLine() { lines.value.push({ key: nextKey++, description: '', hours: 1, rate: selectedProject.value?.rate ?? 0, vat_exempt: false }); }
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }
addLine();

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * Number(vatRate.value) / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const canSave = computed(() => clientId.value && lines.value.length > 0 && nextRunOn.value);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    cadence: cadence.value,
    next_run_on: nextRunOn.value,
    vat_rate: Number(vatRate.value),
    auto_send: autoSend.value,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
    })),
  })).post('/recurring-invoices');
}
</script>

<template>
  <Head title="New recurring schedule" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/recurring-invoices">~ / recurring</Link><span class="ascii-dot">/</span><span>new</span></div>
      <h1 class="page-title">New recurring schedule</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/recurring-invoices" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Create schedule</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Title</h3>
      <input v-model="title" class="cell-input" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px; margin-bottom: 20px" placeholder="e.g. Hosting — {period}  (the {period} placeholder becomes “June 2026”, “Q2 2026”, …)" />

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

      <h3 class="section-title">Schedule</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Cadence</span>
          <select v-model="cadence">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="half-yearly">Half-yearly</option>
            <option value="yearly">Yearly</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>First run on</span>
          <input v-model="nextRunOn" type="date" />
        </label>
        <label class="field" style="flex: 1">
          <span>VAT rate %</span>
          <input v-model="vatRate" type="number" min="0" max="100" step="0.01" />
        </label>
      </div>
      <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px">
        <input type="checkbox" v-model="autoSend" />
        <span class="dim" style="font-size: var(--fs-sm)">Auto-send: email the invoice to the client on generation (otherwise it waits as a draft for review).</span>
      </label>

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
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px" placeholder="Optional notes copied to every generated invoice…"></textarea>
    </div>

    <aside>
      <h3 class="section-title">Per-invoice totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ vatRate }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
      <p class="dim" style="font-size: var(--fs-xs); margin-top: 16px; line-height: 1.6">
        Each cycle generates an invoice with these lines. The server recomputes amounts. A past first-run date is snapped forward to the next future cycle — no back-billing.
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
.field select, .field input { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field select:focus, .field input:focus { outline: none; border-color: var(--accent); }
</style>
```

- [ ] **Step 2: Write `Edit.vue`** (same form, prefilled from `schedule`, submits PATCH)

```vue
<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  schedule: { type: Object, required: true },
  clients: { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] },
});

const clientId = ref(props.schedule.client_id);
const projectId = ref(props.schedule.project_id);
const title = ref(props.schedule.title ?? '');
const notes = ref(props.schedule.notes ?? '');
const cadence = ref(props.schedule.cadence);
const nextRunOn = ref(props.schedule.next_run_on);
const vatRate = ref(props.schedule.vat_rate);
const autoSend = ref(props.schedule.auto_send);

const clientProjects = computed(() => props.projects.filter((p) => p.client_id === clientId.value));
watch(clientId, (val, old) => { if (old !== undefined && val !== old) projectId.value = null; });

const lines = ref([]);
let nextKey = 0;
props.schedule.lines.forEach((l) => lines.value.push({ key: nextKey++, description: l.description, hours: l.hours, rate: l.rate, vat_exempt: l.vat_exempt }));
function addLine() { lines.value.push({ key: nextKey++, description: '', hours: 1, rate: 0, vat_exempt: false }); }
function removeLine(key) { lines.value = lines.value.filter((l) => l.key !== key); }
function moveUp(i) { if (i > 0) { const a = lines.value; [a[i - 1], a[i]] = [a[i], a[i - 1]]; } }

function fmtMoney(rappen) { return 'CHF ' + (rappen / 100).toLocaleString('de-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

const taxableRappen = computed(() => lines.value.filter((l) => !l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const exemptRappen = computed(() => lines.value.filter((l) => l.vat_exempt).reduce((a, l) => a + Math.round(Number(l.hours) * Number(l.rate) * 100), 0));
const subtotalRappen = computed(() => taxableRappen.value + exemptRappen.value);
const vatRappen = computed(() => Math.round(taxableRappen.value * Number(vatRate.value) / 100));
const totalRappen = computed(() => subtotalRappen.value + vatRappen.value);

const canSave = computed(() => clientId.value && lines.value.length > 0 && nextRunOn.value);

const form = useForm({});
function save() {
  form.transform(() => ({
    client_id: clientId.value,
    project_id: projectId.value || null,
    title: title.value || null,
    notes: notes.value || null,
    cadence: cadence.value,
    next_run_on: nextRunOn.value,
    vat_rate: Number(vatRate.value),
    auto_send: autoSend.value,
    lines: lines.value.map((l) => ({
      description: l.description,
      hours: Number(l.hours),
      rate_rappen: Math.round(Number(l.rate) * 100),
      vat_exempt: !!l.vat_exempt,
    })),
  })).patch(`/recurring-invoices/${props.schedule.id}`);
}
</script>

<template>
  <Head title="Edit recurring schedule" />

  <div class="page-head">
    <div>
      <div class="crumb"><Link href="/recurring-invoices">~ / recurring</Link><span class="ascii-dot">/</span><span>edit</span></div>
      <h1 class="page-title">Edit recurring schedule</h1>
    </div>
    <div style="display: flex; gap: 8px">
      <Link href="/recurring-invoices" class="btn ghost">Cancel</Link>
      <button class="btn primary" :disabled="form.processing || !canSave" @click="save">Save changes</button>
    </div>
  </div>

  <div style="padding: 0 28px 28px; display: grid; grid-template-columns: 1fr 360px; gap: 28px">
    <div>
      <h3 class="section-title">Title</h3>
      <input v-model="title" class="cell-input" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px; margin-bottom: 20px" placeholder="e.g. Hosting — {period}" />

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

      <h3 class="section-title">Schedule</h3>
      <div style="display: flex; gap: 12px; margin-bottom: 20px">
        <label class="field" style="flex: 1">
          <span>Cadence</span>
          <select v-model="cadence">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="half-yearly">Half-yearly</option>
            <option value="yearly">Yearly</option>
          </select>
        </label>
        <label class="field" style="flex: 1">
          <span>Next run on</span>
          <input v-model="nextRunOn" type="date" />
        </label>
        <label class="field" style="flex: 1">
          <span>VAT rate %</span>
          <input v-model="vatRate" type="number" min="0" max="100" step="0.01" />
        </label>
      </div>
      <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px">
        <input type="checkbox" v-model="autoSend" />
        <span class="dim" style="font-size: var(--fs-sm)">Auto-send the invoice to the client on generation.</span>
      </label>

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
              <button class="icon-btn" title="move up" @click="moveUp(i)"><Icon name="chevron-up" /></button>
              <button class="icon-btn" title="remove" @click="removeLine(l.key)"><Icon name="close" /></button>
            </td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="pad-l muted" style="padding: 16px">No lines. Add one to start.</td></tr>
        </tbody>
      </table>
      <button class="btn ghost" style="margin-top: 12px" @click="addLine">+ Add line</button>

      <h3 class="section-title" style="margin-top: 28px">Notes</h3>
      <textarea v-model="notes" class="cell-input" rows="3" style="width: 100%; border: 1px solid var(--border-strong); padding: 8px"></textarea>
    </div>

    <aside>
      <h3 class="section-title">Per-invoice totals</h3>
      <div class="invoice-totals" style="display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: var(--fs-sm)">
        <div class="label">Subtotal</div><div class="v">{{ fmtMoney(subtotalRappen) }}</div>
        <div class="label">MwSt {{ vatRate }}%</div><div class="v">{{ fmtMoney(vatRappen) }}</div>
        <div class="grand-l">Total</div><div class="v grand">{{ fmtMoney(totalRappen) }}</div>
      </div>
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
.field select, .field input { border: 1px solid var(--border-strong); background: var(--paper); padding: 6px 8px; font-family: inherit; color: var(--ink); }
.field select:focus, .field input:focus { outline: none; border-color: var(--accent); }
</style>
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/RecurringInvoices/Create.vue resources/js/Pages/RecurringInvoices/Edit.vue
git commit -m "feat(recurring): add Create and Edit pages"
```

---

### Task 7: Back-link on generated invoices

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `resources/js/Pages/Invoices/Show.vue`

- [ ] **Step 1: Expose the back-reference in `InvoiceController::show`**

In `app/Http/Controllers/InvoiceController.php::show`, change the eager-load line (currently `$invoice->load([... 'events' => ...]);`) to also load the relation:

```php
        $invoice->load(['client', 'project', 'recurringInvoice:id,title', 'lines' => fn ($q) => $q->orderBy('sort_order'), 'events' => fn ($q) => $q->orderByDesc('occurred_at')]);
```

Then add one key to the `'invoice' => [...]` payload array (e.g. right after the `'notes' => $invoice->notes,` line):

```php
                'recurring' => $invoice->recurringInvoice
                    ? ['id' => $invoice->recurringInvoice->id, 'title' => $invoice->recurringInvoice->title]
                    : null,
```

- [ ] **Step 2: Add the link in `Invoices/Show.vue`**

Near the invoice meta/header in `resources/js/Pages/Invoices/Show.vue`, add (using the existing `invoice` prop object):

```vue
        <Link
          v-if="invoice.recurring"
          :href="`/recurring-invoices/${invoice.recurring.id}/edit`"
          class="dim"
          style="font-size: var(--fs-xs)"
        >↻ Generated from recurring schedule</Link>
```

> Ensure `Link` is imported from `@inertiajs/vue3` in this page (it already is if other links exist; add it to the import if not).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php resources/js/Pages/Invoices/Show.vue
git commit -m "feat(recurring): link generated invoices back to their schedule"
```

---

### Task 8: Build assets, then feature tests

**Files:**
- Test: `tests/Feature/Http/RecurringInvoiceControllerTest.php`

- [ ] **Step 1: Build the frontend** (so the new pages exist in the Vite manifest — required for Inertia render tests)

Run: `ddev npm run build`
Expected: build succeeds; `public/build/manifest.json` now references `RecurringInvoices/Index.vue`, `Create.vue`, `Edit.vue`.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create([
        'name' => 'Ernte Test', 'country' => 'CH',
        'default_currency' => 'CHF', 'default_vat_rate' => 8.10,
        'iban' => 'CH9300762011623852957', 'qr_iban' => 'CH4431999123000889012',
    ]);
    $this->actingAs(User::factory()->create());
    Mail::fake();
    Carbon::setTestNow('2026-05-29');
});

afterEach(fn () => Carbon::setTestNow());

test('index renders the schedules page', function () {
    $this->get('/recurring-invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('RecurringInvoices/Index')->has('schedules'));
});

test('store creates a schedule with lines and snaps a past first-run forward', function () {
    $client = Client::factory()->create();

    $this->post('/recurring-invoices', [
        'client_id' => $client->id,
        'title' => 'Hosting — {period}',
        'cadence' => 'monthly',
        'next_run_on' => '2026-01-10',     // in the past relative to 2026-05-29
        'vat_rate' => 8.10,
        'auto_send' => false,
        'lines' => [['description' => 'Hosting', 'hours' => 1, 'rate_rappen' => 10000, 'vat_exempt' => false]],
    ])->assertRedirect('/recurring-invoices');

    $schedule = RecurringInvoice::first();
    expect($schedule)->not->toBeNull();
    expect($schedule->anchor_day)->toBe(10);
    expect($schedule->next_run_on->toDateString())->toBe('2026-06-10'); // snapped forward, no backfill
    expect($schedule->lines)->toHaveCount(1);
});

test('store rejects a schedule with no lines', function () {
    $client = Client::factory()->create();

    $this->post('/recurring-invoices', [
        'client_id' => $client->id, 'cadence' => 'monthly', 'next_run_on' => '2026-06-01', 'vat_rate' => 8.10, 'lines' => [],
    ])->assertSessionHasErrors('lines');
});

test('update replaces lines and reschedules', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly']);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->patch("/recurring-invoices/{$schedule->id}", [
        'client_id' => $schedule->client_id,
        'cadence' => 'quarterly',
        'next_run_on' => '2026-07-01',
        'vat_rate' => 8.10,
        'lines' => [['description' => 'New line', 'hours' => 2, 'rate_rappen' => 5000, 'vat_exempt' => true]],
    ])->assertRedirect('/recurring-invoices');

    $schedule->refresh()->load('lines');
    expect($schedule->cadence)->toBe('quarterly');
    expect($schedule->lines)->toHaveCount(1);
    expect($schedule->lines->first()->description)->toBe('New line');
});

test('pause and resume toggle the schedule and snap next run forward', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-01-01']);

    $this->post("/recurring-invoices/{$schedule->id}/pause")->assertRedirect();
    expect($schedule->fresh()->isPaused())->toBeTrue();

    $this->post("/recurring-invoices/{$schedule->id}/resume")->assertRedirect();
    $schedule->refresh();
    expect($schedule->isPaused())->toBeFalse();
    expect($schedule->next_run_on->toDateString())->toBe('2026-06-01'); // snapped forward
});

test('run generates an invoice immediately and redirects to it', function () {
    $schedule = RecurringInvoice::factory()->create(['cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-06-01']);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(['hours' => 1, 'rate_rappen' => 10000]);

    $this->post("/recurring-invoices/{$schedule->id}/run")->assertRedirect();

    expect(Invoice::where('recurring_invoice_id', $schedule->id)->count())->toBe(1);
});

test('destroy deletes the schedule but keeps generated invoices', function () {
    $schedule = RecurringInvoice::factory()->create();
    $invoice = Invoice::factory()->create(['recurring_invoice_id' => $schedule->id]);

    $this->delete("/recurring-invoices/{$schedule->id}")->assertRedirect('/recurring-invoices');

    expect(RecurringInvoice::find($schedule->id))->toBeNull();
    expect($invoice->fresh())->not->toBeNull();
    expect($invoice->fresh()->recurring_invoice_id)->toBeNull();
});

test('edit renders the edit page', function () {
    $schedule = RecurringInvoice::factory()->create();
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create();

    $this->get("/recurring-invoices/{$schedule->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('RecurringInvoices/Edit')->has('schedule'));
});
```

- [ ] **Step 3: Run test to verify it fails (before controller wiring is complete) / passes**

Run: `ddev artisan test --filter=RecurringInvoiceControllerTest`
Expected: PASS (9 passed). If any Inertia render test returns 500, re-run Step 1 (`ddev npm run build`) — the manifest is stale.

- [ ] **Step 4: Run the full suite**

Run: `ddev artisan test`
Expected: PASS — no regressions.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Http/RecurringInvoiceControllerTest.php
git commit -m "test(recurring): controller + page-render feature tests"
```

---

### Task 9: Manual verification

- [ ] **Step 1: Exercise the UI**

Run the app (`ddev npm run dev` + `ddev launch`), then:
1. Visit `/recurring-invoices` → empty state shows.
2. Create a monthly schedule with title `Hosting — {period}`, a first-run date in the current month, one line, auto-send off.
3. Confirm it lists with the right cadence and next-run date.
4. Click **Generate now** → redirected to a new draft invoice whose title reads `Hosting — <Month> <Year>` and whose lines match.
5. On that invoice's Show page, confirm the "Generated from recurring schedule" link appears and points back to the edit page.
6. Pause, resume, edit, and delete the schedule.

---

## Self-review notes (for the implementer)

- **No-backfill** is enforced in `store`, `update`, and `resume` via `BillingPeriod::nextRunOnOrAfter`.
- **VAT rate** flows: form → `vat_rate` column → generator passes it to `createDraft(vatRate: …)` (plan i, Task 5).
- **Manifest gotcha**: assets must be built before the Inertia render tests (Task 8, Step 1).
- The `run` action reuses the same generator as the daily command, so manual and scheduled generation are identical.
- Deleting a schedule keeps invoices (`nullOnDelete`) — covered by the destroy test.
