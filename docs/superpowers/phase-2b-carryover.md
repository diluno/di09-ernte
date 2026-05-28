# Phase 2b carryover notes

Hand-off from the Phase 2a build (merged to `main`, tag `phase-2a`). Captures what Phase 2a left as placeholders, what it deferred, and the items from the original Phase-1 carryover that belong to Phase 2b. Not bugs — forward-compat hooks and reminders. Read this alongside the spec (`docs/superpowers/specs/2026-05-27-ernte-design.md` §6 invoice flow, §7 search/shortcuts) before writing the Phase 2b plan.

## State of the app after Phase 2a

Working end-to-end: Projects (index + show overview tab), Tasks (CRUD + reorder + inline add), Timer (start/stop/switch/discard + Today page), Manual entries (CRUD + inline form), Clients (index + create + edit + archive). Chrome is live: running-timer chip, sidebar pinned/recent/week-bars, statusbar db-size + backup readout. 129 tests passing. CSS is the verbatim port from `design/ernte/project/styles.css` — **Phase 2a added no new CSS**; Phase 2b should keep hand-rolling and reuse existing classes.

## Phase 2b scope (per spec §5–7 + the Phase 2a plan header)

- **Invoices** — `Invoices/Index` (stats + filters + table), `Invoices/Create` (period picker + billable-unbilled entry checklist + line grouping + line editor), `Invoices/Show` (document view + activity sidebar + actions: send/markPaid/void). Routes already specced in §5.
- **Invoice PDF** — `resources/views/invoices/pdf.blade.php` + `spatie/browsershot` (headless Chromium) + `sprain/swiss-qr-bill` SVG embedded as the payment slip. German labels (Rechnung, Betrag, MwSt) per spec §6 open question.
- **Send + reminders** — `InvoiceMail` over SMTP on the `emails` queue; `ernte:invoices:remind` daily job at 09:00; daily overdue-stamp job writing `invoice_events.overdue_stamped`.
- **Settings/Profile** — `Settings/Profile` page editing `business_profile` (the tweaks panel already persists; the profile form is new).
- **Reports** — the design's "coming soon" placeholder card (`Reports/Placeholder`).
- **⌘K palette + `/api/search`** — `SearchController@__invoke` returning mixed project/client/invoice results; keyboard map (space, n, ⌘P, g d/t/c/i, /, ⌘K) handled in `AppLayout`.
- **Backup command** — `ernte:backup` (mysqldump + invoices tarball) writing a `backups` row so the statusbar shows a real time instead of "never". Daily at 03:00.

(Production docker-compose + `bin/install` + README is Phase 3, not 2b.)

## Items deferred from the Phase-1 carryover (now due in 2b)

- **`Client::invoices()` relationship.** Only `projects()` landed in 2a. `/invoices/new?client=…` and the client detail page need `invoices()`. One `hasMany` one-liner.
- **`Invoice::void()` must clear `time_entries.invoice_id`.** FK is `ON DELETE SET NULL` but voiding sets `status='void'` without deleting — so voided invoices keep entries attached and those entries vanish from "unbilled" scopes. Implement a transactional domain method that flips status AND nulls the linked entries' `invoice_id`.
- **`InvoiceBuilder::computeTotals()` already handles `vat_exempt` lines** (splits taxable from exempt before VAT). The builder currently hardcodes `vat_exempt: false` because time entries carry no exempt flag. The line-editor UI in `Invoices/Create` is where the exempt toggle goes — pass it through to the builder.
- **`InvoiceFactory` produces test-only numbers** (`2026-T#####`). When asserting on production number format (`YYYY-NNN`), call `InvoiceNumberer::nextFor()` directly instead of the factory.
- **`sprain/swiss-qr-bill` is not yet required.** `composer require` it in 2b. Phase 1's `QrReferenceGenerator` (mod-10 recursive) stays for reference generation; the library is for SVG rendering — they don't collide.
- **Browsershot is ready to use.** DDEV web image has Chromium at `/usr/bin/chromium`; `BROWSERSHOT_CHROME_PATH` is set. `composer require spatie/browsershot` and go — no further system setup.

## Placeholders wired in Phase 2a to swap when Invoices ship

- **`outstanding` is hardcoded to 0** in two places: `ClientProjections::index()` (`outstanding => 0`) and `DashboardProjections::stats()` (`outstanding_amount => 0.0`). The Vue layer (`Clients/Index.vue`, `Projects/Index.vue`) already renders these — just compute them from sent/overdue invoices.
- **`Clients/Index.vue` row sparkline is a hardcoded literal** `[2,3,1,4,5,…]` for every row. `ClientProjections` supplies no per-client activity series yet. Either feed real 14-day data or drop to an empty/dim placeholder.
- **`ClientController` resource is registered `->except(['show'])`.** Phase 2b likely wants a `Clients/Show` (the design's client detail). When it lands, retarget `pushRecent` in `Clients/Edit.vue` (currently labels the recent entry as `/clients/{id}/edit`).
- **`Projects/Show` only renders the Overview tab.** Entries/Tasks/Team/Settings are disabled chips with correct counts (`counts.entries`, `counts.tasks`). Wiring the tab bodies is outstanding.
- **Topbar ⌘K button is `disabled`** (title "Phase 2b"); `/api/search` is not implemented.

## Smaller findings worth a sweep (none block 2b)

- **`TimerController::switch` delegates to `start()`** rather than the existing `TimerService::switch()` — the service method is now dead code reachable only via direct calls. Either route through it or drop it.
- **`EntryController` ownership style is split:** `update` enforces user-ownership via `UpdateEntryRequest::authorize()`, `destroy` via an inline `abort_if`. Both tested, both work; unify if you touch it. (Single-user app, so not load-bearing — but the pattern matters if multi-user ever lands.)
- **Filter/search pattern diverges:** `ProjectController::index` filters/searches server-side via query params (round-trip per keystroke, debounced); `ClientController::index` returns all rows and filters in-memory in Vue. Fine at current scale; align before either list grows large.
- **`SidebarProps::pinnedProjects` uses LEFT JOIN + GROUP BY** with `projects.created_at` in the ORDER BY but not the GROUP BY. DDEV's MariaDB runs without `ONLY_FULL_GROUP_BY` so it's fine today; add `projects.created_at` to the GROUP BY if a stricter sql_mode is ever enabled.
- **`Backup::latest()` still shadows Eloquent's query-builder `latest()`** (returns a `?Backup`, not a builder). The statusbar is the only caller. Rename to `mostRecent()` if a second caller wants a builder — the backup command in 2b is a candidate.
- **`TimerHero.vue` reads `running.project.rate_rappen` directly** to compute the live ticking earnings — the one intentional place a `_rappen` field crosses into Vue. Everything else converts to CHF at the projection seam. Keep this invariant in 2b.
- **Currency symbol is `€` throughout the UI** (carried from the design, which is a Berlin/EUR mock). The spec is Swiss/CHF with German invoice labels. A localization sweep (€ → CHF, formatting) is worth doing when the invoice document lands, since the PDF must be CHF.
- **`useTweaks` singleton-drift footgun** (from Phase 1) still applies — if a second consumer of `useTweaks()` is added, refactor to a module-scope singleton first.

## Suggested handoff prompt for the new conversation

> Write the Phase 2b plan for ernte; read the spec § 6–7 and the phase-2b carryover doc first.

Phase 2b is large (invoices + PDF + QR-bill + email + scheduler + settings + reports + ⌘K + shortcuts + backup). Consider splitting it the way Phase 2 was split — e.g. 2b-i (Invoices CRUD + Create flow + Show + PDF + QR-bill) and 2b-ii (send/reminders + settings + reports + ⌘K + shortcuts + backup command) — so each plan ships working, testable software on its own.
