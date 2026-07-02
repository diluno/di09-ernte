# Multiple Client Contacts — Slice 1: Contacts Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single `contact_name`/`email` columns on `clients` with a `contacts` table (many per client), edit them in the client UI, and route all document sends to the client's default contacts.

**Architecture:** New `Contact` model with a `hasMany` from `Client`. Existing single contact is backfilled into one default contact row, then the old columns are dropped. A `Client::defaultRecipients()` helper returns `[{name,email}]`; the three send paths (`InvoiceLifecycle`, `EstimateLifecycle`, `SendInvoiceReminderMail`) resolve recipients through it instead of reading `client->email`. This slice leaves the app fully working with auto-addressed defaults; per-document recipient choice is Slice 2.

**Tech Stack:** Laravel 11, MySQL (MariaDB via DDEV), Inertia + Vue 3, Pest/PHPUnit.

## Global Constraints

- Run all artisan/test commands through DDEV: `ddev artisan …`, `ddev artisan test …`. The host shell cannot reach the DB.
- Money stays in rappen integers; unrelated to this slice but do not touch those fields.
- Follow existing file patterns: FormRequests for validation, `App\Support\*` projection classes for Inertia payloads, `defineOptions({ layout: AppLayout })` in pages.
- Feature tests 500 unless the asserted page's `.vue` is in the built Vite manifest — build assets (`ddev npm run build`) before running feature tests that render new/changed pages.

---

### Task 1: `contacts` table migration with backfill and column drop

**Files:**
- Create: `database/migrations/2026_07_02_000001_create_contacts_table.php`
- Test: `tests/Feature/ContactsMigrationTest.php`

**Interfaces:**
- Produces: `contacts` table with columns `id, client_id, name, email, role (nullable), is_default (bool), sort_order (int), timestamps`. Each pre-existing client with a non-empty `email` gets exactly one contact (`is_default = true`, `sort_order = 0`, `name = contact_name ?: client name`). Columns `clients.contact_name` and `clients.email` are dropped.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ContactsMigrationTest.php
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Facades\Schema;

it('backfills a default contact from the legacy client columns and drops them', function () {
    // The migration under test has already run in the test DB. Assert its shape.
    expect(Schema::hasColumn('clients', 'email'))->toBeFalse();
    expect(Schema::hasColumn('clients', 'contact_name'))->toBeFalse();
    expect(Schema::hasColumns('contacts', ['client_id', 'name', 'email', 'role', 'is_default', 'sort_order']))->toBeTrue();
});

it('creates and reads a contact row', function () {
    $client = Client::factory()->create();
    $contact = Contact::create([
        'client_id' => $client->id,
        'name' => 'Marc Gschwend',
        'email' => 'marc@moveable.ch',
        'role' => 'Accounting',
        'is_default' => true,
        'sort_order' => 0,
    ]);

    expect($contact->fresh()->email)->toBe('marc@moveable.ch');
    expect($contact->fresh()->is_default)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=ContactsMigrationTest`
Expected: FAIL — `Class "App\Models\Contact" not found` / table missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_07_02_000001_create_contacts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('role')->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Backfill: one default contact per client that had an email.
        DB::table('clients')->whereNotNull('email')->where('email', '!=', '')->orderBy('id')
            ->each(function ($client) {
                DB::table('contacts')->insert([
                    'client_id' => $client->id,
                    'name' => $client->contact_name ?: $client->name,
                    'email' => $client->email,
                    'role' => null,
                    'is_default' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
        });
        Schema::dropIfExists('contacts');
    }
};
```

- [ ] **Step 4: Create the `Contact` model** (needed for the test and downstream tasks)

```php
<?php
// app/Models/Contact.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'name', 'email', 'role', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function client() { return $this->belongsTo(Client::class); }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter=ContactsMigrationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_02_000001_create_contacts_table.php app/Models/Contact.php tests/Feature/ContactsMigrationTest.php
git commit -m "feat: contacts table with backfill from legacy client columns"
```

---

### Task 2: `Client → contacts` relation, `defaultRecipients()`, and factory cleanup

**Files:**
- Modify: `app/Models/Client.php`
- Create: `database/factories/ContactFactory.php`
- Modify: `database/factories/ClientFactory.php` (remove `contact_name`/`email` if present)
- Test: `tests/Feature/ClientContactsRelationTest.php`

**Interfaces:**
- Consumes: `Contact` model (Task 1).
- Produces: `Client::contacts()` (hasMany, ordered `sort_order` then `id`); `Client::defaultRecipients(): array` returning `[['name' => string, 'email' => string], …]` for contacts where `is_default = true`, ordered the same way. Empty array when none.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ClientContactsRelationTest.php
use App\Models\Client;
use App\Models\Contact;

it('returns default recipients ordered by sort_order', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'B', 'email' => 'b@x.ch', 'is_default' => true, 'sort_order' => 1]);
    Contact::factory()->for($client)->create(['name' => 'A', 'email' => 'a@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'C', 'email' => 'c@x.ch', 'is_default' => false, 'sort_order' => 2]);

    expect($client->defaultRecipients())->toBe([
        ['name' => 'A', 'email' => 'a@x.ch'],
        ['name' => 'B', 'email' => 'b@x.ch'],
    ]);
});

it('returns an empty array when no default contacts exist', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['is_default' => false]);
    expect($client->defaultRecipients())->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=ClientContactsRelationTest`
Expected: FAIL — `Call to undefined method … defaultRecipients()` and/or missing `ContactFactory`.

- [ ] **Step 3: Add the relation and helper to `Client`**

In `app/Models/Client.php`, remove `'contact_name', 'email',` from `$fillable`, and add:

```php
    public function contacts()
    {
        return $this->hasMany(Contact::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Default recipient set as [{name,email}] snapshots. */
    public function defaultRecipients(): array
    {
        return $this->contacts()
            ->where('is_default', true)
            ->get(['name', 'email'])
            ->map(fn (Contact $c) => ['name' => $c->name, 'email' => $c->email])
            ->all();
    }
```

Add `use App\Models\Contact;`? No — same namespace; reference `Contact::class` directly (already in `App\Models`). Import is unnecessary.

- [ ] **Step 4: Create `ContactFactory` and clean `ClientFactory`**

```php
<?php
// database/factories/ContactFactory.php
namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'role' => null,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }
}
```

In `database/factories/ClientFactory.php`, delete any `'contact_name' => …` and `'email' => …` keys from the `definition()` return array (they now break inserts).

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter=ClientContactsRelationTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite to catch factory fallout**

Run: `ddev artisan test`
Expected: PASS. If other tests referenced `contact_name`/`email` on clients, note the failures — they are fixed in Tasks 3–5. If a *factory/seeder* still sets those keys, fix it now (it is infrastructure, not behavior).

- [ ] **Step 7: Commit**

```bash
git add app/Models/Client.php database/factories/ContactFactory.php database/factories/ClientFactory.php tests/Feature/ClientContactsRelationTest.php
git commit -m "feat: Client contacts relation and defaultRecipients helper"
```

---

### Task 3: Client request validation + controller sync of contacts

**Files:**
- Modify: `app/Http/Requests/StoreClientRequest.php`
- Modify: `app/Http/Requests/UpdateClientRequest.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Test: `tests/Feature/ClientContactsControllerTest.php`

**Interfaces:**
- Consumes: `Client::contacts()` (Task 2).
- Produces: `store`/`update` accept a `contacts` array of `{name, email, role?, is_default}` and sync it (create new, update existing by `id`, delete omitted). `edit` payload includes a `contacts` array with `id, name, email, role, is_default, sort_order`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ClientContactsControllerTest.php
use App\Models\Client;
use App\Models\Contact;

it('creates a client with contacts', function () {
    $this->post('/clients', [
        'name' => 'Moveable', 'short_code' => 'MOV', 'country' => 'CH',
        'contacts' => [
            ['name' => 'Marc', 'email' => 'marc@x.ch', 'role' => 'Lead', 'is_default' => true],
            ['name' => 'Acct', 'email' => 'acct@x.ch', 'role' => null, 'is_default' => false],
        ],
    ])->assertRedirect('/clients');

    $client = Client::where('short_code', 'MOV')->firstOrFail();
    expect($client->contacts)->toHaveCount(2);
    expect($client->defaultRecipients())->toBe([['name' => 'Marc', 'email' => 'marc@x.ch']]);
});

it('syncs contacts on update: adds, edits, deletes', function () {
    $client = Client::factory()->create();
    $keep = Contact::factory()->for($client)->create(['name' => 'Old', 'email' => 'old@x.ch', 'is_default' => true]);
    $drop = Contact::factory()->for($client)->create(['name' => 'Gone', 'email' => 'gone@x.ch']);

    $this->patch("/clients/{$client->id}", [
        'contacts' => [
            ['id' => $keep->id, 'name' => 'Renamed', 'email' => 'old@x.ch', 'role' => null, 'is_default' => true],
            ['name' => 'New', 'email' => 'new@x.ch', 'role' => null, 'is_default' => false],
        ],
    ])->assertRedirect();

    $client->refresh();
    expect($client->contacts->pluck('name')->sort()->values()->all())->toBe(['New', 'Renamed']);
    expect(Contact::find($drop->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=ClientContactsControllerTest`
Expected: FAIL — contacts not persisted (validation strips them / controller ignores them).

- [ ] **Step 3: Update both FormRequests**

In `StoreClientRequest::rules()` and `UpdateClientRequest::rules()`, delete the `contact_name` and `email` lines and add:

```php
            'contacts' => 'sometimes|array',
            'contacts.*.id' => 'sometimes|integer',
            'contacts.*.name' => 'required|string|max:255',
            'contacts.*.email' => 'required|email|max:255',
            'contacts.*.role' => 'nullable|string|max:255',
            'contacts.*.is_default' => 'boolean',
```

- [ ] **Step 4: Sync contacts in the controller**

In `app/Http/Controllers/ClientController.php`, add a private helper and call it from `store`/`update`. Replace `store`, `edit`, and `update`:

```php
    public function store(StoreClientRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $client = Client::create($data);
        $this->syncContacts($client, $contacts);

        return redirect('/clients');
    }

    public function edit(Client $client): Response
    {
        $client->load('contacts');

        return Inertia::render('Clients/Edit', [
            'client' => [
                ...$client->only([
                    'id', 'name', 'short_code',
                    'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                    'vat_id', 'default_rate_rappen',
                ]),
                'contacts' => $client->contacts->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'email' => $c->email,
                    'role' => $c->role, 'is_default' => $c->is_default, 'sort_order' => $c->sort_order,
                ])->values(),
            ],
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $data = $request->validated();
        $contacts = $data['contacts'] ?? null;
        unset($data['contacts']);

        $client->update($data);
        if ($contacts !== null) {
            $this->syncContacts($client, $contacts);
        }

        return back();
    }

    /** Create/update/delete a client's contacts from a submitted array. */
    private function syncContacts(Client $client, array $contacts): void
    {
        $keptIds = [];
        foreach ($contacts as $i => $row) {
            $attrs = [
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => $row['role'] ?? null,
                'is_default' => (bool) ($row['is_default'] ?? false),
                'sort_order' => $i,
            ];
            if (! empty($row['id'])) {
                $client->contacts()->whereKey($row['id'])->update($attrs);
                $keptIds[] = (int) $row['id'];
            } else {
                $keptIds[] = $client->contacts()->create($attrs)->id;
            }
        }
        $client->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter=ClientContactsControllerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/StoreClientRequest.php app/Http/Requests/UpdateClientRequest.php app/Http/Controllers/ClientController.php tests/Feature/ClientContactsControllerTest.php
git commit -m "feat: validate and sync client contacts in ClientController"
```

---

### Task 4: Expose contacts in Inertia projections

**Files:**
- Modify: `app/Support/ClientDetail.php` (`client()` method, ~line 32)
- Modify: `app/Support/ClientProjections.php` (`index()` map, ~line 38)
- Test: `tests/Feature/ClientProjectionsContactsTest.php`

**Interfaces:**
- Consumes: `Client::contacts()`, `Client::defaultRecipients()`.
- Produces: `ClientDetail::payload()['client']['contacts']` — array of `{id, name, email, role, is_default}`. `ClientProjections::index()` rows expose `default_contact` — the first default recipient `{name,email}` or `null` (used by the index list; replaces the old `contact_name`/`email` keys).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ClientProjectionsContactsTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Support\ClientDetail;
use App\Support\ClientProjections;

it('includes contacts in the client detail payload', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'm@x.ch', 'is_default' => true]);

    $payload = ClientDetail::payload($client->fresh());

    expect($payload['client']['contacts'])->toHaveCount(1);
    expect($payload['client']['contacts'][0]['email'])->toBe('m@x.ch');
    expect($payload['client'])->not->toHaveKey('email');
});

it('exposes the primary default contact in the index projection', function () {
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'm@x.ch', 'is_default' => true, 'sort_order' => 0]);

    $row = ClientProjections::index()->firstWhere('id', $client->id);
    expect($row['default_contact'])->toBe(['name' => 'Marc', 'email' => 'm@x.ch']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=ClientProjectionsContactsTest`
Expected: FAIL — payload still emits `contact_name`/`email`, no `contacts`/`default_contact`.

- [ ] **Step 3: Update `ClientDetail::client()`**

In `app/Support/ClientDetail.php`, in the `client()` method array, delete the `'contact_name' => …` and `'email' => …` lines and add before `'address_line_1'`:

```php
            'contacts' => $client->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email,
                'role' => $c->role, 'is_default' => $c->is_default,
            ])->values()->all(),
```

And in `payload()`, eager-load contacts so the map has data:

```php
        $client->loadMissing('contacts');
```
(place it as the first line of `payload()`.)

- [ ] **Step 4: Update `ClientProjections::index()`**

In `app/Support/ClientProjections.php`: add `->with('contacts')` to the `Client::query()` builder (after `->withCount('projects')`), then in the `->map(...)` array delete `'contact_name' => …` and `'email' => …` and add:

```php
            'default_contact' => $c->defaultRecipients()[0] ?? null,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev artisan test --filter=ClientProjectionsContactsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ClientDetail.php app/Support/ClientProjections.php tests/Feature/ClientProjectionsContactsTest.php
git commit -m "feat: expose client contacts in Inertia projections"
```

---

### Task 5: Route send paths through `defaultRecipients()`

**Files:**
- Modify: `app/Services/Invoicing/InvoiceLifecycle.php` (~lines 21-46)
- Modify: `app/Services/Estimating/EstimateLifecycle.php` (~lines 29-47)
- Modify: `app/Jobs/SendInvoiceReminderMail.php` (~lines 31-50)
- Test: `tests/Feature/SendToDefaultContactsTest.php`

**Interfaces:**
- Consumes: `Client::defaultRecipients()`.
- Produces: each send path emails the first default recipient on `To` and the rest on `Cc`; throws `\DomainException` when the client has no default recipients; the `sent` event's `email_to` payload records the full recipient email list.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/SendToDefaultContactsTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Mail\InvoiceMail;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Facades\Mail;

it('sends an invoice to all default contacts, first To then Cc', function () {
    Mail::fake();
    $client = Client::factory()->create();
    Contact::factory()->for($client)->create(['name' => 'Marc', 'email' => 'marc@x.ch', 'is_default' => true, 'sort_order' => 0]);
    Contact::factory()->for($client)->create(['name' => 'Acct', 'email' => 'acct@x.ch', 'is_default' => true, 'sort_order' => 1]);
    $invoice = Invoice::factory()->for($client)->create(['status' => 'draft']);

    app(InvoiceLifecycle::class)->issue($invoice);

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('marc@x.ch') && $mail->hasCc('acct@x.ch');
    });
});

it('refuses to send when the client has no default contacts', function () {
    $client = Client::factory()->create(); // no contacts
    $invoice = Invoice::factory()->for($client)->create(['status' => 'draft']);

    expect(fn () => app(InvoiceLifecycle::class)->issue($invoice))
        ->toThrow(\DomainException::class);
});
```

> Note: `Invoice::factory()` must produce a draft that `issue()` can render. If the existing factory already supports this (it is used by current invoice tests), reuse it. If PDF rendering needs lines, add `->has(InvoiceLine::factory()->count(1), 'lines')` to match existing invoice-send tests — check `tests/Feature` for the current pattern and mirror it.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev artisan test --filter=SendToDefaultContactsTest`
Expected: FAIL — code still reads `client->email` (now a missing column → error), and no Cc.

- [ ] **Step 3: Update `InvoiceLifecycle::issue()`**

Add `use Illuminate\Mail\Mailables\Address;` at the top. Replace the guard and the `Mail::to(...)` line:

```php
        $recipients = $invoice->client?->defaultRecipients() ?? [];
        if (empty($recipients)) {
            throw new \DomainException('Cannot send invoice because the client has no contacts.');
        }
```

```php
            $to = array_map(fn ($r) => new Address($r['email'], $r['name']), $recipients);
            Mail::to($to[0])->cc(array_slice($to, 1))->send(new InvoiceMail($invoice, $path));

            $this->event($invoice, 'pdf_generated', ['path' => $path]);
            $this->event($invoice, 'sent', ['email_to' => array_column($recipients, 'email'), 'pdf_path' => $path]);
```

- [ ] **Step 4: Update `EstimateLifecycle` the same way**

Add `use Illuminate\Mail\Mailables\Address;`. Replace the `! $estimate->client?->email` guard with the `defaultRecipients()` guard (message: "Cannot send estimate because the client has no contacts."), and replace `Mail::to($estimate->client->email)->send(new EstimateMail(...))` with the first-To/rest-Cc pattern from Step 3 (using `EstimateMail`). Update the `sent` event `email_to` to `array_column($recipients, 'email')`.

- [ ] **Step 5: Update `SendInvoiceReminderMail`**

Add `use Illuminate\Mail\Mailables\Address;`. Replace the `if (! $invoice->client?->email)` guard (~line 50) with an empty-`defaultRecipients()` check that returns early (reminders should silently skip a contactless client, matching the job's current "no email → skip" behavior — verify the current early-return semantics and preserve them). Replace `Mail::to($invoice->client->email)->send(new InvoiceReminderMail($invoice))` with the first-To/rest-Cc pattern, and set the event `email_to` to `array_column($recipients, 'email')`.

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev artisan test --filter=SendToDefaultContactsTest`
Expected: PASS.

- [ ] **Step 7: Run the full backend suite**

Run: `ddev artisan test`
Expected: PASS. Fix any remaining tests that asserted on `client->email` for sends by pointing them at a default contact.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Invoicing/InvoiceLifecycle.php app/Services/Estimating/EstimateLifecycle.php app/Jobs/SendInvoiceReminderMail.php tests/Feature/SendToDefaultContactsTest.php
git commit -m "feat: route document sends to client default contacts (first To, rest Cc)"
```

---

### Task 6: Contacts editor UI on the client Create/Edit forms

**Files:**
- Create: `resources/js/Components/ContactsEditor.vue`
- Modify: `resources/js/Pages/Clients/Create.vue`
- Modify: `resources/js/Pages/Clients/Edit.vue`

**Interfaces:**
- Consumes: `client.contacts` from the edit payload (Task 4); posts a `contacts` array (Task 3).
- Produces: `ContactsEditor` — a `v-model`ed component over an array of `{id?, name, email, role, is_default}`; add / remove rows and toggle `is_default`.

- [ ] **Step 1: Create `ContactsEditor.vue`**

```vue
<script setup>
const model = defineModel({ type: Array, required: true });

function addRow() {
  model.value = [...model.value, { name: '', email: '', role: '', is_default: model.value.length === 0 }];
}
function removeRow(i) {
  model.value = model.value.filter((_, idx) => idx !== i);
}
</script>

<template>
  <div class="contacts-editor">
    <div v-for="(c, i) in model" :key="c.id ?? `new-${i}`" class="contact-row">
      <input v-model="c.name" placeholder="Name" aria-label="Contact name" />
      <input type="email" v-model="c.email" placeholder="Email" aria-label="Contact email" />
      <input v-model="c.role" placeholder="Role (optional)" aria-label="Contact role" />
      <label class="default-toggle" title="Default recipient">
        <input type="checkbox" v-model="c.is_default" /> default
      </label>
      <button type="button" class="row-action row-action--danger" @click="removeRow(i)" aria-label="Remove contact">✕</button>
    </div>
    <button type="button" class="btn btn--ghost" @click="addRow">+ Add contact</button>
  </div>
</template>

<style scoped>
.contact-row { display: grid; grid-template-columns: 1fr 1fr 0.7fr auto auto; gap: 8px; align-items: center; margin-bottom: 8px; }
.default-toggle { display: flex; align-items: center; gap: 4px; font-size: var(--fs-xs); color: var(--ink-3); white-space: nowrap; }
</style>
```

- [ ] **Step 2: Wire into `Create.vue`**

In `resources/js/Pages/Clients/Create.vue`: import the editor (`import ContactsEditor from '@/Components/ContactsEditor.vue';`), replace `contact_name: '', email: '',` in the `useForm({...})` with `contacts: [],`, delete the two `<label>` blocks for "Contact name" and "Email", and add before the submit button:

```vue
    <div class="field" style="grid-column: span 2">
      <span>Contacts</span>
      <ContactsEditor v-model="form.contacts" />
      <small v-if="form.errors.contacts" class="err">{{ form.errors.contacts }}</small>
    </div>
```

- [ ] **Step 3: Wire into `Edit.vue`**

Mirror Step 2 in `resources/js/Pages/Clients/Edit.vue`: import the editor; seed the form with `contacts: props.client.contacts ?? []` (map to plain objects so they're mutable); remove the old contact name/email fields; add the same `<ContactsEditor>` block.

- [ ] **Step 4: Build assets and manually verify**

Run: `ddev npm run build`
Then start the app (`ddev launch` or existing dev flow) and: create a client with two contacts (one default), confirm they persist and appear on the client show page; edit to rename one, add one, delete one, and re-check.

Expected: contacts round-trip; default checkbox controls which contacts documents address.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/ContactsEditor.vue resources/js/Pages/Clients/Create.vue resources/js/Pages/Clients/Edit.vue
git commit -m "feat: contacts editor on client create/edit forms"
```

---

### Task 7: Contacts panel on the client show page

**Files:**
- Modify: `resources/js/Pages/Clients/Show.vue` (CONTACT panel, ~lines 165-172)
- Modify: `resources/js/Pages/Clients/Index.vue` (wherever it renders `contact_name`/`email`, if at all)

**Interfaces:**
- Consumes: `client.contacts` (Task 4) on Show; `default_contact` (Task 4) on Index.

- [ ] **Step 1: Update the Show CONTACT panel**

In `resources/js/Pages/Clients/Show.vue`, replace the two `<dt>Contact</dt><dd>{{ client.contact_name … }}</dd>` and `<dt>Email</dt><dd>{{ client.email … }}</dd>` rows with a list over `client.contacts`:

```vue
      <template v-if="client.contacts.length">
        <div v-for="c in client.contacts" :key="c.id" class="contact-line">
          <span class="contact-name">{{ c.name }}<span v-if="c.is_default" class="badge dot sent" style="margin-left: 6px">default</span></span>
          <a :href="`mailto:${c.email}`">{{ c.email }}</a>
          <span v-if="c.role" class="muted">{{ c.role }}</span>
        </div>
      </template>
      <p v-else class="muted">No contacts.</p>
```

Keep the remaining `<dt>/<dd>` rows (Default rate, VAT ID) as-is.

- [ ] **Step 2: Fix the Index list if it referenced the old fields**

Grep first: `grep -n "contact_name\|\.email" resources/js/Pages/Clients/Index.vue`. If it renders them, swap to `client.default_contact?.name` / `client.default_contact?.email` (guard for `null`). If it doesn't reference them, skip.

- [ ] **Step 3: Build and verify**

Run: `ddev npm run build`
Load a client show page: contacts list renders, default badge shows, mailto links work. Load the clients index: no console errors, primary contact shows where expected.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Clients/Show.vue resources/js/Pages/Clients/Index.vue
git commit -m "feat: show client contacts on detail and index pages"
```

---

## Self-Review Notes

- **Spec coverage:** contacts table + backfill + drop columns (Task 1), relation/helper (Task 2), CRUD + validation (Task 3), projections (Task 4), send fan-out first-To/rest-Cc + guard (Task 5), contact-management UI (Tasks 6–7). Per-document `recipients` snapshot and the recipient picker are intentionally **out of scope** for this slice — they are Slice 2.
- **Interim behavior:** until Slice 2, documents auto-address the client's default contacts at send time (no per-document choice). The app is fully working after Task 7.
- **Type consistency:** recipient shape is `['name' => …, 'email' => …]` everywhere; `defaultRecipients()` returns a plain array; `syncContacts` keys off `id`.
