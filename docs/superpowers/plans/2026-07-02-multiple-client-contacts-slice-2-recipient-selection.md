# Multiple Client Contacts — Slice 2: Per-Document Recipient Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Depends on:** Slice 1 (contacts foundation) must be merged first. This slice assumes `contacts`, `Client::contacts()`, `Client::defaultRecipients()`, and the first-To/rest-Cc send paths already exist.

**Goal:** Store an explicit recipient snapshot on each invoice / estimate / recurring invoice, defaulted from the client's default contacts, editable until sent, and route sends to that snapshot instead of re-deriving defaults.

**Architecture:** A nullable `recipients` JSON column on `invoices`, `estimates`, `recurring_invoices`, cast to array. Drafts initialise `recipients` from `Client::defaultRecipients()` at creation. Recurring generation copies the schedule's `recipients` onto each generated invoice. The three send paths read `$document->recipients` (falling back to the client's defaults only if the snapshot is null, for pre-Slice-2 rows). A shared `RecipientPicker.vue` lets the user check which of the client's contacts receive the document.

**Tech Stack:** Laravel 11, MySQL (MariaDB via DDEV), Inertia + Vue 3, Pest/PHPUnit.

## Global Constraints

- Run all artisan/test commands through DDEV: `ddev artisan …`, `ddev artisan test …`.
- Recipient shape is `['name' => string, 'email' => string]`, matching `Client::defaultRecipients()`.
- Feature tests 500 unless the asserted page's `.vue` is in the built Vite manifest — run `ddev npm run build` before feature tests that render changed pages.

---

### Task 1: `recipients` JSON column on the three document tables + casts + backfill

**Files:**
- Create: `database/migrations/2026_07_02_000002_add_recipients_to_documents.php`
- Modify: `app/Models/Invoice.php`, `app/Models/Estimate.php`, `app/Models/RecurringInvoice.php`
- Test: `tests/Feature/DocumentRecipientsColumnTest.php`

**Interfaces:**
- Produces: nullable `recipients` JSON column on `invoices`, `estimates`, `recurring_invoices`, cast to `array` on each model and added to `$fillable`. Existing rows are backfilled from their client's current `defaultRecipients()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/DocumentRecipientsColumnTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;

it('casts recipients to an array', function () {
    $invoice = Invoice::factory()->create(['recipients' => [['name' => 'A', 'email' => 'a@x.ch']]]);
    expect($invoice->fresh()->recipients)->toBe([['name' => 'A', 'email' => 'a@x.ch']]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=DocumentRecipientsColumnTest`
Expected: FAIL — `recipients` not fillable / column missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_07_02_000002_add_recipients_to_documents.php
use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('recipients')->nullable()->after('client_id');
            });
        }

        // Backfill each document from its client's default contacts.
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            DB::table($table)->select('id', 'client_id')->orderBy('id')->each(function ($row) use ($table) {
                $client = Client::find($row->client_id);
                if (! $client) {
                    return;
                }
                DB::table($table)->where('id', $row->id)
                    ->update(['recipients' => json_encode($client->defaultRecipients())]);
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('recipients'));
        }
    }
};
```

- [ ] **Step 4: Add cast + fillable to the three models**

In `app/Models/Invoice.php`, `Estimate.php`, `RecurringInvoice.php`: add `'recipients'` to `$fillable` and `'recipients' => 'array',` to `$casts`.

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter=DocumentRecipientsColumnTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_02_000002_add_recipients_to_documents.php app/Models/Invoice.php app/Models/Estimate.php app/Models/RecurringInvoice.php tests/Feature/DocumentRecipientsColumnTest.php
git commit -m "feat: recipients snapshot column on invoices, estimates, recurring invoices"
```

---

### Task 2: Default `recipients` on draft creation

**Files:**
- Modify: `app/Services/Invoicing/InvoiceBuilder.php` (`createDraft`, ~line 98 `Invoice::create`)
- Modify: `app/Http/Controllers/EstimateController.php` (`store`, ~line 68) OR the estimate builder if it owns creation — verify which; mirror the invoice approach
- Modify: `app/Http/Controllers/RecurringInvoiceController.php` (`store`, ~line 60 `RecurringInvoice::create`)
- Test: `tests/Feature/DraftRecipientsDefaultTest.php`

**Interfaces:**
- Consumes: `Client::defaultRecipients()`.
- Produces: a newly created invoice/estimate/recurring-invoice draft has `recipients` set to the client's default recipients at creation time.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/DraftRecipientsDefaultTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Services\Invoicing\InvoiceBuilder;

it('defaults invoice recipients from the client default contacts', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Nope', 'email' => 'no@x.ch', 'is_default' => false]);

    $invoice = app(InvoiceBuilder::class)->createDraft(
        client: $client, project: null,
        periodStart: '2026-07-01', periodEnd: '2026-07-31',
        lines: [['description' => 'Work', 'hours' => 1, 'rate_rappen' => 10000]],
        entryIds: [],
    );

    expect($invoice->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=DraftRecipientsDefaultTest`
Expected: FAIL — `recipients` is null.

- [ ] **Step 3: Set recipients in `InvoiceBuilder::createDraft`**

In the `Invoice::create([...])` array (~line 98), add:

```php
                'recipients' => $client->defaultRecipients(),
```

- [ ] **Step 4: Set recipients when creating estimates and recurring invoices**

- Estimates: locate where the estimate draft is created (`EstimateController::store` calls `$builder->createDraft(...)` — check `app/Services/Estimating/EstimateBuilder.php`; add `'recipients' => $client->defaultRecipients()` to its `Estimate::create([...])`, mirroring the invoice change). If the estimate builder has no `$client` in scope, pass it through as `InvoiceBuilder` does.
- Recurring: in `RecurringInvoiceController::store`, the `RecurringInvoice::create([...])` at ~line 60 — add `'recipients' => Client::findOrFail($data['client_id'])->defaultRecipients(),` (the controller already resolves `$data['client_id']`; reuse the loaded client if one exists).

- [ ] **Step 5: Extend the test for estimates and recurring**

Add two more `it(...)` cases to `DraftRecipientsDefaultTest.php` that POST to `/estimates` and `/recurring-invoices` with a client that has a default contact, then assert the created record's `recipients` equals that contact. (Use the existing estimate/recurring store request shape — copy the payload from `tests/Feature` estimate/recurring creation tests.)

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev artisan test --filter=DraftRecipientsDefaultTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Invoicing/InvoiceBuilder.php app/Services/Estimating/EstimateBuilder.php app/Http/Controllers/EstimateController.php app/Http/Controllers/RecurringInvoiceController.php tests/Feature/DraftRecipientsDefaultTest.php
git commit -m "feat: default document recipients from client contacts on draft creation"
```

---

### Task 3: Recurring generation copies the schedule's recipients

**Files:**
- Modify: `app/Services/Invoicing/RecurringInvoiceGenerator.php` (~line 58, after `$invoice->recurring_invoice_id = $schedule->id`)
- Test: `tests/Feature/RecurringRecipientsCopyTest.php`

**Interfaces:**
- Consumes: `RecurringInvoice::$recipients`.
- Produces: an invoice generated from a schedule has `recipients` equal to the schedule's `recipients`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/RecurringRecipientsCopyTest.php
use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Services\Invoicing\RecurringInvoiceGenerator;

it('copies recurring recipients onto generated invoices', function () {
    $client = Client::factory()->create();
    $schedule = RecurringInvoice::factory()->for($client)->create([
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
        'next_run_on' => now()->toDateString(),
    ]);
    // seed at least one line so generation produces an invoice — mirror existing recurring tests.
    $schedule->lines()->create(['description' => 'Retainer', 'hours' => 1, 'rate_rappen' => 10000, 'sort_order' => 0]);

    $invoice = app(RecurringInvoiceGenerator::class)->generate($schedule, now());

    expect($invoice->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});
```

> Confirm the generator's public method name/signature (`generate($schedule, $runDate)`) against the current file and adjust the call if it differs.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=RecurringRecipientsCopyTest`
Expected: FAIL — generated invoice has defaulted recipients (from builder), not necessarily the schedule's edited set.

- [ ] **Step 3: Copy recipients in the generator**

In `RecurringInvoiceGenerator`, right after `$invoice->recurring_invoice_id = $schedule->id;` and before `$invoice->save();`:

```php
            $invoice->recipients = $schedule->recipients;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter=RecurringRecipientsCopyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/RecurringInvoiceGenerator.php tests/Feature/RecurringRecipientsCopyTest.php
git commit -m "feat: generated invoices inherit recurring schedule recipients"
```

---

### Task 4: Send paths read the document recipient snapshot

**Files:**
- Modify: `app/Services/Invoicing/InvoiceLifecycle.php` (guard + `Mail::to`)
- Modify: `app/Services/Estimating/EstimateLifecycle.php`
- Modify: `app/Jobs/SendInvoiceReminderMail.php`
- Test: `tests/Feature/SendUsesDocumentRecipientsTest.php`

**Interfaces:**
- Consumes: `$document->recipients`.
- Produces: each send path resolves recipients as `$document->recipients ?: $document->client?->defaultRecipients() ?? []` (snapshot wins; fall back to client defaults for null/legacy rows), then sends first-To/rest-Cc. Empty → `\DomainException` (invoice/estimate) or silent skip (reminder), as in Slice 1.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/SendUsesDocumentRecipientsTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Mail\InvoiceMail;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Facades\Mail;

it('sends to the invoice recipient snapshot, not the client defaults', function () {
    Mail::fake();
    $client = Client::factory()->create();
    // Client default differs from the invoice snapshot to prove the snapshot wins.
    Contact::factory()->for($client)->create(['name' => 'Default', 'email' => 'default@x.ch', 'is_default' => true]);
    $invoice = Invoice::factory()->for($client)->create([
        'status' => 'draft',
        'recipients' => [['name' => 'Chosen', 'email' => 'chosen@x.ch']],
    ]);

    app(InvoiceLifecycle::class)->issue($invoice);

    Mail::assertSent(InvoiceMail::class, fn ($m) => $m->hasTo('chosen@x.ch') && ! $m->hasTo('default@x.ch'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=SendUsesDocumentRecipientsTest`
Expected: FAIL — Slice 1 code still sends to `defaultRecipients()` (`default@x.ch`).

- [ ] **Step 3: Update the three send paths**

In each of `InvoiceLifecycle::issue`, `EstimateLifecycle` (send), and `SendInvoiceReminderMail`, replace the recipient resolution line so the snapshot wins:

```php
        $recipients = $invoice->recipients ?: ($invoice->client?->defaultRecipients() ?? []);
```

(use `$estimate` / the reminder's `$invoice` respectively). Leave the first-To/rest-Cc dispatch and the empty guard from Slice 1 unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev artisan test --filter=SendUsesDocumentRecipientsTest`
Expected: PASS.

- [ ] **Step 5: Run the full backend suite**

Run: `ddev artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Invoicing/InvoiceLifecycle.php app/Services/Estimating/EstimateLifecycle.php app/Jobs/SendInvoiceReminderMail.php tests/Feature/SendUsesDocumentRecipientsTest.php
git commit -m "feat: send documents to their recipient snapshot, falling back to client defaults"
```

---

### Task 5: `recipients` editable via the document update endpoints

**Files:**
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `app/Http/Controllers/InvoiceController.php` (`update`, ~line 188)
- Modify: the estimate + recurring update requests/controllers (mirror)
- Test: `tests/Feature/UpdateDocumentRecipientsTest.php`

**Interfaces:**
- Produces: a draft invoice/estimate/recurring `update` accepts a `recipients` array of `{name, email}` and persists it. Validation: `recipients` array, each with required `name` + `email`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/UpdateDocumentRecipientsTest.php
use App\Models\Client;
use App\Models\Invoice;

it('updates invoice recipients on a draft', function () {
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->for($client)->create(['status' => 'draft', 'recipients' => []]);

    $this->patch("/invoices/{$invoice->number}", [
        'recipients' => [['name' => 'Marc', 'email' => 'marc@x.ch']],
    ])->assertRedirect();

    expect($invoice->fresh()->recipients)->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});
```

> Confirm the invoice update route binds by `number` (the show/edit routes use `{invoice->number}`); adjust the URL if it binds by id.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=UpdateDocumentRecipientsTest`
Expected: FAIL — `recipients` stripped by validation / ignored by controller.

- [ ] **Step 3: Allow `recipients` in `UpdateInvoiceRequest`**

Add to `rules()`:

```php
            'recipients' => 'sometimes|array',
            'recipients.*.name' => 'required|string|max:255',
            'recipients.*.email' => 'required|email|max:255',
```

- [ ] **Step 4: Persist in `InvoiceController::update`**

Inside the `DB::transaction` closure (alongside the `array_key_exists('title', …)` blocks):

```php
            if (array_key_exists('recipients', $data)) {
                $invoice->recipients = $data['recipients'];
            }
```

- [ ] **Step 5: Mirror for estimates and recurring invoices**

Apply the same validation rule + `update` persistence to `UpdateEstimateRequest`/`EstimateController::update` and the recurring update request/controller. Reuse the exact rule strings and the `array_key_exists('recipients', …)` guard.

- [ ] **Step 6: Extend the test to cover estimate + recurring update**

Add `it(...)` cases patching an estimate and a recurring invoice's recipients and asserting persistence, mirroring the invoice case with the correct route bindings.

- [ ] **Step 7: Run test to verify it passes**

Run: `ddev artisan test --filter=UpdateDocumentRecipientsTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php app/Http/Requests/UpdateEstimateRequest.php app/Http/Controllers/EstimateController.php app/Http/Requests/*Recurring* app/Http/Controllers/RecurringInvoiceController.php tests/Feature/UpdateDocumentRecipientsTest.php
git commit -m "feat: edit document recipients via update endpoints"
```

---

### Task 6: `RecipientPicker.vue` component

**Files:**
- Create: `resources/js/Components/RecipientPicker.vue`

**Interfaces:**
- Consumes: `contacts` prop — `[{id, name, email, role, is_default}]` (the client's contacts).
- Produces: `v-model` array of `{name, email}` recipient snapshots. Checking a contact adds `{name,email}`; unchecking removes it. Matches on `email`.

- [ ] **Step 1: Create the component**

```vue
<script setup>
const props = defineProps({
  contacts: { type: Array, required: true }, // [{id,name,email,role,is_default}]
});
const model = defineModel({ type: Array, required: true }); // [{name,email}]

function isChecked(contact) {
  return model.value.some((r) => r.email === contact.email);
}
function toggle(contact) {
  if (isChecked(contact)) {
    model.value = model.value.filter((r) => r.email !== contact.email);
  } else {
    model.value = [...model.value, { name: contact.name, email: contact.email }];
  }
}
</script>

<template>
  <div class="recipient-picker">
    <p v-if="!contacts.length" class="muted">This client has no contacts. Add contacts on the client page first.</p>
    <label v-for="c in contacts" :key="c.id" class="recipient-row">
      <input type="checkbox" :checked="isChecked(c)" @change="toggle(c)" />
      <span class="recipient-name">{{ c.name }}</span>
      <span class="muted">{{ c.email }}</span>
      <span v-if="c.is_default" class="badge dot sent">default</span>
    </label>
  </div>
</template>

<style scoped>
.recipient-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: var(--fs-sm); }
.recipient-name { color: var(--ink); }
</style>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/RecipientPicker.vue
git commit -m "feat: RecipientPicker component"
```

---

### Task 7: Wire the recipient picker into the document forms

**Files:**
- Modify: `resources/js/Pages/Invoices/Create.vue` and `resources/js/Pages/Invoices/Edit.vue`
- Modify: `resources/js/Pages/Estimates/Create.vue` / `Edit.vue`
- Modify: `resources/js/Pages/RecurringInvoices/Create.vue` / `Edit.vue`
- Modify: the create/edit controllers to pass the selected client's `contacts` and the document's current `recipients` to the page props (extend the existing `create`/`edit` payloads)

**Interfaces:**
- Consumes: `RecipientPicker.vue` (Task 6); client `contacts` (Slice 1 projections); document `recipients` (Task 1).

- [ ] **Step 1: Pass contacts + recipients to the pages**

In each document `create`/`edit` controller method, add the client's contacts to the props. For create pages that pick a client dynamically, include contacts per client in the existing client/projects list (add a `contacts` key to each client entry, `[{id,name,email,role,is_default}]`); for edit pages, add `'recipients' => $document->recipients ?? []` and the client's contacts to the payload. Follow the exact prop names each page already consumes.

- [ ] **Step 2: Add the picker to each form**

In each Create/Edit page: import `RecipientPicker`, add `recipients: []` (create) or `recipients: props.…recipients` (edit) to the `useForm`, and render:

```vue
    <div class="field">
      <span>Recipients</span>
      <RecipientPicker :contacts="selectedClientContacts" v-model="form.recipients" />
    </div>
```

where `selectedClientContacts` is a computed returning the chosen client's `contacts` (create pages) or the fixed client's contacts (edit pages). On create pages, when the client selection changes, reset `form.recipients` to that client's default contacts (`contacts.filter(c => c.is_default).map(({name,email}) => ({name,email}))`).

- [ ] **Step 3: Build assets**

Run: `ddev npm run build`
Expected: builds clean.

- [ ] **Step 4: Manual end-to-end verification**

Drive the app: create an invoice for a multi-contact client — the picker pre-checks the defaults; uncheck one and add a non-default; save; confirm the draft's recipients reflect the choice; send it and confirm (via Mailpit/`ddev launch`'s mail catcher or the logged `sent` event) it went to the chosen set with first-To/rest-Cc. Repeat abbreviated checks for an estimate and a recurring invoice.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Invoices/Create.vue resources/js/Pages/Invoices/Edit.vue resources/js/Pages/Estimates/Create.vue resources/js/Pages/Estimates/Edit.vue resources/js/Pages/RecurringInvoices/Create.vue resources/js/Pages/RecurringInvoices/Edit.vue app/Http/Controllers/InvoiceController.php app/Http/Controllers/EstimateController.php app/Http/Controllers/RecurringInvoiceController.php
git commit -m "feat: recipient picker on invoice, estimate, and recurring forms"
```

---

## Self-Review Notes

- **Spec coverage:** `recipients` snapshot columns + backfill (Task 1), default-on-create (Task 2), recurring template copy (Task 3), send from snapshot with fallback (Task 4), editable recipients (Task 5), picker component (Task 6), picker wired into all three document forms (Task 7).
- **Fallback safety:** send paths use `recipients ?: defaultRecipients()`, so any null/legacy row still sends. Backfill in Task 1 fills existing rows so this is belt-and-suspenders.
- **Type consistency:** recipient shape `['name','email']` matches `Client::defaultRecipients()` and the Slice-1 send code end to end; picker matches on `email`.
- **Manual-verification tasks (6-wiring, 7):** the exact prop names on the document create/edit pages vary; the implementer must read each page and match its existing client/props shape rather than assume. This is called out in the task, not left as a silent placeholder.
