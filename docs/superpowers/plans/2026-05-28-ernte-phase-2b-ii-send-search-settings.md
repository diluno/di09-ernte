# Ernte — Phase 2b-ii (Send + reminders + settings + search polish) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the Phase 2b polish layer left after invoice core: real invoice email send, reminder and overdue-stamp scheduler commands, editable business profile settings, the reports placeholder, global search with a command palette, keyboard shortcuts, and the backup command that feeds the statusbar.

**Source spec read first:** `docs/superpowers/specs/2026-05-27-ernte-design.md` §6 invoice flow (statuses, sending, reminders) and §7 UX system (topbar, statusbar, command palette). Also relevant: §8 DDEV scheduler/queue and backups, §9 invariants.

**Carryover read first:** `docs/superpowers/phase-2b-carryover.md`, especially "Phase 2b-ii scope" and the "New known-pending items" list.

**Predecessor:** `docs/superpowers/plans/2026-05-28-ernte-phase-2b-i-invoices.md` (merged to `main`; 166 tests passing, build clean).

---

## Scope

Phase 2b-i shipped invoice index/create/show/PDF/QR-bill and the lifecycle shell. Phase 2b-ii finishes the remaining Phase 2 items:

- Send invoice email with the generated PDF attached.
- Reminder email command and daily overdue-stamp command.
- Scheduler wiring for reminders, overdue stamps, and backups.
- Settings/Profile page for the singleton `business_profile`; tweaks persistence already exists.
- Reports placeholder matching the design copy.
- `/api/search` mixed project/client/invoice endpoint.
- Topbar command palette and global keyboard shortcuts.
- `ernte:backup` command that writes `backups` rows for the statusbar.
- Carryover update after completion.

Production Docker/`bin/install`/README remain Phase 3.

---

## Discoveries and decisions

1. **The click-path send cannot be purely async yet.** Spec §6 says the Send button wraps work in a queue job, but the same section and the carryover require SMTP failure to keep the invoice as `draft` and surface a flash error. A queued job cannot reliably flash an SMTP failure back to the request without adding a pending/failed-send UI. For 2b-ii, implement the invoice Send click path synchronously inside `InvoiceLifecycle::issue()` so PDF render + SMTP send + status/event writes succeed or rollback together. Reminder delivery can use the `emails` queue because it has no request flash surface.

2. **`InvoiceLifecycle::issue()` already mutates before email.** Keep the existing method name and route, but add mail inside the transaction after PDF render and before events are committed. If mail throws, the DB transaction rolls back to `draft`. The generated PDF file may remain on disk after a failed send; it is orphaned and overwritten on retry, and `pdf_path` rolls back.

3. **Business profile is seedable but not editable.** Settings/Profile should land before serious send testing because QR-bill creditor fields and the invoice sender email currently require seed/env edits.

4. **DDEV scheduler and queue daemons are already configured.** `.ddev/config.yaml` already runs `schedule:work` and `queue:work --queue=default,emails --tries=3`; this phase only needs Laravel schedule definitions and commands.

5. **Reports route/page exists but is placeholder-light.** Keep it as a placeholder, but align it with the design text: "utilization, revenue, project profitability."

6. **No new npm package is needed.** Use existing Vue/Inertia and hand-rolled CSS, following the current app's literal-path convention instead of Ziggy in Vue pages.

---

## File map

Created:

| Path | Responsibility |
|---|---|
| `app/Mail/InvoiceMail.php` | Invoice send email, PDF attachment |
| `app/Mail/InvoiceReminderMail.php` | Reminder email |
| `resources/views/emails/invoices/sent.blade.php` | Invoice send body |
| `resources/views/emails/invoices/reminder.blade.php` | Reminder body |
| `app/Jobs/SendInvoiceReminderMail.php` | Queueable reminder mail job on `emails` |
| `app/Console/Commands/RemindInvoicesCommand.php` | `ernte:invoices:remind` |
| `app/Console/Commands/StampOverdueInvoicesCommand.php` | `ernte:invoices:stamp-overdue` |
| `app/Console/Commands/BackupCommand.php` | `ernte:backup` |
| `app/Http/Controllers/SearchController.php` | `/api/search` JSON endpoint |
| `app/Http/Requests/UpdateBusinessProfileRequest.php` | Business profile validation |
| `resources/js/Pages/Settings/Profile.vue` | Business profile + tweaks page |
| `resources/js/Components/CommandPalette.vue` | `⌘K` mixed search modal |
| `resources/js/composables/useKeyboardShortcuts.js` | Global shortcut handling |
| `tests/Feature/Http/SearchControllerTest.php` | Search API assertions |
| `tests/Feature/Http/SettingsProfileTest.php` | Settings/Profile HTTP assertions |
| `tests/Feature/Mail/InvoiceMailTest.php` | Mail subject/attachment/content |
| `tests/Feature/Console/InvoiceReminderCommandTest.php` | Reminder selection/idempotency |
| `tests/Feature/Console/OverdueStampCommandTest.php` | Overdue event stamping |
| `tests/Feature/Console/BackupCommandTest.php` | Backup command writes files + row |

Modified:

| Path | What changes |
|---|---|
| `app/Services/Invoicing/InvoiceLifecycle.php` | Send mail during `issue()`; rollback on SMTP failure |
| `app/Http/Controllers/InvoiceController.php` | Catch mail/transport failures and flash user-facing errors |
| `app/Http/Controllers/SettingsController.php` | Add `show()` and `updateProfile()` |
| `routes/web.php` | Add `/settings`, `/settings/profile`, `/api/search`; replace Reports closure with controller only if useful |
| `routes/console.php` | Schedule reminders at 09:00, overdue stamps daily, backups at 03:00 |
| `resources/js/Layouts/AppLayout.vue` | Mount command palette and shortcut handler |
| `resources/js/Components/Topbar.vue` | Enable command palette button |
| `resources/js/Pages/Timer/Today.vue` | Let global `n` open the manual entry form; update shortcut copy |
| `resources/js/Pages/Reports/Placeholder.vue` | Match design placeholder copy |
| `resources/css/base.css` | Add minimal palette/settings form styles if existing classes are not enough |
| `tests/Feature/SmokeTest.php` | Include `/settings` once page lands |
| `docs/superpowers/phase-2b-carryover.md` | Mark 2b-ii complete; leave Phase 3/pending notes |

---

## Conventions

- **Branch:** create `phase-2b-ii-send-search-settings` from `main`.
- **Commands:** run through DDEV: `ddev artisan ...`, `ddev npm run build`.
- **Mail:** use Laravel Mail with SMTP config. Use `business_profile.email` as `from`/`replyTo` when set; otherwise fall back to `config('mail.from')`.
- **Invoice send invariant:** send from the UI is all-or-nothing from the database perspective. If PDF or SMTP fails, invoice remains `draft`, no `sent` event is written, and the controller flashes an error.
- **Reminder invariant:** reminders never change invoice status. They write `invoice_events.reminded` only after a send/job is accepted successfully.
- **Overdue invariant:** `overdue` remains computed (`status='sent' AND due_on < today`); the scheduled job only writes a one-time `invoice_events.overdue_stamped`.
- **Search:** return up to 8 mixed results shaped exactly as spec: `{ type, id, label, sublabel, url }`.
- **Vue paths:** use literal hrefs/URLs, matching shipped pages.
- **Tests:** each task ends green for the targeted feature tests; final task runs full `ddev artisan test` and `ddev npm run build`.

---

## Task 0: Branch + baseline

- [ ] **Step 1: Branch off main**

```bash
host$ git checkout main
host$ git pull
host$ git checkout -b phase-2b-ii-send-search-settings main
host$ git status
```

Expected: clean working tree on the new branch.

- [ ] **Step 2: Confirm baseline**

```bash
host$ ddev artisan test
host$ ddev npm run build
```

Expected: the Phase 2b-i baseline is green (carryover says 166 tests) and Vite builds.

No commit.

---

## Task 1: Settings/Profile page for `business_profile`

Editable business profile should land first because invoice mail and QR-bill sender identity depend on it.

- [ ] **Step 1: Add request validation**

Create `UpdateBusinessProfileRequest` validating existing columns:

- `name` required string max 255
- address fields nullable strings
- `country` required 2-char uppercase
- `uid`, `vat_id`, `iban`, `qr_iban`, `email`, `logo_path` nullable strings, with `email` email-validated
- `default_currency` required `in:CHF`
- `default_vat_rate` numeric between `0` and `100`
- `invoice_number_prefix` nullable string max 20
- `reminder_days_after_due` integer between `1` and `60`

Do not add a payment-terms/due-days field in this phase; the schema/spec table does not currently define one, and `InvoiceLifecycle` already uses 30 days.

- [ ] **Step 2: Extend `SettingsController`**

Add:

- `show()` renders `Settings/Profile` with `profile => BusinessProfile::current()`.
- `updateProfile(UpdateBusinessProfileRequest $request)` updates the singleton row and redirects back with success flash.
- Keep `updateTweaks()` exactly compatible with the existing tweaks panel.

- [ ] **Step 3: Add routes**

Add authenticated routes:

```php
Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
Route::patch('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
```

Keep `/settings/tweaks` as-is.

- [ ] **Step 4: Build `resources/js/Pages/Settings/Profile.vue`**

Use the existing app chrome, table/form styling, and a compact operations-tool layout:

- Business identity: name, email, UID, VAT ID.
- Address: line 1, line 2, postal code, city, country.
- Banking: IBAN, QR-IBAN.
- Invoice defaults: currency read-only/locked to CHF, VAT rate, invoice number prefix, reminder cadence.
- Save button with processing state and validation errors.
- Include the existing tweaks controls by either linking users to the topbar gear or rendering a small "Tweaks" section that uses the same `useTweaks()` API. If adding a second consumer of `useTweaks()`, first refactor `useTweaks` to a module-scope singleton as warned in carryover.

- [ ] **Step 5: Tests**

Add `SettingsProfileTest`:

- authenticated `/settings` renders `Settings/Profile` with current profile props
- `PATCH /settings/profile` updates profile and flashes success
- invalid country/email/VAT/rate/reminder values are rejected
- unauthenticated access redirects to login

Update `SmokeTest` dataset to include `/settings`.

- [ ] **Step 6: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/Http/SettingsProfileTest.php tests/Feature/SettingsTweaksTest.php tests/Feature/SmokeTest.php
host$ ddev npm run build
host$ git add app/Http/Controllers/SettingsController.php app/Http/Requests/UpdateBusinessProfileRequest.php routes/web.php resources/js/Pages/Settings/Profile.vue resources/js/composables/useTweaks.js tests/Feature/Http/SettingsProfileTest.php tests/Feature/SettingsTweaksTest.php tests/Feature/SmokeTest.php
host$ git commit -m "feat(settings): edit business profile"
```

Adjust `git add` paths if `useTweaks.js` does not need the singleton refactor.

---

## Task 2: Invoice send email

Add real outbound invoice mail to the existing `InvoiceLifecycle::issue()` path.

- [ ] **Step 1: Create mail views**

Create `resources/views/emails/invoices/sent.blade.php`.

Keep bodies plain and durable:

- greeting to client/contact if present
- invoice number
- total CHF
- due date
- short note that the PDF is attached
- sender name/business profile footer

Use Blade conditionals, not complex mail CSS.

- [ ] **Step 2: Create mailables**

Create `InvoiceMail`:

- accepts an `Invoice` and storage-relative PDF path
- loads `client`, `project`, `lines`
- subject like `Rechnung {number} - {business name}`
- `from`/`replyTo` from `BusinessProfile::current()->email` when present, fallback to `config('mail.from.address')`
- attaches `Storage::disk('local')->path($pdfPath)` as `Rechnung-{number}.pdf`, MIME `application/pdf`
- renders the sent mail view

- [ ] **Step 3: Refactor `InvoiceLifecycle::issue()`**

Keep the method name. Inside the transaction:

1. Reject non-draft invoices.
2. Reject invoices whose client has no email with a `DomainException`.
3. Stamp `status='sent'`, `issued_on=today`, `due_on=today+30`, `sent_at=now`.
4. Refresh and render/cache the PDF.
5. Send `InvoiceMail` synchronously.
6. Write `pdf_generated` and `sent` events with payload including `email_to` and `pdf_path`.

If step 4 or 5 throws, the transaction must roll back. Do not leave the invoice as `sent`.

- [ ] **Step 4: Controller error handling**

Update `InvoiceController::send()` to catch:

- `DomainException` for invalid state/missing email
- `RuntimeException` for PDF/QR errors
- mail transport/other `Throwable` for SMTP failures

Flash a concise user-facing error. Log the underlying exception for non-domain failures.

- [ ] **Step 5: Tests**

Extend `InvoiceLifecycleTest` or add `InvoiceSendMailTest`:

- sending a draft sends `InvoiceMail`, marks invoice sent, stamps dates, writes `pdf_generated` + `sent` events
- sent event payload includes recipient and path
- missing client email keeps draft and flashes/reports an error
- simulated mail failure keeps draft and writes no `sent` event
- non-draft invoice still raises the existing domain error

Use `Mail::fake()` for success. For failure, bind a tiny fake mailer/service or use Laravel's mail fake/mock approach that throws from the send path.

- [ ] **Step 6: Manual DDEV mail check**

With Mailhog/DDEV mail configured, create a draft invoice for a client with email, click Send, and confirm:

- flash success appears
- invoice status is sent
- activity shows PDF generated + sent
- Mailhog has one message with the PDF attachment

- [ ] **Step 7: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/Services/InvoiceLifecycleTest.php tests/Feature/Http/InvoiceControllerTest.php tests/Feature/Mail/InvoiceMailTest.php
host$ git add app/Mail/InvoiceMail.php resources/views/emails/invoices/sent.blade.php app/Services/Invoicing/InvoiceLifecycle.php app/Http/Controllers/InvoiceController.php tests/Feature/Services/InvoiceLifecycleTest.php tests/Feature/Http/InvoiceControllerTest.php tests/Feature/Mail/InvoiceMailTest.php
host$ git commit -m "feat(invoices): email sent invoices with pdf"
```

---

## Task 3: Reminder + overdue-stamp commands

Implement the two scheduled invoice maintenance jobs from spec §6.

- [ ] **Step 1: Reminder job**

Create `resources/views/emails/invoices/reminder.blade.php` and `InvoiceReminderMail`:

- subject like `Zahlungserinnerung Rechnung {number}`
- includes invoice number, total CHF, due date, and payment note
- uses `business_profile.email` for `from`/`replyTo` when present
- attaches the cached PDF when `pdf_path` exists

Create `SendInvoiceReminderMail`:

- implements `ShouldQueue`
- `onQueue('emails')`
- accepts invoice id
- reloads the invoice with client
- sends `InvoiceReminderMail`
- writes `invoice_events.reminded` with payload `email_to`, `days_overdue`
- exits quietly if invoice is no longer sent, no longer overdue, has no client email, or was recently reminded

- [ ] **Step 2: Reminder command**

Create `RemindInvoicesCommand`:

- signature: `ernte:invoices:remind`
- reads `BusinessProfile::current()->reminder_days_after_due` (min 1)
- selects invoices where:
  - `status = 'sent'`
  - `due_on <= today - reminder_days_after_due`
  - client has an email
  - no `reminded` event within the last `reminder_days_after_due` days
- dispatches `SendInvoiceReminderMail` on `emails`
- prints counts: queued, skipped missing email, skipped recent reminder

Interpretation: first reminder goes out N days after due; later reminders are spaced by N days.

- [ ] **Step 3: Overdue stamp command**

Create `StampOverdueInvoicesCommand`:

- signature: `ernte:invoices:stamp-overdue`
- selects sent invoices where `due_on < today`
- writes exactly one `overdue_stamped` event per invoice
- payload should include `due_on` and `days_overdue`
- does not change invoice status

- [ ] **Step 4: Schedule commands**

In `routes/console.php`, schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ernte:invoices:remind')->dailyAt('09:00');
Schedule::command('ernte:invoices:stamp-overdue')->daily();
```

Use the app timezone (`Europe/Zurich` in this workspace) by default; no custom timezone code unless tests prove it is needed.

- [ ] **Step 5: Tests**

Add tests:

- reminder command queues only sent overdue invoices past the configured reminder cadence
- reminder job writes `reminded` after mail success
- reminder command/job skips paid, draft, void, not-yet-due, missing-email, and recently-reminded invoices
- overdue command writes one `overdue_stamped` event for sent overdue invoices
- overdue command is idempotent on repeated runs

- [ ] **Step 6: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/Console/InvoiceReminderCommandTest.php tests/Feature/Console/OverdueStampCommandTest.php
host$ git add app/Console/Commands app/Jobs app/Mail/InvoiceReminderMail.php resources/views/emails/invoices/reminder.blade.php routes/console.php tests/Feature/Console/InvoiceReminderCommandTest.php tests/Feature/Console/OverdueStampCommandTest.php
host$ git commit -m "feat(invoices): scheduled reminders and overdue stamps"
```

---

## Task 4: Backup command

Implement the command behind the existing statusbar backup readout.

- [ ] **Step 1: Rename the model helper if touched**

Carryover notes that `Backup::latest()` shadows Eloquent's query-builder `latest()`. If the backup command needs another helper, rename to `Backup::mostRecent()` and update `HandleInertiaRequests`. If not, leave it alone to avoid churn.

- [ ] **Step 2: Create `BackupCommand`**

Signature: `ernte:backup`.

Behavior:

- create `storage/app/backups/{timestamp}/`
- run `mysqldump` for the current DB connection into `database.sql`
- gzip the SQL dump
- tar/gzip `storage/app/invoices` into `invoices.tar.gz` if the directory exists
- write a small `manifest.json` with app version, created_at, db connection/database, file list, and sizes
- write one `backups` row with `path` pointing to the backup directory or archive and `size_bytes` as total bytes
- print a summary line

Use Laravel `Process` or Symfony Process, not ad hoc shell string concatenation. Pull DB credentials from `config('database.connections.mysql')`.

- [ ] **Step 3: Testability**

Because CI/test environments may not have `mysqldump`, structure the command so the process runner can be faked. Tests should verify:

- command creates the backup directory/files with a fake dump process
- invoices tarball is optional when no invoices directory exists
- a `backups` row is written and statusbar `backup_last_at` can see it
- command returns non-zero and does not write a success row if dump fails

- [ ] **Step 4: Schedule**

In `routes/console.php`, add:

```php
Schedule::command('ernte:backup')->dailyAt('03:00');
```

- [ ] **Step 5: Manual DDEV check**

```bash
host$ ddev artisan ernte:backup
```

Expected: storage backup files are created, a `backups` row exists, and the statusbar shows a recent backup after page reload.

- [ ] **Step 6: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/Console/BackupCommandTest.php tests/Feature/InertiaPropsTest.php
host$ git add app/Console/Commands/BackupCommand.php app/Models/Backup.php app/Http/Middleware/HandleInertiaRequests.php routes/console.php tests/Feature/Console/BackupCommandTest.php tests/Feature/InertiaPropsTest.php
host$ git commit -m "feat(backups): add scheduled backup command"
```

Adjust paths if `Backup::latest()` is not renamed.

---

## Task 5: `/api/search`

Build the JSON endpoint for command palette search.

- [ ] **Step 1: Controller**

Create `SearchController@__invoke`.

Rules:

- authenticated only
- `q` trimmed; empty query returns a useful default list, or `[]` if that feels cleaner in the palette. Prefer defaults: recently updated projects/invoices and active clients.
- optional `type=project|client|invoice` narrows results for the project-switch palette
- non-empty query uses `LIKE` over:
  - projects: `name`, `code`, client name
  - clients: `name`, `short_code`, `contact_name`, `email`
  - invoices: `number`, client name
- return up to 8 results total
- shape: `{ type: 'project'|'client'|'invoice', id, label, sublabel, url }`

URL decisions:

- project: `/projects/{code}`
- invoice: `/invoices/{number}`
- client: `/clients/{id}/edit` until a real `Clients/Show` exists

Add a TODO/carryover note for retargeting client results when `Clients/Show` lands.

- [ ] **Step 2: Route**

Add:

```php
Route::get('/api/search', SearchController::class)->name('api.search');
```

Inside the existing auth group.

- [ ] **Step 3: Tests**

`SearchControllerTest` should cover:

- unauthenticated redirect/forbidden behavior matches web auth
- empty query returns at most 8 rows
- project search finds name/code/client matches
- client search finds name/email/contact matches
- invoice search finds number/client matches
- result URLs match current route reality
- result count never exceeds 8

- [ ] **Step 4: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/Http/SearchControllerTest.php
host$ git add app/Http/Controllers/SearchController.php routes/web.php tests/Feature/Http/SearchControllerTest.php
host$ git commit -m "feat(search): add mixed command palette endpoint"
```

---

## Task 6: Command palette + keyboard shortcuts

Wire the UX in spec §7 and the keyboard map from the route table.

- [ ] **Step 1: Build `CommandPalette.vue`**

Requirements:

- opened by a prop or exposed `open()` method from `AppLayout`
- one search input
- debounce calls to `/api/search?q=...`
- show up to 8 results
- arrow up/down changes active result
- Enter visits the active result with Inertia router
- Escape closes
- click result visits
- loading and empty states are compact
- result type is visible but quiet (`project`, `client`, `invoice`)

Use existing design language. Add minimal CSS classes such as `.palette-backdrop`, `.palette`, `.palette-row` only if existing `.search`, `.table`, `.btn`, `.kbd` classes are insufficient.

- [ ] **Step 2: Enable Topbar button**

Change `Topbar.vue`:

- remove `disabled`
- emit or call a callback to open the palette
- keep the existing `⌘K` visual

If prop drilling feels awkward, mount the button behavior in `AppLayout` with a document event, but keep it simple.

- [ ] **Step 3: Shortcut composable**

Create `useKeyboardShortcuts.js` and mount it in `AppLayout.vue`.

Implement:

- `⌘K`/`Ctrl+K`: open command palette
- `/`: focus the first visible filter/search input on the current page
- `g d`: visit `/projects`
- `g t`: visit `/timer`
- `g c`: visit `/clients`
- `g i`: visit `/invoices`
- `n`: open manual entry affordance
- `space`: if a timer is running, stop it; if no timer is running, open project switch palette instead of guessing a project
- `⌘P`/`Ctrl+P`: open project switch palette

Guardrails:

- Ignore shortcuts while typing in input/textarea/select/contenteditable, except Escape.
- Ignore repeated keydown.
- Keep the `g` sequence timeout short (~900ms).
- Do not hijack browser print if a text field is focused.

- [ ] **Step 4: Manual entry event**

The current manual-entry form lives inside `Timer/Today.vue`. For 2b-ii, `n` should:

- if already on `/timer`, dispatch a local event that opens the inline manual entry form
- if elsewhere, visit `/timer?manual=1`; `Timer/Today.vue` reads the query/shared URL and opens the form

Do not build a global manual-entry modal in this phase unless the inline route approach becomes clumsy.

- [ ] **Step 5: Project switch palette**

For `⌘P`/space-when-idle:

- either reuse `CommandPalette` in `mode="project"` against `/api/search?type=project` or filter client-side from mixed results
- selecting a project posts `/timer/start` with `project_id`
- if a timer is already running, this effectively switches because `TimerService::start()` stops the old one first

If this is too large, implement `⌘P` as opening the normal command palette with a project-only initial filter, and leave "start selected project" as a carryover. The `space` shortcut must not start an arbitrary project.

- [ ] **Step 6: Timer shortcut copy**

Update `Timer/Today.vue` shortcuts panel to remove "(shortcuts ship in Phase 2b)" and list the now-working shortcuts.

- [ ] **Step 7: Tests + browser QA**

Feature tests:

- `/api/search` already covered in Task 5
- no PHP feature tests needed for keydown behavior beyond the timer endpoints already tested

Manual/browser QA:

- `⌘K` opens palette from topbar and keyboard
- typing finds project/client/invoice; Enter navigates
- `g d/t/c/i` navigate
- `/` focuses page filter on projects/invoices/clients
- `n` opens manual entry on timer or navigates to timer with it open
- `space` stops a running timer; when idle, opens project switch instead of starting the wrong thing

- [ ] **Step 8: Verify + commit**

```bash
host$ ddev npm run build
host$ ddev artisan test tests/Feature/Http/SearchControllerTest.php tests/Feature/Http/TimerControllerTest.php
host$ git add resources/js/Components/CommandPalette.vue resources/js/Components/Topbar.vue resources/js/Layouts/AppLayout.vue resources/js/Pages/Timer/Today.vue resources/js/composables/useKeyboardShortcuts.js resources/css/base.css
host$ git commit -m "feat(ui): command palette and global shortcuts"
```

---

## Task 7: Reports placeholder polish

Small but finish the spec/design item cleanly.

- [ ] **Step 1: Align copy**

Update `Reports/Placeholder.vue` to match the design:

- title: Reports
- meta: Last 90 days
- placeholder text: "Reports view — utilization, revenue, project profitability."

Keep it deliberately non-functional. This is not a reports implementation phase.

- [ ] **Step 2: Optional controller**

The current route closure is acceptable. If consistency matters, add a tiny `ReportController@show`; otherwise leave the closure alone.

- [ ] **Step 3: Verify + commit**

```bash
host$ ddev artisan test tests/Feature/SmokeTest.php
host$ ddev npm run build
host$ git add resources/js/Pages/Reports/Placeholder.vue tests/Feature/SmokeTest.php
host$ git commit -m "polish(reports): align placeholder copy"
```

If a controller is created, also add `routes/web.php` and `app/Http/Controllers/ReportController.php`.

---

## Task 8: Final QA + carryover

- [ ] **Step 1: Full automated verification**

```bash
host$ ddev artisan test
host$ ddev npm run build
```

Expected: all tests pass and Vite build succeeds.

- [ ] **Step 2: Manual smoke flow**

In the browser:

1. `/settings`: edit business email/IBAN/reminder cadence and save.
2. `/invoices`: open a draft invoice for a client with email; Send; confirm Mailhog and activity.
3. Run `ddev artisan ernte:invoices:stamp-overdue`; confirm overdue activity appears once.
4. Run `ddev artisan ernte:invoices:remind`; confirm reminder job/mail behavior.
5. Run `ddev artisan ernte:backup`; reload; statusbar backup age updates.
6. Use `⌘K`, `/`, `g d`, `g t`, `g c`, `g i`, `n`, and timer `space`.
7. `/reports`: placeholder copy matches design.

- [ ] **Step 3: Update carryover**

Update `docs/superpowers/phase-2b-carryover.md`:

- mark 2b-ii complete
- record final test count/build status
- keep Phase 3 items: production Docker, `bin/install`, README
- keep still-valid known-pending notes, especially:
  - real `Clients/Show` and search result retargeting
  - Projects/Show secondary tabs
  - remaining EUR-to-CHF sweep outside invoice pages
  - GET `/invoices/{number}/pdf` side-effect caveat if still unresolved
  - Browsershot production Chromium requirements

- [ ] **Step 4: Commit final docs**

```bash
host$ git add docs/superpowers/phase-2b-carryover.md docs/superpowers/plans/2026-05-28-ernte-phase-2b-ii-send-search-settings.md
host$ git commit -m "docs: update carryover after Phase 2b-ii"
```

- [ ] **Step 5: Final merge checklist**

Before merging:

- [ ] full test suite green
- [ ] build green
- [ ] no failed jobs from manual mail/reminder checks
- [ ] Mailhog verified for invoice send and reminder
- [ ] statusbar shows fresh backup after command
- [ ] command palette usable by mouse and keyboard
- [ ] carryover updated with exact test count

---

## Acceptance checklist

- [ ] A draft invoice can be sent by email with a PDF attachment.
- [ ] SMTP/PDF failures keep the invoice as draft and flash an error.
- [ ] Sent invoice activity shows `pdf_generated` and `sent`.
- [ ] Reminder command queues/sends reminders only when cadence rules allow.
- [ ] Overdue-stamp command writes exactly one `overdue_stamped` event per overdue sent invoice.
- [ ] `/settings` edits `business_profile`, including QR-bill banking and reminder cadence fields.
- [ ] `/reports` is the designed placeholder.
- [ ] `/api/search` returns mixed project/client/invoice results, max 8.
- [ ] Topbar `⌘K` opens a keyboard-navigable palette.
- [ ] Keyboard shortcuts from the spec are implemented with input-focus guardrails.
- [ ] `ernte:backup` creates backup artifacts and writes a `backups` row.
- [ ] Scheduler contains reminder, overdue-stamp, and backup jobs.
- [ ] Full tests and Vite build pass.
