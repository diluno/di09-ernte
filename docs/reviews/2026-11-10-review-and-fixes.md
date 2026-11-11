# ernte review and fixes — November 2026

Review date: 2026-11-10. Fixes applied 2026-11-10/11 on branch `review-fixes` (uncommitted at time of writing). Continued 2026-11-11: loose ends, browser checks, phase 2 — see Part 4.
Previous review: `PROJECT_REVIEW.md` (2026-09-08).

## Status at a glance

| Area | State |
| --- | --- |
| Phase 1: correctness fixes (billing, receipts/banking/ops, frontend behaviour) | Done, uncommitted |
| Phase 1 loose ends (2.4) | Done, except the period-change item (left open on purpose) |
| Browser check of the fixes (2.5) | Done 2026-11-11, except the password change (not clicked) |
| Phase 2: ledger redesign port + narrow-window layout | Done 2026-11-11, uncommitted (see Part 4) |
| Symfony/Laravel security updates, Sail removal, parallel-test DB permission | Done (`composer audit` clean) |
| Pint | **Not started** (run as its own commit once this is committed) |
| Full test suite | 921 passed, 0 failed (serial and `--parallel`) |
| Production build (`npm run build`) | Passes |

How things were verified: the review read code paths end to end and walked the running app in Chrome. The fixes are covered by tests where they are backend changes. Frontend changes were verified by reading and building only; nothing was clicked through after the fixes.

---

## Part 1 — Review findings

### 1.1 Fix first (money and irreversible actions)

| # | Finding | Where | Fixed |
| --- | --- | --- | --- |
| 1 | Bank matching auto-applied a credit on amount alone, ignoring payer name and a QR reference pointing elsewhere; could mark the wrong invoice paid | `app/Services/Banking/InvoicePaymentMatcher.php` | Yes |
| 2 | QR bill fell back to a hard-coded example IBAN when the profile had none | `app/Services/Invoicing/QrBillRenderer.php` | Yes |
| 3 | Deleting a sent or paid invoice released its time entries for re-billing and removed the frozen PDF | `InvoiceController::destroy` | Yes |
| 4 | Editing a time entry's date did nothing (edit mode always kept the original start) | `resources/js/Pages/Timer/Today.vue` | Yes |
| 5 | Space, `n`, `g` shortcuts fired from any focused button or link, and with modifiers held | `resources/js/composables/useKeyboardShortcuts.js` | Yes |
| 6 | Billed time entries stayed editable and deletable | `EntryController` | Yes |
| 7 | Send and remind had no confirmation or double-click guard; status check outside the transaction; mail sent inside it | `Invoices/Show.vue`, `Estimates/Show.vue`, `InvoiceLifecycle.php`, `EstimateLifecycle.php` | Yes |
| 8 | Receipts and Settings returned 500 when the Dropbox token could not be decrypted (different `APP_KEY`) | `app/Services/Dropbox/DropboxClient.php` | Yes |
| 9 | Backups omitted bank statements and local receipts | `app/Console/Commands/BackupCommand.php` | Yes |
| 10 | A failed photo conversion was filed to Dropbox as a fake `.pdf` and the local original deleted | `PrepareReceiptPdf`, `ReceiptFiler` | Yes |

### 1.2 Worth fixing soon

| Finding | Where | Fixed |
| --- | --- | --- |
| Running-timer totals off by 1–2 h (`UTC_TIMESTAMP()` vs Zurich wall-clock `started_at`, 12 sites) | `SidebarProps`, `Project`, `ProjectDetail`, `ClientDetail`, `ClientProjections`, `InvoiceController` | Yes |
| New invoice discarded all edits when the period changed; no unsaved-changes guard anywhere | `Invoices/Create.vue` and all long forms | Yes |
| VAT rates page crashed after adding a rate | `Settings/VatRates.vue` | Yes |
| iOS companion token was also a full MCP credential (no abilities, no expiry) | `Api/AuthController`, `config/sanctum.php`, `routes/api.php` | Abilities yes; expiry not added |
| `DELETE /profile` still routed; deleting the only user cascades to all time entries; `/profile` page broken | Breeze leftovers | Yes |
| Sent-invoice PDFs re-rendered from live data | `InvoiceController` | Yes |
| Mail greeted the client's default contact, not the actual recipient | `resources/views/emails/**` | Yes |
| Accepting an amount mismatch marked the invoice fully paid with no record of the difference | `BankController` | Difference recorded on the event trail; no full partial-payment model |
| Contact validation errors invisible on client forms | `Clients/Edit.vue`, `Clients/Create.vue` | Yes |
| Recurring schedule could get stuck re-reporting an existing occurrence | `RecurringInvoiceGenerator`, `GenerateRecurringInvoicesCommand` | Yes |
| Reminder cadence was N+1 days while the UI promised N | `RemindInvoicesCommand`, `SendInvoiceReminderMail` | Yes |
| `entry_ids` only checked for existence (could link another client's or a running entry) | `StoreInvoiceRequest`, `InvoiceBuilder` | Yes |
| Receipt jobs had no `failed()` hook; a timeout left receipts pending forever | `ExtractReceipt`, `FileReceipt` | Yes |
| Statement importer silently skipped known references and had no balance check | `StatementImporter` | Yes (see behaviour changes) |
| Vendor-logo fetch open to DNS rebinding; arbitrary bytes handed to Imagick | `VendorLogos` | Yes |
| Inbox scan race; no unique index on `receipts.dropbox_file_id` | `DropboxIntake` | Yes |

### 1.3 Design and UX (from the browser walkthrough)

Fixed in phase 1:

- Money, dates and durations now use one set of formatters. Document and line amounts always show two decimals; dates are `09 Nov 2026`; durations are decimal hours (`4.0h`) outside the live timer clock.
- Copy: "Entries in period0 selected", "entr(y/ies)", "Default rate (rappen)", "MwSt"/"Rundung" in the app UI, the timer's "pick a project below", and "This client has no contacts" before a client is chosen.
- List rows are real links (keyboard reachable, cmd-click opens a new tab).
- Command palette is a proper dialog with focus trap and focus restore.
- Icon-only buttons and line-editor inputs have accessible names.
- Empty states on Clients and Projects lists.
- Fonts no longer load twice (CDN links removed).

**Still open (phase 2):**

- **Two visual systems.** Ledger look: shell, `Invoices/Index`, `Invoices/Show`, `Bank/Index`, `Receipts/Index`, `Receipts/Show`. Old look: `Timer/Today`, all `Projects/*`, all `Clients/*`, all `Estimates/*`, `RecurringInvoices/*`, `Invoices/Create`, `Invoices/Edit`, `Settings/*`. Old-look components: `TimerHero`, `EntryRow`, `TaskRow`, `FlashMessages`, `EstimateScopeEditor`. The seam is most visible from the invoices list to New invoice, and between invoice detail and estimate detail.
- **No small-screen layout.** `.app` is a fixed `240px 1fr` grid and no media query touches the sidebar. At 820px the invoice stats overlap; at 500px the page title breaks to one letter per line.
- **Recurring invoices list** is the weakest page: raw `{period}` placeholder in titles, four tiny stacked text links per row with red Delete directly under "Generate now", no stats strip.
- **Project detail:** the "CHF 3'450" stat wraps to two lines because stats reuse the 64px title size; the activity heatmap overflows its panel.
- **New invoice:** form content sits 10px left of the page header; two "Create draft" buttons; step 1 is a lone native select of 61 clients.
- **Estimate detail** has no stats strip and exposes Decline/Delete as text links, unlike invoice detail with its "…" menu.
- "CHF 0" outstanding shown in red; grey primary buttons on the bank page look disabled.
- Label colour `--ink-3` (#7a7367) is about 4.2:1 on paper, 3.9:1 on hover rows, at 11–12px.
- Ledger row actions are hover-only (`opacity: 0`), invisible on touch.
- No dark mode, no `prefers-reduced-motion` rule (deliberately out of scope).

### 1.4 Code health

Fixed: invoice/recurring line editors and totals extracted into shared components; web and MCP invoice validation share rules; `InvoiceController::update` logic moved into `InvoiceBuilder`; Breeze leftovers removed (frontend and backend); dead CSS selectors removed; axios used one way.

Still open:

- Pint fails on about 110 files (formatting only).
- `artisan test --parallel` fails: the `db` user cannot create per-process databases.
- `composer audit` reports 10 Symfony advisories; `laravel/framework` is seven patch releases behind. `npm audit` reports 25 dev-dependency advisories (via puppeteer/vite).
- `laravel/sail` is unused under DDEV.
- The two PDF renderers are near-identical; only the estimate one calls `emulateMedia('print')`.
- The client/contact/project option mapping is copy-pasted across four controllers.
- `Bank/Index.vue` and `Timer/Today.vue` are still large single files.
- A JSDoc typedef for shared Inertia props would be cheap and useful.
- Invoice numbers are allocated at draft creation with `date('Y')` (gaps on deleted drafts; a December draft issued in January keeps the old year).
- Markdown images in PDF content could make the server fetch arbitrary URLs.

### 1.5 Done well — keep

- Money is integer rappen through one `LineTotals` path; the server recomputes everything and snapshots the VAT rate per document.
- Recurring-invoice idempotency: unique occurrence key, lock and re-check, retryable auto-send with events.
- `DropboxClient` confines every path to the receipts root, has no delete, uploads with `mode=add`.
- The camt parser uses string arithmetic, rejects DOCTYPE, and the quarter export balance-checks its own output.
- Model output from receipt extraction is schema-constrained and only drives proposals, filenames and folders.

### 1.6 Not reviewed

Harvest importer, `EstimateDrafter`, `EstimateScope`, backup verification internals, `AdoptReceiptsCommand`, `MonthListWriter` rendering, read-only API controllers, the MCP list/get/accept/decline tools, a pixel comparison against `design/ledger/*.dc.html`, the production server.

---

## Part 2 — What changed in phase 1

### 2.1 Behaviour changes to know about

- **Invoice delete** is allowed only for drafts and for void invoices that were never sent. Everything else must be voided. Estimate deletion is unchanged.
- **Billed entries are locked:** `PATCH`/`DELETE /entries/{id}` on an entry linked to a non-draft invoice is refused with an error.
- **No IBAN, no send:** issuing or sending an invoice without a configured IBAN fails with a clear message (web flash, MCP error result). `ernte:doctor` checks it.
- **Mail failure on send resets the document to draft** and shows the error. Mail goes out after the DB commit.
- **Amount-only bank matches need confirming** unless the payer name agrees and no reference or hint conflicts.
- **Statements that do not reconcile are rejected outright** (opening + entries ≠ closing), not imported with a warning. Not yet tried against a real ZKB file.
- **Reminders:** cadence is exactly N days by date; a second reminder within a minute is refused.
- **Sent documents serve the stored PDF**; only drafts render live.
- **Tokens:** `POST /api/auth/token` mints `companion` by default and `mcp` with `"scope":"mcp"`. `/api/mcp` requires `mcp`. Existing `*` tokens keep working for both. No expiry.
- **`/profile` redirects to `/settings`;** password change lives on the Settings page (`PUT /password`).
- **Recurring:** `vat_rate` column is no longer written; the rate used is the one in force at the period end.
- **Seeder:** fails loudly on an empty owner password only when the owner does not exist yet.
- **Queue:** `retry_after` is now 200.

### 2.2 Migrations

- `2026_11_11_000001_make_receipt_dropbox_file_ids_unique.php` — unique index on `receipts.dropbox_file_id`. Skips itself and logs a warning if duplicates exist.

### 2.3 New shared frontend API (phase 2 builds on these)

- `formatters/money.js`: `fmtMoney(value, { rappen, whole, currency })`, `fmtPercent(rate)`, `toRappen(chf)`
- `formatters/date.js`: `parseDate`, `toIsoDate`, `todayIso`, `fmtDate`, `fmtDateShort`, `fmtTime`, `fmtDateTime(v, { short })`, `fmtWeekday`
- `formatters/duration.js`: `parseDuration`, `fmtHours`, `fmtHoursFromSeconds`, `fmtClock`, `fmtClockSeconds`, `minutesToInput`
- `formatters/vat.js`: `vatRateForDate`, `lineAmountRappen(line)`, `roundTotalRappen`, `totalsForRate(lines, rate)`, `totalsForLines(lines, catalog, date)`
- `formatters/lineItems.js`: `makeLineItem(values)`, `lineItemsPayload(lines, { vat })`
- `composables/useDocumentTotals.js`: `useDocumentTotals(lines, { rate } | { catalog, date })`
- `composables/useUnsavedGuard.js`: `useUnsavedGuard(formOrRefOrGetter, { message })`, `useDirtySnapshot(getter)`
- `composables/usePendingAction.js`: `usePendingAction()` → `{ pending, pendingKey, run(...) }`, `recipientList(recipients, fallback)`
- `composables/useRowLink.js`: `rowClick(event, url)`
- `Components/LineItemsEditor.vue` (v-model lines; props `showVat`, `newLine`, `emptyText`; emits `remove`)
- `Components/TotalsCard.vue` (props `totals`, `rate`)
- `Components/RecurringInvoiceForm.vue` (props `schedule`, `clients`, `projects`, `vat_rates`)
- CSS: `.cell-input(.framed/.num)`, `.sr-only`, `.line-actions`, `.row-link`, `tr.is-clickable`, `.running-timer__link`

Removed: `formatChf`, `fmtDayMonth`, `formatDuration`, `fmtHMS`, `window.axios`, `Pages/Profile/*`, `AuthenticatedLayout`, `Register`, `VerifyEmail`, 13 Breeze components.

New backend helpers: `App\Support\EntryDuration::secondsSql()`, `App\Support\InvoiceInputRules`, `InvoiceBuilder::updateDraft()` / `unbilledEntries()`, `effectiveRecipients()` / `primaryRecipientName()` on Invoice and Estimate.

### 2.4 Loose ends from phase 1 (small)

- [x] Hide the invoice "Delete…" menu item unless the invoice is a draft, or void with no `sent_at` (`Invoices/Show.vue`). The server already refuses it.
- [x] Add labels for the new bank match notes `reference_conflict` and `payer_mismatch` (`NOTE_LABEL` in `Bank/Index.vue`); they render as raw text.
- [x] Backend: `EstimateProjections::detail` should include `recipients` so the estimate send confirmation can name them.
- [x] Backend: `InvoiceController@create` should add `unbilled_outside_period: { count, first_on, last_on }`; the frontend hint is wired but inert.
- [x] Recurring form totals preview should use the VAT rate at `schedule.next_period_end`, not at `next_run_on`.
- [x] A crash between commit and mail can leave a document marked sent with no `sent` event.
- [x] Quarterly export still 500s if it has to live-render an invoice with no IBAN.
- [x] `docs/deployment/forge.md` restore paragraph still lists only invoices and estimates.
- [ ] Changing the invoice period keeps edits to suggested lines only while the suggestion keeps the same description and rate.
- [x] Tests using `Storage::fake('local')` share one directory on disk (one transient failure seen under concurrent runs).

### 2.5 Browser checks before merging

- [x] Timer: edit an entry's date; start/stop with Space; Space on a focused button does nothing.
- [x] New invoice: change the period after editing title and lines; navigate away with unsaved changes.
- [x] Send an invoice and a reminder (against Mailpit): confirmation names recipients; button disables.
- [x] Command palette: Tab stays inside; Esc restores focus.
- [ ] Settings: page loads and Dropbox shows as disconnected (checked); **password change not clicked**.
- [x] VAT rates: add a rate, page stays up.
- [x] Bank: all 63 real ZKB statements pass the balance check (read-only run of the parser and check, no upload); matching re-run.
- [x] Recurring: create and edit through the shared form.

### 2.6 After deploying

1. Paste the updated `deploy/forge/deploy.sh` into Forge (`npm ci` only).
2. Run `bash deploy/forge/provision-chrome-and-backups.sh` (poppler-utils, libheif, php-imagick), restart PHP-FPM and the queue worker, then `php artisan ernte:doctor`.
3. Check `DB_QUEUE_RETRY_AFTER` is unset or at least 200.
4. Check the log for "was not made unique" after migrating.
5. Store `APP_KEY` off-server; it is needed to decrypt the Dropbox token after a restore.
6. Run `php artisan ernte:backup` once and confirm the statements and receipts archives are included.
7. Re-run bank matching: amount-only proposals now need confirming.

---

## Part 3 — Phase 2 plan (done 2026-11-11, see Part 4)

Suggested slices, each shippable on its own:

1. **Shell and narrow windows.** Collapse the sidebar below ~900px (off-canvas or icon rail), make `.stats` wrap to 2×2, stop the 64px title breaking, give `Projects/Show` stats the mono 40px stat style.
2. **Recurring invoices list.** Ledger page head, stats strip, formatted dates, resolved `{period}` in titles, row actions in a "…" menu with Delete separated from "Generate now".
3. **Invoice create/edit.** Ledger page head and gutters (40px), one primary action, searchable client picker.
4. **Estimates** (index, show, create, edit). Detail page to mirror invoice detail: stats strip, "…" menu.
5. **Projects and Clients** (index, show, create, edit).
6. **Timer and Settings.**
7. **Token cleanup.** Retire `--forest`, `--rust`, `--gold`, `--bg*` aliases; darken `--ink-3` for small labels; restyle `TimerHero`, `EntryRow`, `TaskRow`, `FlashMessages`, `EstimateScopeEditor`.

Design spec for the ledger look: `design/ledger/README.md`.

Housekeeping to do alongside: run Pint as its own commit, `composer update` for the Symfony advisories, grant the `db` user rights for parallel test databases, drop `laravel/sail`.

---

## Part 4 — Continuation, 2026-11-11

### 4.1 Found and fixed during the browser checks

- The PDF printed 0.25 h as "0.3" next to an amount computed from 0.25. Hours now print with two decimals when one would round (`resources/views/documents/pdf.blade.php`, test added). Stored PDFs of sent documents are unchanged.
- Linked time on an invoice without a project showed "—"; it now names the entries' project when they share one, else "Several projects".
- An estimate counted as expired on its last valid day in the model but not in the list filter; both now use "valid through its last day".
- Visually hidden labels (`.sr-only`) inside scroll boxes made the page scroll sideways (recurring list, VAT rates at 500px) and 54px taller than the window (New invoice). Scroll boxes are now their containing block.

### 4.2 Behaviour changes from today

- **VAT rates:** adding an open-ended rate that starts after the current open-ended rate now closes the current one on the day before, in one transaction. Everything else that overlaps is still rejected.
- **Send:** the `sent` and `pdf_generated` events are written in the same transaction that marks the document sent; a failed mail removes them again with the reset to draft.
- **Invoice menu:** "Delete…" only appears when the server would allow it; the "…" button is hidden when the menu is empty.
- **Quarterly export** redirects back with a message instead of a 500 when an invoice needs a live render and no IBAN is set.
- **Entry rows** show clock durations (`0:15`) on the timer, project and client pages; billed entries show a "billed" badge on the timer page and have no edit/delete.
- **Declined** estimates are no longer red. Project glyphs are never red. `--ink-3` is `#6e675b` (was `#7a7367`): about 5.0:1 on paper, 4.6:1 on hover rows.
- **Disabled buttons** are a grey outline with no fill, so a solid ink button is always an enabled one.
- **Tests:** `Storage::fake()` gets a per-process directory outside `--parallel`.

### 4.3 Phase 2 as built

- Shell: sidebar is an off-canvas drawer below 900px; stats go 2×2 below 1200px and one column below 600px; detail pages stack below 1100px; the page title scales from 64px to 34px; gutters are `var(--gutter)` (40px, 20px below 900px).
- All pages are on the ledger look: recurring list/forms, invoice create/edit, estimates, projects, clients, timer, settings, VAT rates, toasts, guest pages.
- New shared pieces: `Components/ActionMenu.vue`, `ClientPicker.vue`, `TimeEntryForm.vue`, the form blocks and other building blocks documented in `design/ledger/components.md`, per-area CSS in `resources/css/pages/*.css`.
- Legacy tokens (`--forest`, `--rust`, `--gold`, `--accent*`, `--bg*`, `--ink-4`) are removed.
- Checked in Chrome at 1440px on every converted page, and at 1024, 820 and 500px for the shell and for sideways overflow on 17 pages. Not checked: every state of every page (e.g. over-budget project, paused schedule, expired estimate, estimate edit, error toasts, login pages), and no comparison against a real phone.

### 4.4 Still open

- Pint (about 110 files) as its own commit.
- Changing the invoice period keeps edits to suggested lines only while the suggestion keeps the same description and rate.
- A second "Send reminder now" within a minute still flashes "Reminder queued" although the job drops it.
- "Run matching" on the bank page gives no feedback when nothing changed; the bank tables scroll together with their section head on narrow windows.
- A hard crash after commit but before the mail leaves a document whose `sent` event names a mail that never left (logged as critical; not detectable automatically).
- `Estimates/Create` and `Edit` still use a native client select; `ClientPicker` is only on invoices and recurring.
- Page-specific rules that are really shared (`.combo*`, `.recipient-row`, `.side-sections`, timer/entry-row overrides) sit in `resources/css/pages/*.css`; base.css still carries the older versions they override, plus dead `.doc-id`, `.timer-stage`, `.divider-row`.
- `ProjectDetail`/`ClientDetail` entry payloads do not expose `is_locked`, so the "billed" badge only shows on the timer page.
- Sections 1.4 (code health) and 1.6 (not reviewed) are otherwise unchanged; `npm audit` dev advisories untouched.

---

## Local environment notes

- DDEV was started for the review and is still running. Its `queue` and `scheduler` daemons are stopped (left that way: the local database is a production copy); queued jobs need `ddev artisan queue:work --once`.
- `.ddev/config.yaml` has a post-start hook granting the `db` user rights on `db_test%` so `artisan test --parallel` works.
- Spare test databases `db_test2`, `db_test3`, `db_test4` and `db_test_test_*` were created for parallel work and can be dropped.
- The local database appears to be a copy of production data encrypted with a different `APP_KEY` (that is what triggered the Dropbox decrypt error).
- `/*_camt053/` is now in `.gitignore`; the stale gitlink `.claude/worktrees/agent-a017074e8779d1739` is staged for removal from the index.
