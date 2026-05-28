# Phase 2b carryover notes

Hand-off from the Phase 2a/2b build train. Captures what shipped, what was deliberately deferred, and the items that should shape the production deployment phase. Not bugs — forward-compat hooks and reminders. Read this alongside the spec (`docs/superpowers/specs/2026-05-27-ernte-design.md`, especially §8 deployment) before writing the Phase 3 plan. **Deployment direction has changed:** Phase 3 should target Laravel Forge, not Docker/docker-compose.

## State of the app after Phase 2a

Working end-to-end: Projects (index + show overview tab), Tasks (CRUD + reorder + inline add), Timer (start/stop/switch/discard + Today page), Manual entries (CRUD + inline form), Clients (index + create + edit + archive). Chrome is live: running-timer chip, sidebar pinned/recent/week-bars, statusbar db-size + backup readout. 129 tests passing. CSS is the verbatim port from `design/ernte/project/styles.css` — **Phase 2a added no new CSS**; Phase 2b should keep hand-rolling and reuse existing classes.

> **Correction (found during 2b-i):** the Phase-2a CSS port was actually **incomplete** — `resources/css/base.css` only ported the chrome subset of `styles.css`. Page-level classes (`.table*`, `.stats*`, `.invoice-*`, `.heat*`, `.detail-grid`, `.task-*`, `.entry-row*`, `.timer-*`, `.spark*`) were referenced by shipped pages but missing from the compiled bundle, so those pages rendered unstyled. **2b-i Task 2 completed the port** (verbatim from `styles.css`), which also retroactively styled the Phase-2a tables/charts. The "keep reusing existing classes" guidance now holds because the classes finally all exist.

## Status after Phase 2b-i (Invoices core — MERGED to `main`)

Merged to `main` (merge commit `f3a9a19`; branch `phase-2b-i-invoices` deleted), plus a follow-up fix (`16dd7fd`) so the top-level "New invoice" button renders a client picker instead of 404ing. **166 tests passing, build clean.** Done:

- **Invoices/Index** — stats strip (outstanding/overdue/paid-ytd/avg-days) + status filter tabs + search + table (CHF, German-aware). `InvoiceController@index` + `InvoiceProjections`.
- **Invoices/Create** — period picker (default previous month), billable+unbilled+**finished** entry checklist, server-suggested line grouping, editable line table with per-line `vat_exempt`, live CHF totals. `create`/`store` + `StoreInvoiceRequest` + `InvoiceBuilder::suggestLinesFromEntries`/`createDraft`. `/invoices/new` with no `?client=` renders a **client-picker mode** (the top-level "New invoice" button); the project/client `+ Invoice` buttons deep-link with `?client=`.
- **Invoices/Show** — document via an `<iframe>` to `/invoices/{number}/preview` (the same Blade the PDF uses), activity sidebar from `invoice_events`, linked-entries summary, status-gated actions (Send / Mark paid / Void) + draft `update` (notes + lines).
- **PDF** — `spatie/browsershot` v5 (drives the container Chromium; needs `puppeteer` npm + `->noSandbox()` + a `config('services.browsershot.chrome_path')` key). `InvoicePdfRenderer` (html() + cached pdf()). Document Blade is German + CHF.
- **Swiss QR-bill** — `sprain/swiss-qr-bill` **v5** (NOT v4 — API differs: `StructuredAddress`, `DisplayOptions::setPrintable`). `QrBillRenderer` builds the payment part, validates via `getViolations()`, QRR when `qr_iban` else NON, self-heals a missing `qr_reference`.
- **Lifecycle** — `InvoiceLifecycle::issue()` (draft→sent: stamp dates, render+cache PDF, write `pdf_generated`+`sent` events — **NO email yet**), `markPaid()`, `void()` (releases linked entries — the Phase-1 carryover bug). `send`/`pdf` controller routes wire the Show buttons.
- **Projections** — real `outstanding` (per-client + global) + `paid_ytd` + `avg_days_to_pay`; the Phase-2a `outstanding = 0` placeholders are gone.
- **Entry points** — `+ Invoice` enabled on Projects/Show and per-row on Clients/Index.
- **Demo seeder** — `DemoFixturesSeeder` now seeds a business profile (QR-IBAN) + a draft + a sent/overdue invoice.

## Status after Phase 2b-ii (Send/search/settings — implemented)

Implemented on branch `phase-2b-ii-send-search-settings`; plan doc: `docs/superpowers/plans/2026-05-28-ernte-phase-2b-ii-send-search-settings.md`. **190 tests passing, build clean.** Browser QA covered Settings/Profile, the command palette/search flow, project navigation, and timer shortcuts. Command smoke covered backup, overdue stamping, and reminder dispatch.

Done:

- **Send email + reminders** — `InvoiceMail` is wired into `InvoiceLifecycle::issue()` with PDF attachment and rollback-on-mail-failure behavior. `InvoiceReminderMail` is sent via queued `SendInvoiceReminderMail` jobs from `ernte:invoices:remind`. Daily schedule at 09:00.
- **Overdue stamping** — `ernte:invoices:stamp-overdue` flips sent invoices past due to overdue and writes `invoice_events.overdue_stamped`. Daily schedule.
- **Settings/Profile** — `Settings/Profile` edits the singleton `business_profile` record, so QR-bill creditor fields are editable in-app. Existing tweak persistence remains under `/settings/tweaks`.
- **Reports** — `Reports/Placeholder` now renders the design's coming-soon placeholder with scoped metadata instead of an empty shell.
- **⌘K palette + `/api/search`** — `SearchController@__invoke` returns mixed project/client/invoice results; `CommandPalette` supports global search and project switch/start mode.
- **Keyboard shortcuts** — `AppLayout` owns `space`, `n`, `⌘P`, `g d/t/c/i`, `/`, and `⌘K`, with text-input guards.
- **Backup command** — `ernte:backup` creates a gzipped database dump, optional invoices tarball, manifest, and `backups` row. Daily schedule at 03:00.

## Status after Phase 3 (Laravel Forge deployment — implemented)

Implemented on branch `phase-3-forge-deployment`. **193 tests passing, build clean.** Local smoke covered `ernte:doctor`, shell syntax for the Forge scripts, command registration, and the full app test suite.

Done:

- **Forge runbook** — `docs/deployment/forge.md` documents server assumptions, environment setup, deployment, queue daemon, scheduler, backup/restore, and smoke testing.
- **Forge env template** — `.env.forge.example` lists production values for Forge, SMTP, database, app identity, Browsershot, bootstrap user, and business profile.
- **Forge deploy script** — `deploy/forge/deploy.sh` pulls `main`, installs Composer/npm dependencies, builds assets, prepares storage, migrates, seeds, warms safe caches, restarts queues, and runs `ernte:doctor`.
- **Forge provision recipe** — `deploy/forge/provision-chrome-and-backups.sh` installs Google Chrome for Browsershot plus `mysqldump` support for backups.
- **Runtime doctor** — `ernte:doctor` checks app key, HTTPS `APP_URL` in production, database connectivity/tables, business profile, writable storage, queue, mailer, Chromium, and `mysqldump`.
- **Production footer host** — the status bar now displays the host derived from `APP_URL` instead of hardcoded `localhost`.
- **Timezone config** — `APP_TIMEZONE` is now honored by `config/app.php`.

## Post-deploy polish now done

- **QR-IBAN production guardrail** — the PDF renderer no longer 500s when a normal IBAN is accidentally stored in `qr_iban`; Settings rejects that mismatch and `ernte:doctor` warns about it.
- **Currency sweep** — Projects, Clients, Timer, and shared budget/timer components now display CHF instead of the design mock's EUR labels.
- **Draft PDF route** — draft PDF downloads render transient bytes and do not write `pdf_path`; issued invoices still use the cached storage path.
- **Void confirmation** — the invoice detail void action now asks for confirmation before releasing linked entries.
- **Paid YTD semantics** — invoice stats now key `paid_ytd` on `paid_at` year instead of `issued_on`.
- **Timer switch path** — `TimerController::switch()` now calls `TimerService::switch()` directly.

## Items deferred from the Phase-1 carryover

**All resolved in 2b-i** (kept here for the record): `Client::invoices()` added; `void()` clears entries (in `InvoiceLifecycle`, not the model); per-line `vat_exempt` flows through `createDraft`; `sprain/swiss-qr-bill` + `spatie/browsershot` installed (v5 — see notes below); `QrReferenceGenerator` reused (also now self-heals a missing reference). Detail:

- **`Client::invoices()` relationship.** Only `projects()` landed in 2a. `/invoices/new?client=…` and the client detail page need `invoices()`. One `hasMany` one-liner.
- **`Invoice::void()` must clear `time_entries.invoice_id`.** FK is `ON DELETE SET NULL` but voiding sets `status='void'` without deleting — so voided invoices keep entries attached and those entries vanish from "unbilled" scopes. Implement a transactional domain method that flips status AND nulls the linked entries' `invoice_id`.
- **`InvoiceBuilder::computeTotals()` already handles `vat_exempt` lines** (splits taxable from exempt before VAT). The builder currently hardcodes `vat_exempt: false` because time entries carry no exempt flag. The line-editor UI in `Invoices/Create` is where the exempt toggle goes — pass it through to the builder.
- **`InvoiceFactory` produces test-only numbers** (`2026-T#####`). When asserting on production number format (`YYYY-NNN`), call `InvoiceNumberer::nextFor()` directly instead of the factory.
- **`sprain/swiss-qr-bill` is not yet required.** `composer require` it in 2b. Phase 1's `QrReferenceGenerator` (mod-10 recursive) stays for reference generation; the library is for SVG rendering — they don't collide.
- **Browsershot is ready to use.** DDEV web image has Chromium at `/usr/bin/chromium`; `BROWSERSHOT_CHROME_PATH` is set. `composer require spatie/browsershot` and go — no further system setup.

## Placeholders wired in Phase 2a to swap when Invoices ship

- **`outstanding` is hardcoded to 0** — ✅ DONE in 2b-i. Both `ClientProjections::index()` and `DashboardProjections::stats()` now compute it from `InvoiceProjections` (sent invoices).
- **`Clients/Index.vue` row sparkline is a hardcoded literal** `[2,3,1,4,5,…]` for every row. `ClientProjections` supplies no per-client activity series yet. Either feed real 14-day data or drop to an empty/dim placeholder.
- **`ClientController` resource is registered `->except(['show'])`.** Phase 2b likely wants a `Clients/Show` (the design's client detail). When it lands, retarget `pushRecent` in `Clients/Edit.vue` (currently labels the recent entry as `/clients/{id}/edit`).
- **`Projects/Show` only renders the Overview tab.** Entries/Tasks/Team/Settings are disabled chips with correct counts (`counts.entries`, `counts.tasks`). Wiring the tab bodies is outstanding.
- **Topbar ⌘K button is enabled** — ✅ DONE in 2b-ii. It opens the command palette, backed by `/api/search`.

## New known-pending items (surfaced during 2b-i build + code review — none block 2b-ii)

Deferred deliberately during 2b-i (the code works for the single-user happy path; these are revisit-when-relevant):

- **`DashboardProjections::stats()` calls the full `InvoiceProjections::stats()` just to read `outstanding`** — 4 wasted queries per dashboard load. Add a dedicated `outstandingTotal()` if it ever shows up in profiling.
- **`invoice_events.created` payload `entries_count`** records the *submitted* id count, not the actual attached-rows count (the attach is guarded by billable+unbilled). Informational only; accurate in the normal Create flow.
- **`Invoices/Create` period change discards line edits.** Changing the from/to date does a full `router.get` reload (`preserveState: false`); a disclaimer warns the user but there's no dirty-state confirm. Intentional for 2b-i.
- **The line-rate editor works in whole CHF.** `Invoices/Create` (and the future draft-edit UI) post `rate_rappen = round(rate_CHF * 100)`, so sub-franc project rates lose precision on round-trip. `Invoices/Show` emits each line's `rate` in CHF but `InvoiceController::update()` expects `rate_rappen` — **a future draft-edit UI must post `rate_rappen`, not the `rate` field `show()` emits.**
- **Browsershot + synchronous SMTP run inside the `issue()` DB transaction.** This preserves 2b-ii's rollback-on-mail-failure requirement. Harmless single-user; for multi-user, move rendering/email to an outbox-style flow with a shorter status transaction.
- **QR-bill plain-IBAN path uses reference type `NON`** (no reference). Implement SCOR if a non-QR-IBAN business profile is ever used in anger.
- **Production Browsershot needs three things** now codified in 2b-i: `puppeteer` in `package.json` (installed with `PUPPETEER_SKIP_DOWNLOAD=true`), `->noSandbox()` in `InvoicePdfRenderer`, and the `BROWSERSHOT_CHROME_PATH` → `config('services.browsershot.chrome_path')` key. Phase 3 (Laravel Forge) must install/provide Chromium on the server and set that env var to the real binary path. The carryover's old "no further system setup" note was incomplete.
- **PDF determinism (spec §9 #7):** `pdf_path` is cached at issue time; the `pdf` route re-renders only if the file is missing. Drafts are the only editable state and have no issued PDF, so there's no stale-PDF risk today — but revisit if issued invoices ever become editable.

## Smaller findings worth a sweep (none block 2b)

- **`EntryController` ownership style is split:** `update` enforces user-ownership via `UpdateEntryRequest::authorize()`, `destroy` via an inline `abort_if`. Both tested, both work; unify if you touch it. (Single-user app, so not load-bearing — but the pattern matters if multi-user ever lands.)
- **Filter/search pattern diverges:** `ProjectController::index` filters/searches server-side via query params (round-trip per keystroke, debounced); `ClientController::index` returns all rows and filters in-memory in Vue. Fine at current scale; align before either list grows large.
- **`SidebarProps::pinnedProjects` uses LEFT JOIN + GROUP BY** with `projects.created_at` in the ORDER BY but not the GROUP BY. DDEV's MariaDB runs without `ONLY_FULL_GROUP_BY` so it's fine today; add `projects.created_at` to the GROUP BY if a stricter sql_mode is ever enabled.
- **`Backup::latest()` still shadows Eloquent's query-builder `latest()`** (returns a `?Backup`, not a builder). The statusbar is the only caller; the 2b-ii backup command writes via `Backup::create()`. Rename to `mostRecent()` if a second caller wants a builder.
- **`TimerHero.vue` reads `running.project.rate_rappen` directly** to compute the live ticking earnings — the one intentional place a `_rappen` field crosses into Vue. Everything else converts to CHF at the projection seam. Keep this invariant in 2b.
- **`useTweaks` singleton-drift footgun** (from Phase 1) still applies — if a second consumer of `useTweaks()` is added, refactor to a module-scope singleton first.

## Suggested handoff prompt for the new conversation

> Continue Ernte polish after Phase 3; read the phase-2b carryover doc first.

Phase 3 shipped the Laravel Forge deployment path and the first post-deploy polish pass is in. Good next candidates are larger product surfaces: real `Clients/Show`, Projects/Show secondary tabs, replacing the hardcoded Clients sparkline with real activity, SCOR references for plain-IBAN QR bills, and a draft-edit UX pass around line rates/dirty period changes.
