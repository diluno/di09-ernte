# Phase 2 carryover notes

Items the final Phase 1 review flagged that Phase 2 should pick up. Not bugs in Phase 1 — forward-compat hooks and reminders that don't fit anywhere else.

## Domain layer

- **Add `Client::projects()` and `Client::invoices()` relationships.** Both are needed by `/invoices/new?client=…` (loads unbilled entries grouped by client) and the client detail page. Two `hasMany` one-liners.

- **Voiding an invoice must explicitly clear `time_entries.invoice_id`.** The FK is `ON DELETE SET NULL`, but voiding doesn't delete the row — it sets `status = 'void'`. Without explicit unlinking, voided invoices would keep their entries attached and those entries would silently disappear from "unbilled" scopes. Implement either: (a) a domain method `Invoice::void()` that updates the status AND nulls the linked entries' `invoice_id` in a transaction, OR (b) a model event on `Invoice` saving that watches the status transition. Option (a) is more explicit.

- **`InvoiceBuilder::computeTotals()` is ready for `vat_exempt` lines.** The math is correct (split taxable from exempt before computing VAT), but the builder hardcodes `vat_exempt: false` on every line because time entries don't carry an exempt flag. When Phase 2 adds the line editor UI, that's where the exempt toggle goes — pass the flag through to the builder if entries gain it, or let the user edit lines after the draft is created.

- **`Backup::latest()` shadows the Eloquent query builder's `latest()` method.** `Backup::latest()->something()` won't return a query builder — it returns a `Backup` instance or `null`. Fine for the one current caller (statusbar reading the most recent backup row); rename to `mostRecent()` if a second caller wants a builder.

- **`InvoiceFactory` produces test-only numbers** (`2026-T#####` format) instead of the production `YYYY-NNN` format. The factory has a comment about it. When Phase 2 writes a test that asserts on number format, use `InvoiceNumberer::nextFor()` directly instead of the factory.

## Tooling / environment

- **Tests run on MariaDB, not SQLite.** `phpunit.xml` is configured with `DB_CONNECTION=mariadb` and `DB_DATABASE=db_test` (created in DDEV). Don't reintroduce SQLite — the codebase relies on generated columns, raw FK ALTERs, and the `LAST_INSERT_ID(expr)` trick that don't translate. If a future migration needs MariaDB-specific syntax, it can use it directly without a driver branch.

- **DDEV web image already has Chromium installed** (`/usr/bin/chromium`) plus `libgbm1` + `libasound2` for stability. `BROWSERSHOT_CHROME_PATH=/usr/bin/chromium` is set as an env var. Phase 2's PDF rendering can `composer require spatie/browsershot` and use it immediately, no further system setup.

- **`sprain/swiss-qr-bill` library was deferred to Phase 2.** Phase 1 hand-rolled the mod-10 recursive check digit (`QrReferenceGenerator`) because the lib's primary value is rendering the QR-bill SVG/PDF, which we don't need until Phase 2. When you `composer require sprain/swiss-qr-bill`, you can keep `QrReferenceGenerator` for reference generation and use the library for SVG rendering — they don't collide.

## Frontend / Blade

- **`resources/views/app.blade.php` has no body class** (Tailwind was removed in Phase 0). If Phase 2 brings any Tailwind utility classes back (unlikely — we're hand-rolling CSS), the Breeze auth pages still use Tailwind utilities and need restyling alongside.

- **Inertia progress-bar color is hardcoded** to the forest accent (`#2d4a3a`) in `resources/js/app.js`. If you ever wire it to the runtime `--accent` token, you'd need a Vue-level Inertia config rather than the static literal.

- **`useTweaks` composable has a known footgun**: if a second consumer ever calls `useTweaks()`, both instances will hold independent `settings` refs and drift. Currently fine (only `TweaksPanel.vue` uses it). If Phase 2 needs a second consumer (e.g. an inline density toggle on the dashboard), refactor to a module-scope singleton first.

## What's next (per the spec § 5–7)

Phase 2 scope:
- HTTP controllers wiring the four domain services to the seven Inertia routes (`/projects`, `/projects/{project:code}`, `/timer`, `/clients`, `/invoices`, `/invoices/new`, `/invoices/{invoice:number}`, `/reports`, plus the timer + entry + invoice action endpoints).
- Vue components for each page (Dashboard table, project detail with burn-down/heatmap/tasks, Timer/Today hero, Clients table, Invoices list, Invoice create flow, Invoice document view).
- Topbar running-timer chip (currently a placeholder) — reads `running_entry` from shared props, ticks via `setInterval`.
- Custom SVG chart components (Sparkline, BurnDown, Heatmap, WeekBars, BudgetBar) — ~30–80 LOC each, no chart library.
- Invoice PDF template (Blade) + Browsershot + Swiss QR-bill embedded.
- Send invoice via SMTP, reminder scheduler.
- ⌘K command palette, keyboard shortcuts.
- Statusbar real backup-time value from the `backups` table.

Phase 2 will be larger than Phase 1 — consider splitting into 2a (Projects + Timer + Clients flows) and 2b (Invoices + PDF + email) when writing the plan.
