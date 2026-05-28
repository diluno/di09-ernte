# Phase 2b carryover notes

Hand-off from the Phase 2a build (merged to `main`, tag `phase-2a`). Captures what Phase 2a left as placeholders, what it deferred, and the items from the original Phase-1 carryover that belong to Phase 2b. Not bugs — forward-compat hooks and reminders. Read this alongside the spec (`docs/superpowers/specs/2026-05-27-ernte-design.md` §6 invoice flow, §7 search/shortcuts) before writing the Phase 2b plan.

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

## Phase 2b-ii scope (what's left — the next plan)

The invoice subsystem (Index/Create/Show/PDF/QR-bill, above) shipped in 2b-i. Remaining for **2b-ii**:

- **Send email + reminders** — wrap `InvoiceMail` (SMTP, `emails` queue) into the existing `InvoiceLifecycle::issue()` (which already issues + renders the PDF but does NOT mail). `ernte:invoices:remind` daily job at 09:00; daily overdue-stamp job writing `invoice_events.overdue_stamped`. SMTP failure must keep status `draft`/`sent` and surface a flash error.
- **Settings/Profile** — `Settings/Profile` page editing `business_profile` (the tweaks panel already persists; the profile form is new). Needed so the QR-bill creditor (IBAN/QR-IBAN/address) is editable in-app rather than seeded.
- **Reports** — the design's "coming soon" placeholder card (`Reports/Placeholder`).
- **⌘K palette + `/api/search`** — `SearchController@__invoke` returning mixed project/client/invoice results; keyboard map (space, n, ⌘P, g d/t/c/i, /, ⌘K) handled in `AppLayout`.
- **Backup command** — `ernte:backup` (mysqldump + invoices tarball) writing a `backups` row so the statusbar shows a real time instead of "never". Daily at 03:00.

(Production docker-compose + `bin/install` + README is Phase 3, not 2b.)

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
- **Topbar ⌘K button is `disabled`** (title "Phase 2b"); `/api/search` is not implemented.

## New known-pending items (surfaced during 2b-i build + code review — none block 2b-ii)

Deferred deliberately during 2b-i (the code works for the single-user happy path; these are revisit-when-relevant):

- **`paid_ytd` is keyed on `issued_on` year, not `paid_at`.** `InvoiceProjections::stats()` counts paid invoices *issued* this year. "Paid YTD" arguably means cash *received* this year (`paid_at`). Revisit the accounting semantics (and the test) if it matters.
- **`DashboardProjections::stats()` calls the full `InvoiceProjections::stats()` just to read `outstanding`** — 4 wasted queries per dashboard load. Add a dedicated `outstandingTotal()` if it ever shows up in profiling.
- **`invoice_events.created` payload `entries_count`** records the *submitted* id count, not the actual attached-rows count (the attach is guarded by billable+unbilled). Informational only; accurate in the normal Create flow.
- **`Invoices/Create` period change discards line edits.** Changing the from/to date does a full `router.get` reload (`preserveState: false`); a disclaimer warns the user but there's no dirty-state confirm. Intentional for 2b-i.
- **The line-rate editor works in whole CHF.** `Invoices/Create` (and the future draft-edit UI) post `rate_rappen = round(rate_CHF * 100)`, so sub-franc project rates lose precision on round-trip. `Invoices/Show` emits each line's `rate` in CHF but `InvoiceController::update()` expects `rate_rappen` — **a future draft-edit UI must post `rate_rappen`, not the `rate` field `show()` emits.**
- **Browsershot runs inside the `issue()` DB transaction.** A multi-second Chromium render holds the invoice row lock for its duration. Harmless single-user; for multi-user, move the render outside the transaction (stamp status/dates, commit, then render + a short `pdf_path` update).
- **`GET /invoices/{number}/pdf` is side-effecting** — it renders + writes `pdf_path` on a cache miss, even for drafts (the "Download PDF" button shows unconditionally). A GET should be safe; restrict PDF to issued invoices or stream a transient render for drafts.
- **`Void` has no confirmation dialog** and is shown on drafts too (lifecycle allows draft→void). Add a confirm prompt for the destructive action.
- **QR-bill plain-IBAN path uses reference type `NON`** (no reference). Implement SCOR if a non-QR-IBAN business profile is ever used in anger.
- **Production Browsershot needs three things** now codified in 2b-i: `puppeteer` in `package.json` (installed with `PUPPETEER_SKIP_DOWNLOAD=true`), `->noSandbox()` in `InvoicePdfRenderer`, and the `BROWSERSHOT_CHROME_PATH` → `config('services.browsershot.chrome_path')` key. Phase 3 (production docker-compose) must provide Chromium at that path. The carryover's old "no further system setup" note was incomplete.
- **PDF determinism (spec §9 #7):** `pdf_path` is cached at issue time; the `pdf` route re-renders only if the file is missing. Drafts are the only editable state and have no issued PDF, so there's no stale-PDF risk today — but revisit if issued invoices ever become editable.

## Smaller findings worth a sweep (none block 2b)

- **`TimerController::switch` delegates to `start()`** rather than the existing `TimerService::switch()` — the service method is now dead code reachable only via direct calls. Either route through it or drop it.
- **`EntryController` ownership style is split:** `update` enforces user-ownership via `UpdateEntryRequest::authorize()`, `destroy` via an inline `abort_if`. Both tested, both work; unify if you touch it. (Single-user app, so not load-bearing — but the pattern matters if multi-user ever lands.)
- **Filter/search pattern diverges:** `ProjectController::index` filters/searches server-side via query params (round-trip per keystroke, debounced); `ClientController::index` returns all rows and filters in-memory in Vue. Fine at current scale; align before either list grows large.
- **`SidebarProps::pinnedProjects` uses LEFT JOIN + GROUP BY** with `projects.created_at` in the ORDER BY but not the GROUP BY. DDEV's MariaDB runs without `ONLY_FULL_GROUP_BY` so it's fine today; add `projects.created_at` to the GROUP BY if a stricter sql_mode is ever enabled.
- **`Backup::latest()` still shadows Eloquent's query-builder `latest()`** (returns a `?Backup`, not a builder). The statusbar is the only caller. Rename to `mostRecent()` if a second caller wants a builder — the backup command in 2b is a candidate.
- **`TimerHero.vue` reads `running.project.rate_rappen` directly** to compute the live ticking earnings — the one intentional place a `_rappen` field crosses into Vue. Everything else converts to CHF at the projection seam. Keep this invariant in 2b.
- **Currency symbol `€` → CHF sweep is now PARTIAL.** 2b-i made the **invoice pages + PDF** CHF (de-CH formatting) + German document labels. The Projects/Clients/Timer pages still render `€` (carried from the design's Berlin/EUR mock). Finish the sweep across those pages in a later pass.
- **`useTweaks` singleton-drift footgun** (from Phase 1) still applies — if a second consumer of `useTweaks()` is added, refactor to a module-scope singleton first.

## Suggested handoff prompt for the new conversation

> Write the Phase 2b-ii plan for ernte; read the spec § 6 (sending/reminders) + § 7 (search/shortcuts) and the phase-2b carryover doc first.

2b-i shipped the invoice subsystem (Index/Create/Show/PDF/QR-bill) — merged to `main`. 2b-ii covers what's listed under "Phase 2b-ii scope" above: email send + reminders + overdue-stamp job, Settings/Profile, Reports placeholder, ⌘K palette + `/api/search`, keyboard shortcuts, backup command. The email work hangs off the existing `InvoiceLifecycle::issue()` (it already issues + renders the PDF; just add the `InvoiceMail` dispatch + queue). The 2b-i plan lives at `docs/superpowers/plans/2026-05-28-ernte-phase-2b-i-invoices.md`.

2b-ii is still sizable (email + scheduler + settings + reports + ⌘K + shortcuts + backup) — consider splitting it the way Phase 2 and 2b were split (e.g. 2b-ii-a = send/reminders/overdue-stamp + scheduler/queue wiring; 2b-ii-b = settings/profile + reports + ⌘K/search + keyboard shortcuts + backup command), so each plan ships working, testable software on its own. Settings/Profile is worth doing early since the QR-bill creditor (IBAN/QR-IBAN/address) is currently only seedable, not editable in-app.
