# Ledger Redesign — Slice 1: Shell + Invoices List + Invoice Detail

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Source of truth:** `design_handoff_ledger_invoices/README.md` (values are final, high-fidelity) and the two `.dc.html` mocks. This plan does not repeat every pixel value; it says *where* each value lands in the codebase and what data has to change. When the README and this plan disagree on a value, the README wins.

**Goal:** Move ernte onto the "Ledger" look (cream stock, hairline ink rules, one red, tabular mono numerals): retheme the shared tokens and app shell for every page, then rebuild the Invoices index and Invoice detail pages to the mocks.

**Architecture:** Three independently shippable sub-slices on one branch `ledger-redesign`:

| Sub-slice | Ships | Touches |
|---|---|---|
| **1a Tokens + shell** | new palette/type scale, 52px topbar, 240px sidebar, statusbar removed, restyled shared primitives (`.btn`, `.page-head`, `.stats`, `.table`, `.filter-row`) | every page (visually), no page markup |
| **1b Invoices list** | `/invoices` to the mock: grid chart, tab filters, ledger table | `Invoices/Index.vue`, `InvoiceBarChart.vue`, `InvoiceProjections::stats()` |
| **1c Invoice detail** | `/invoices/{number}` to the mock: stats row, sheet-framed preview, more-actions menu, recipients/activity/linked-time aside, "send reminder now" | `Invoices/Show.vue`, `InvoiceController::show()`, one new route |

Pages not in the handoff (Projects, Timer, Clients, Estimates, Recurring, Settings, editors) get the new shell and tokens from 1a and otherwise keep their current markup. They are follow-up slices once the user has mocks for them.

**Tech Stack:** Laravel 12, MariaDB (DDEV), Inertia + Vue 3, plain CSS with tokens (no Tailwind), pixelarticons via `Icon.vue`, Pest.

## Global Constraints

- Run everything through DDEV: `ddev artisan test`, `ddev npm run build`. Feature tests 500 unless the asserted page's `.vue` is in the built Vite manifest — build before running page tests.
- Pages use literal path strings (`/invoices/${number}`), not Ziggy `route()`.
- Radius 0 everywhere. Red (`--red`) is the only chroma on the two invoice screens.
- Mono stays `--font-mono: 'Iosevka Aile', ui-monospace, …` (the face is not bundled; it falls back to the system mono exactly as today — do not add a font file).
- Desktop-first (≥1280px). Existing `@media` rules stay; do not add new responsive work.

## Decisions taken while reading the handoff (flag to the user if any is wrong)

1. **Accent tokens are re-mapped, not deleted.** `--forest`, `--rust`, `--gold`, `--accent` stay defined so untouched pages (budget bars, project glyphs, heatmap, badges) keep working, but they collapse onto the Ledger palette: `--forest → --ink`, `--rust → --red`, `--gold → --ink-3`, `--accent → --ink`. Later slices delete them page by page.
2. **Dark theme block is deleted.** Commit `1d8bd40` removed the theme switcher; `:root[data-theme="dark"]` is dead code.
3. **Statusbar and the `system` shared prop go together.** The prop only feeds the statusbar and costs a DB-size query and uptime read per request. Remove both and the three `system` tests in `tests/Feature/InertiaPropsTest.php`.
4. **`.page-title` becomes 64px globally.** That is what "complete design" means; the other pages will look large-titled until their own slice, which is intended.
5. **Draft title/notes inline editor leaves `Show.vue`.** `Invoices/Edit.vue` already edits both fields; the mock has no editor in the aside. "Edit" lives in the more-actions menu for drafts.
6. **"Paid PDFs" stays a quarter `<select>`** styled as the secondary button (the mock shows a button, the behavior needs a quarter).
7. **Chart year navigation** keeps the existing prev/next arrows, rendered as two small secondary buttons above the legend in the 120px column.
8. **Linked time "View →"** links to the project page (`/projects/{code}`), which lists the entries. The invoice's date range comes from `MIN/MAX(started_at)` of linked entries.
9. **"Send reminder now"** is a new `POST /invoices/{invoice}/remind` that dispatches the existing `SendInvoiceReminderMail` job for a sent invoice, ignoring the pause flag and the recently-reminded check (an explicit click is intent).
10. **"Next reminder scheduled"** row shows only when status is sent, overdue, not paused. Date = `max(due_on, last reminded date) + reminder_days_after_due`.
11. **Stat footnotes need three new numbers** from `InvoiceProjections::stats()`: `overdue_max_days`, `paid_ytd_count`, `avg_terms_days` (mean of `due_on − issued_on` over issued invoices; shown as "terms N").
12. **Detail "Issued" footnote channel** is derived from the newest `sent` event: `payload.manual` → "marked as sent", else "sent by email"; drafts show "not issued".

---

## Sub-slice 1a — Tokens + shell

### Task 1a.1: Replace the token set

**Files:** `resources/css/tokens.css`

- [ ] Rewrite `:root` to the README token list: `--paper #f7f3ea`, `--paper-2 #efe9dc`, `--paper-3 #f1ece1`, `--sheet #fbf9f4`, `--ink #141210`, `--ink-2 #5a554b`, `--ink-3 #7a7367`, `--ink-4` (keep, alias to `--ink-3`), `--border #e3dccd`, `--border-strong #c9c2b3`, `--rule var(--ink)`, `--red #c8341f`, `--red-bg #f6e6e1`, `--ink-hover #2e2b26`. Keep `--bg/--bg-2/--bg-3` as aliases of the paper tokens (widely used in editors).
- [ ] Re-map accents per decision 1. Delete the `[data-theme="dark"]` block and the `data-density` blocks; set `--row-h: 46px; --pad-y: 10px; --pad-x: 12px` directly.
- [ ] Type scale: `--fs-xs 11px`, `--fs-sm 13px`, `--fs-md 14px`, `--fs-lg 15px`, `--fs-xl 64px`, plus `--fs-stat 40px`, `--fs-week 34px`. Add `--track-label .08em`, `--track-section .14em`, `--track-th .12em`.
- [ ] `ddev npm run build` succeeds.

### Task 1a.2: Shell grid, topbar, sidebar, statusbar removal

**Files:** `resources/css/base.css` (App shell, Sidebar, Statusbar sections), `resources/js/Layouts/AppLayout.vue`, `resources/js/Components/Topbar.vue`, `resources/js/Components/Sidebar.vue`, `resources/js/Components/RunningTimerChip.vue`, `resources/js/Components/WeekBars.vue`, delete `resources/js/Components/Statusbar.vue`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/InertiaPropsTest.php`

- [ ] `.app`: `grid-template-columns: 240px 1fr; grid-template-rows: 52px 1fr`. Remove `<Statusbar />` from `AppLayout.vue`, delete the component and the `.statusbar` CSS.
- [ ] Remove the `system` shared prop and its helpers (`dbVersion`, `dbSizeBytes`, `uptimeSeconds`) from the middleware; delete the three `system` tests; keep `app` and `business` props. `ddev artisan test --filter=InertiaProps` green.
- [ ] Topbar to README: 52px, `padding 0 32px`, `border-bottom: 1px solid var(--ink)`, `gap 24px`. Wordmark 15px/700 `-0.01em` with 16px leaf in ink (not accent), followed by `/ business-name` in `--ink-3` (replace the `.mono-tag` business chip). Centre `Jump to…` mono 12px with `⌘K` kbd (`border: 1px solid var(--border-strong); padding: 1px 6px`); drop the `›` glyph and the 280px box. User name 13px, no avatar circle.
- [ ] Timer chip: mono 12px, `border: 1px solid var(--ink); padding: 5px 10px 5px 12px; gap: 10px`, 8px red dot (no pulse animation), project name at 12px, bold elapsed, 9px stop square `background: currentColor`. Hover inverts to ink/paper. Idle state: same frame, `--ink-3` text, no dot.
- [ ] Sidebar: `border-right: 1px solid var(--ink); padding-top: 28px`, no gap. `.nav-item`: `gap 12px; padding 9px 32px; font-size 15px; border-bottom: 1px solid var(--border)`; inactive `--ink-3`/400, active `--ink`/600 `background var(--paper-2)`, no left accent bar; glyph 14px inherits colour; count mono 12px `--ink-3`. Hover `--paper-2`/`--ink`.
- [ ] `.side-section` ("Pinned", "Recent"): 11px, `.14em`, uppercase, `--ink-3`, `padding 32px 32px 8px`. `.pin-row`: `padding 6px 32px; font-size 14px`, no dot glyph (remove the coloured `pin-dot`), hover `--paper-2`. Recent rows same style at 13px `--ink-3`.
- [ ] Bottom block: `border-top: 1px solid var(--ink); padding 24px 32px`. "This week" label as section label; value mono 34px `-0.03em` `line-height 1` with `/ 40 h` at 14px `--ink-3`. `WeekBars.vue`: `height 24px; gap 2px; border-bottom: 1px solid var(--ink)`, past days `--ink`, today `--red`, future/empty no fill, no weekday letters, no weekend opacity.
- [ ] Visual check in the browser at 1440px against `Ernte Invoices.dc.html` (shell only).

### Task 1a.3: Shared primitives

**Files:** `resources/css/base.css` (Content, Buttons, Filter row, Section title, Badge, Tables, Stats sections), `resources/js/Components/Pagination.vue`

- [ ] `.page-head`: `padding 36px 40px 24px; border-bottom: 1px solid var(--ink)`, not sticky. `.crumb`: mono 12px uppercase `.08em` `--ink-3`, links get `border-bottom: 1px solid var(--border-strong)` and hover `--ink`. `.page-title`: 64px/600/`-0.04em`/`line-height .95`, `margin-top 10px`, `gap 22px`; `.meta` mono 12px uppercase `.12em` `--ink-3`. Remove `.page-subtitle` usage on invoices later; keep the class.
- [ ] `.btn`: mono 12px, `padding 10px 14px; border: 1px solid var(--ink); background transparent; radius 0`, hover `--paper-2`. `.btn.primary`: ink bg, paper text, `padding 11px 18px`, hover `--ink-hover`. `.btn.ghost`: border transparent. New `.btn.icon`: 40px square, `display grid; place-items center; padding 0`, `[aria-expanded="true"]` → `--paper-2`. Buttons with an icon use `gap 8px`, icon 13px. Style `select.btn` identically (no native arrow).
- [ ] `.stats`: `border-bottom: 1px solid var(--ink)`. `.stat`: `padding 20px 40px 22px; border-right: 1px solid var(--border)`, gap 0. `.label` 12px `.08em`; `.val` mono 40px `-0.03em` `line-height 1; margin-top 10px`, weight 400; `.unit` 14px `--ink-3` `margin-left 6px`; `.delta` (rename in CSS to also match `.foot`) 12px `--ink-3` `margin-top 8px`. `.stat.is-red` colours label, value, unit and footnote `--red`. Delete `.delta.up/.down`.
- [ ] `.filter-row`: `padding 16px 40px; border-bottom: 1px solid var(--ink); gap 24px; font-size 13px`. Replace `.chip` styling with tab styling: no padding/border box; `padding-bottom 2px; border-bottom: 1px solid transparent`; `[aria-pressed="true"]` → 600, `border-bottom-color var(--ink)`, `--ink`; inactive `--ink-3`, hover `--ink` + `border-bottom-color var(--border-strong)`; `.chip.is-red` always `--red`. `.search`: `margin-left auto; min-width 220px`, mono 12px `--ink-3`, `border: 0; border-bottom: 1px solid var(--border-strong); padding 2px 0; background transparent`, trailing `.kbd` `/` (`padding 0 5px; font-size 11px`). Remove the search icon.
- [ ] `.table`: `th` 11px `.12em` `--ink-3` weight 400 `padding 12px`, `border-bottom: 1px solid var(--border)`, not sticky; `th.is-sorted` → `--ink`. `td` height 46px, `border-bottom: 1px solid var(--border)`, colour `--ink`; row hover `--paper-2`. `.pad-l` 40px, `.pad-r` 40px. Delete the `is-open` gold rows; `is-overdue` rows: `background var(--red-bg)`, first cell `box-shadow: inset 3px 0 0 var(--red)`. `td.num` mono 13px `--ink-3`; `td.money` mono 14px `--ink` 600 (`.is-paid td.money` 400).
- [ ] `.section-title`: 11px `.14em` uppercase `--ink-3`, `border-bottom: 1px solid var(--ink); padding-bottom 8px`, no `::after` rule. New `.side-row`: `padding 10px 0; border-bottom: 1px solid var(--border); font-size 14px` with `.sub` mono 12px `--ink-3` `display block`.
- [ ] `.badge`: keep for other pages but retheme: no border box, 12px uppercase `.08em`, mark via `::before` content (`○` draft, `◐` sent, `●` overdue/paid), colours per README. Remove the forest/gold `color-mix` rules.
- [ ] `Pagination.vue`: buttons become `.btn` (secondary) with active = `.btn.primary`; range text mono 12px `--ink-3`; wrapper `padding 16px 40px`.
- [ ] Remove now-unused rules: `.cmdk` box, `.avatar`, `.user-chip` bg, `.pin-dot`, `.statusbar`, `.delta.up/.down`, `.table tr.is-open`, gold/forest `color-mix` badges. `grep -rn` each removed class in `resources/js` to confirm zero usages before deleting.
- [ ] Full test run green; `ddev npm run build`; click through Projects, Timer, Clients, Estimates to confirm nothing is unreadable (they will look unfinished, that is expected).

---

## Sub-slice 1b — Invoices list

### Task 1b.1: Stats footnotes

**Files:** `app/Support/InvoiceProjections.php`, `tests/Feature/InvoiceStatsTest.php` (extend the existing stats test file, or create)

- [ ] Test: `stats()` returns `overdue_count`, `overdue_max_days` (days between today and the oldest overdue `due_on`, 0 when none), `paid_ytd_count`, `avg_terms_days` (rounded int, null when no issued invoices).
- [ ] Implement with two extra aggregate queries; keep existing keys unchanged.

### Task 1b.2: Grid month chart

**Files:** `resources/js/Components/InvoiceBarChart.vue`

- [ ] Replace the SVG with a CSS grid `repeat(12, 1fr) 120px` per README "Monthly chart". Keep `niceScale`; bar height px = `value / niceMax * 56`. Month label 11px `.1em` `--ink-3`, current month (only when `year` is the current year) label `--ink` and cell `background var(--paper-3)`. Total below in mono 12px, `—` when 0, formatted with `toLocaleString('de-CH')` (no "CHF" prefix). Cell hover `--paper-2`.
- [ ] Legend column: `border-left: 1px solid var(--ink); padding-left 14px`, bottom-aligned: prev/next year `.btn` arrows (`padding 4px 8px`) on one line, then Paid/Open 10px swatches (`--ink` / `--red`), then `Σ {year total}` mono 12px.
- [ ] Section wrapper: `padding 20px 40px 18px; border-bottom: 1px solid var(--border)`. Keep the `update:year` emit contract; `Index.vue` is unchanged for this task.

### Task 1b.3: Index page markup

**Files:** `resources/js/Pages/Invoices/Index.vue`

- [ ] Page head: crumb `Invoices · FY {year} · {counts.all} documents`; title "Invoices" without `.meta`; actions `<select class="btn">↓ Paid PDFs</select>` + `.btn.primary` "+ New invoice".
- [ ] Stats: Outstanding (`{counts.sent} sent`), Overdue (`.stat.is-red`, `{overdue_count} invoice(s) · {overdue_max_days} days`), Paid YTD (`{paid_ytd_count} paid`), Avg days to pay (`terms {avg_terms_days}` or "—"). Values via a local `fmtChf0` (thousands apostrophe, no decimals, unit span "CHF").
- [ ] Filter row: tabs use the new `.chip` tab style, Overdue tab has `.is-red`; search input gets `data-filter-input` and the trailing `/` kbd (the `/` shortcut already targets `.search input`).
- [ ] Table columns: No. (mono 13px, `.pad-l`, width 130) · Subject (15px, ellipsis, `—` fallback, italic when draft; width 260) · Client (`--ink-3`, ellipsis, 220) · Issued (num, `th.is-sorted` "Issued ↓", 120) · Due (num, red when overdue, 120) · Hours (num `xx.x`, 80) · CHF (`.money`, 150) · Status (`.pad-r`, 130, `.badge` with mark). Row classes: `is-draft` (ink `--ink-3`), `is-paid` (ink `--ink-2`), `is-overdue`. Remove `is-open` and `.doc-id` markup.
- [ ] Empty state row keeps `colspan` = 8.
- [ ] `ddev npm run build`; existing `InvoiceIndexTest` (or equivalent) green; browser check against the mock.

---

## Sub-slice 1c — Invoice detail

### Task 1c.1: Detail data

**Files:** `app/Http/Controllers/InvoiceController.php` (`show`), `app/Support/InvoiceProjections.php` (`detail`), `tests/Feature/InvoiceShowTest.php`

- [ ] `detail()` adds `recipients` (`$invoice->recipients ?: $invoice->client->defaultRecipients()`), `terms_days` (`due_on − issued_on`, null when unissued), `days_late` (positive int when overdue else 0), `avg_rate` (hours-weighted mean of line rates in CHF, null when no hours), `issued_channel` (`email` | `manual` | null from the newest `sent` event).
- [ ] `show()` extends `linked_entries` with `project: {name, code}`, `from`, `to` (`MIN/MAX(started_at)` as dates), and adds `next_reminder_on` (decision 10; null otherwise).
- [ ] Tests assert each new key on a sent+overdue invoice with two linked entries, and nulls on a draft.

### Task 1c.2: Send reminder now

**Files:** `routes/web.php`, `app/Http/Controllers/InvoiceController.php`, `app/Services/Invoicing/InvoiceLifecycle.php` (if a `remind()` helper fits better than the controller), `tests/Feature/InvoiceReminderNowTest.php`

- [ ] `POST /invoices/{invoice}/remind` → 422 with flash error unless status is `sent` or recipients are empty; otherwise `SendInvoiceReminderMail::dispatch($invoice->id)` and flash "Reminder queued for {number}." Test with `Queue::fake()`.

### Task 1c.3: Icons

**Files:** `resources/js/Components/Icon.vue`

- [ ] Add `download` and `more-horizontal` from `~icons/pixelarticons/*` to the map (`check` exists).

### Task 1c.4: Show page markup + more-actions menu

**Files:** `resources/js/Pages/Invoices/Show.vue`, `resources/css/base.css` (Invoice detail section)

- [ ] Page head: crumb `Invoices / {number} · {client}` (+ ` · ↻ recurring` link when `invoice.recurring`); title = `invoice.title || 'Invoice #'+number` with inline status in `.meta`: `● overdue · {days_late} days` red, else `{mark} {status}` `--ink-3`, plus ` · reminders paused` when paused.
- [ ] Actions by status: draft → secondary "PDF" (download icon), primary "Send by email"; sent → "PDF", primary "Mark paid" (check icon); paid/void → "PDF" only. Then the 40px `.btn.icon` more button (`aria-expanded`, `aria-haspopup="menu"`).
- [ ] Menu (`menuOpen` ref): items in order — draft: Edit, Mark as sent, Delete…; sent: Pause/Resume reminders, Send reminder now, Void invoice, Delete…; paid/void: Delete…. Styles per README (`.menu`, `.menu-item`, `.menu-item.is-danger`). Close on outside click (`document` mousedown listener registered on open, removed on close/unmount) and `Esc`. Keep every existing `window.confirm` string verbatim. Remove the inline title/notes editor (decision 5).
- [ ] Stats row: Total (`incl. {vat_rate}% VAT`), Issued (`DD Mon` mono 40px; footnote `{year} · sent by email|marked as sent`, draft: `—` / "not issued"), Due (`.is-red` when overdue; footnote `terms {terms_days} · {days_late} days late` or `terms {terms_days}`), Hours (footnote `{count} linked entries · {avg_rate} CHF/h`).
- [ ] Body: `.invoice-page` grid `1fr 340px`, `flex:1; min-height:0`. Left `.invoice-doc-wrap`: `padding 32px 40px; background var(--paper-2); overflow-y auto; display flex; justify-content center; align-items flex-start`. The iframe gets class `.invoice-sheet`: `width 640px; min-height 100%; border: 1px solid var(--ink); background var(--sheet); box-shadow: 0 1px 0 var(--border-strong), 0 14px 34px rgba(20,18,16,.08)`. Keep the iframe as the scroll region.
- [ ] Aside `.invoice-side`: `border-left: 1px solid var(--ink); padding 28px 32px; overflow-y auto; display flex; flex-direction column; gap 32px`. Sections with `.section-title` + `.side-row`: **Recipients** (name + `.sub` email; "No recipients" muted row when empty), **Activity** (grid `96px 1fr; gap 12px`; time mono 12px; `overdue_stamped` row and its time in red; detail line `.sub`; trailing italic "Next reminder scheduled" row when `next_reminder_on`), **Linked time** (project name + `{hours} h` mono; then `{count} entries · {from} – {to}` 12px `--ink-3` with "View →" link to `/projects/{code}`, `border-bottom: 1px solid var(--ink)`; hidden when count is 0).
- [ ] `ddev npm run build`; `InvoiceShowTest` green; browser check against `Ernte Invoice Detail.dc.html` in all four statuses (draft, sent, overdue, paid).

### Task 1c.5: Cleanup + carryover

- [ ] Delete `.invoice-doc`, `.invoice-h`, `.invoice-line`, `.invoice-totals` from `base.css` if `grep` shows no remaining usage (they served an older inline preview).
- [ ] Write `docs/superpowers/ledger-redesign-carryover.md`: which pages still carry pre-Ledger markup (Projects/Timer/Clients/Estimates/Recurring/Settings/editors/Login), the re-mapped accent tokens to retire, and the open question of an Iosevka webfont.
- [ ] Move `design_handoff_ledger_invoices/` to `design/ledger/` (drop `support.js`; fonts are already in `resources/fonts/`) so the mocks stay in the repo next to the earlier `design/ernte/` reference, and commit.
