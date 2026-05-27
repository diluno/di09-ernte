# Ernte — Self-hosted Time Tracking & Invoicing

**Status:** Design — approved 2026-05-27
**Owner:** Sam (single user, self-hosted)
**Source design:** `design/ernte/` (Claude Design handoff bundle — HTML/React JSX prototype)

## 1. Goal

A single-user, self-hosted replacement for Harvest covering:

- Project + task tracking with hour & fee budgets
- A real-time timer for logging today's work
- Client records
- Invoice generation from billable time, with Swiss QR-bill on the PDF
- A polished, opinionated UI matching the design's "developer-tool with warm paper" aesthetic

Out of scope for v1: reports, recurring invoices, multi-user, payment integrations, mobile layouts, real-time push, bank reconciliation, Harvest data import.

## 2. Stack

| Layer | Choice | Reason |
|---|---|---|
| Backend | Laravel 12, PHP 8.3 | Latest LTS-shape release |
| DB | MariaDB 11 (MySQL 8 compatible) | User preference |
| Queue + cache | `database` driver | No Redis required for a one-user app |
| Auth | Laravel Breeze (Inertia + Vue starter) | Single seeded user; self-registration off |
| Frontend | Inertia.js + Vue 3 (Composition API, `<script setup>`), Vite | Design is React JSX but user picked Vue; Inertia keeps a server-rendered routing model with SPA feel |
| Fonts | JetBrains Mono (self-hosted via Vite asset) | Matches design |
| PDF | `spatie/browsershot` (headless Chromium) | Pixel-faithful to the Blade invoice template — dompdf rejected for weak CSS support |
| QR-bill | `sprain/swiss-qr-bill` | Emits SVG, embedded into PDF template |
| Mail | Laravel Mail / SMTP (`.env`) | One Mailable for invoice send, one for reminder |
| Scheduling | Laravel scheduler in `php artisan schedule:work` | Daily reminder + overdue-stamp + backup jobs |
| Tests | PHPUnit + Pest for domain (timer math, invoice totals, QR generation, numbering) | Dusk skipped |
| Local dev | DDEV (Docker-based, PHP/Laravel-aware) | Standard, fast iteration; handles PHP+nginx+MariaDB+Chromium image automatically |
| Production deploy | docker-compose: `app` (PHP-FPM + nginx + supervisord) + `db` (MariaDB) | Self-hosted standard |

## 3. Data model

Currency stored as **integer rappen (CHF cents)** everywhere — no floats.
Date-only fields use `DATE` columns; instants use `DATETIME` in UTC.

### `users`
- `id`, `name`, `email` (unique), `password`, `email_verified_at`, timestamps
- `settings` JSON: `{ theme: "paper"|"dark", density: "comfortable"|"compact", accent: "#hex" }`

### `business_profile` (singleton)
- `id` (always 1), `name`, `address_line_1`, `address_line_2`, `postal_code`, `city`, `country` (default `CH`)
- `uid` (Swiss UID, format `CHE-XXX.XXX.XXX`), `vat_id` (e.g. `CHE-…-MWST`, separate field for forms that want them split)
- `iban`, `qr_iban` (nullable; either `iban` or `qr_iban` is used for QR-bill creditor)
- `email`, `logo_path` (nullable)
- `default_currency` (`CHF`), `default_vat_rate` (decimal, default `8.10`)
- `invoice_number_prefix` (optional override; default empty), `reminder_days_after_due` (int, default `7`)
- timestamps

### `clients`
- `id`, `name`, `short_code` (2-char, for glyph), `contact_name`, `email`
- `address_line_1`, `address_line_2`, `postal_code`, `city`, `country`
- `vat_id` (nullable), `default_rate_rappen` (nullable — falls back to project rate)
- `archived_at` (nullable), timestamps

### `projects`
- `id`, `client_id` (FK), `name`, `code` (unique), `description`
- `glyph` (enum like `alt-0..alt-4`), `status` (`active`/`archived`)
- `billable` (bool), `retainer` (bool), `retainer_hours` (nullable int), `retainer_resets_monthly` (bool)
- `budget_hours` (int, 0 = no budget), `budget_amount_rappen` (int, 0 = no budget)
- `rate_rappen` (int)
- `started_on` (DATE, nullable), `deadline_on` (DATE, nullable)
- timestamps

Computed (not stored): `spent_hours`, `spent_amount_rappen`, `percent_hours`, `percent_amount`, `band` (`ok`/`warn`/`over`), `last_activity_at`.

### `tasks`
- `id`, `project_id` (FK), `name`, `budget_hours` (nullable int)
- `done` (bool), `sort_order` (int)
- timestamps

### `time_entries`
- `id`, `user_id` (FK, always the singleton user — present for future-proofing)
- `project_id` (FK), `task_id` (FK, nullable), `description` (string)
- `started_at` (DATETIME, UTC), `ended_at` (DATETIME, UTC, nullable — `NULL` ⇒ running)
- `billable` (bool, default copies project.billable)
- `invoice_id` (FK, nullable — set when invoiced)
- timestamps

Duration is **not stored** — computed in queries as
`TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))`.
(MariaDB generated columns cannot use non-deterministic functions like `NOW()`, so we don't try.)

**Invariant:** at most one row per user with `ended_at IS NULL`.
MariaDB enforcement: a generated column `is_running TINYINT GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN 1 ELSE NULL END) STORED`, with `UNIQUE(user_id, is_running)`. NULLs don't collide, so only one running row is allowed.

### `invoices`
- `id`, `number` (string, e.g. `2026-014`, unique), `client_id` (FK), `project_id` (FK, nullable)
- `period_start` (DATE), `period_end` (DATE)
- `issued_on` (DATE, nullable until issued), `due_on` (DATE, nullable until issued)
- `status` (`draft`/`sent`/`paid`/`void`) — note: `overdue` is **computed**, not stored
- `currency` (default `CHF`), `vat_rate` (decimal, default copies business_profile)
- `subtotal_rappen`, `vat_rappen`, `total_rappen` (all int, cached on save)
- `notes` (text, nullable)
- `qr_reference` (string, generated for QR-bill — Creditor Reference / QRR format)
- `sent_at`, `paid_at` (DATETIME nullable)
- `pdf_path` (nullable — cached render)
- timestamps

### `invoice_lines`
- `id`, `invoice_id` (FK), `description` (text)
- `hours` (decimal 10,2), `rate_rappen` (int), `amount_rappen` (int — `hours * rate_rappen`, rounded)
- `vat_exempt` (bool, default false — for reverse-charge / export lines)
- `sort_order` (int)
- timestamps

### `invoice_events`
- `id`, `invoice_id` (FK), `kind` (`created`/`sent`/`reminded`/`paid`/`pdf_generated`/`voided`/`overdue_stamped`)
- `occurred_at` (DATETIME), `payload` JSON (nullable — e.g. email-to address, PDF path)
- Drives the Activity sidebar on the invoice detail page.

## 4. Server-authoritative timer

### Endpoints
- `POST /timer/start { project_id, task_id?, description? }`
  - In a transaction: if a running entry exists, set its `ended_at = now()` (auto-stop), then insert new row with `started_at = now()`.
  - Returns the new entry payload (Inertia redirect to `/timer`).
- `POST /timer/stop`
  - Sets `ended_at = now()` on the running entry.
- `POST /timer/switch { project_id, task_id?, description? }`
  - Alias of start — kept as a distinct endpoint so the UI's "switch project" button is intent-explicit.
- `POST /timer/discard`
  - Hard-deletes the running entry. No confirmation modal (the design has a "discard" button next to stop).

### UI ticking
Inertia page receives `running_entry: { id, started_at, project, task, description }` or `null` via shared props.
A composable (`useTimer`) starts a `setInterval(1s)` that computes `elapsed = Date.now() - new Date(started_at).getTime()`. **Never trust the client clock for durations stored in DB.** Display only.

### Persistence guarantees
- The running chip in the topbar reads the same shared prop on every page, so it appears everywhere immediately after start.
- Reload restores the timer from `started_at` — no localStorage involved.
- Manual entries (without using the timer) are a separate `POST /entries` with explicit `started_at` and `ended_at`.

## 5. Pages, routes, controllers

All authenticated routes use the `auth` middleware. Inertia layout: `AppLayout.vue` (sidebar + topbar + statusbar + slot).

| Method | Path | Action | Page / response |
|---|---|---|---|
| GET | `/login` | Breeze | login page |
| POST | `/login`, `/logout` | Breeze | session |
| GET | `/` | redirect | → `/projects` |
| GET | `/projects` | `ProjectController@index` | `Projects/Index` |
| POST | `/projects` | `ProjectController@store` | redirect |
| GET | `/projects/{project:code}` | `ProjectController@show` | `Projects/Show` (tabs: overview/entries/tasks/settings) |
| PATCH | `/projects/{project}` | `ProjectController@update` | redirect |
| POST | `/projects/{project}/archive` | `ProjectController@archive` | redirect |
| POST | `/tasks` | `TaskController@store` | redirect |
| PATCH | `/tasks/{task}` | `TaskController@update` | redirect (toggle done, rename, budget, reorder) |
| DELETE | `/tasks/{task}` | `TaskController@destroy` | redirect |
| GET | `/timer` | `TimerController@show` | `Timer/Today` |
| POST | `/timer/start` | `TimerController@start` | redirect |
| POST | `/timer/stop` | `TimerController@stop` | redirect |
| POST | `/timer/switch` | `TimerController@switch` | redirect |
| POST | `/timer/discard` | `TimerController@discard` | redirect |
| POST | `/entries` | `EntryController@store` | redirect — manual entry |
| PATCH | `/entries/{entry}` | `EntryController@update` | redirect |
| DELETE | `/entries/{entry}` | `EntryController@destroy` | redirect |
| GET | `/clients` | `ClientController@index` | `Clients/Index` |
| Resource | `/clients` | `ClientController` | CRUD |
| GET | `/invoices` | `InvoiceController@index` | `Invoices/Index` |
| GET | `/invoices/new` | `InvoiceController@create` | `Invoices/Create` — pre-fills from `?client=`, `?project=`, `?from=`, `?to=` |
| POST | `/invoices` | `InvoiceController@store` | redirect to detail (creates as `draft`) |
| GET | `/invoices/{invoice:number}` | `InvoiceController@show` | `Invoices/Show` |
| PATCH | `/invoices/{invoice}` | `InvoiceController@update` | redirect — edit draft lines/notes |
| POST | `/invoices/{invoice}/send` | `InvoiceController@send` | redirect — issue, render PDF, email, transition to `sent` |
| POST | `/invoices/{invoice}/paid` | `InvoiceController@markPaid` | redirect |
| POST | `/invoices/{invoice}/void` | `InvoiceController@void` | redirect |
| GET | `/invoices/{invoice}/pdf` | `InvoiceController@pdf` | streamed PDF |
| GET | `/reports` | `ReportController@show` | `Reports/Placeholder` — design's "coming soon" card |
| GET | `/settings` | `SettingsController@show` | `Settings/Profile` — business profile + tweaks |
| PATCH | `/settings/profile` | `SettingsController@updateProfile` | redirect |
| PATCH | `/settings/tweaks` | `SettingsController@updateTweaks` | redirect — debounced from frontend |
| GET | `/api/search` | `SearchController@__invoke` | JSON — for ⌘K palette |

Keyboard map (handled in `AppLayout`): `space` → toggle timer, `n` → new entry modal, `⌘P`/`Ctrl+P` → switch project palette, `g d` → `/projects`, `g t` → `/timer`, `g c` → `/clients`, `g i` → `/invoices`, `/` → focus filter, `⌘K`/`Ctrl+K` → command palette.

## 6. Invoice flow

### Numbering
`{YYYY}-{NNN}` where NNN is a zero-padded per-year counter. On draft creation:

```sql
BEGIN;
SELECT MAX(CAST(SUBSTRING_INDEX(number, '-', -1) AS UNSIGNED)) AS n
FROM invoices WHERE number LIKE '{YYYY}-%' FOR UPDATE;
-- compute next, insert
COMMIT;
```

Issued and sent invoices keep their number; voiding doesn't free the number.

### Create from entries
1. User clicks `+ Invoice` on a project or client.
2. `/invoices/new?client=…&project=…` shows the period range (default: previous calendar month) and a checklist of **billable, unbilled** time entries in range (where `invoice_id IS NULL` and `billable = 1`).
3. Selected entries are grouped by `description` (or task name if description blank); each group becomes one `invoice_line`: description, summed hours, rate from project (overridable), amount.
4. User edits lines (rename, merge, split by editing hours, change rate, reorder, delete, mark `vat_exempt`).
5. Save → creates `invoices` row + `invoice_lines` + sets `time_entries.invoice_id` on selected entries (rolls back if save fails).
6. Invoice is in `draft`. `issued_on`/`due_on` empty until Send.

### VAT
- Per-invoice `vat_rate` (default from `business_profile.default_vat_rate` = 8.10).
- Per-line `vat_exempt` for reverse-charge cases.
- Computed: `subtotal = SUM(amount where !exempt)`; `vat = subtotal * vat_rate / 100`; `total = subtotal + vat + SUM(amount where exempt)`. Cached on save.

### PDF
- Blade template `resources/views/invoices/pdf.blade.php` — same template used for on-screen `Invoices/Show` (rendered inside the Inertia page via a server-rendered HTML fragment from `/invoices/{number}/preview`).
- Browsershot launches headless Chromium against the preview URL with auth cookie injection, prints to PDF (A4, margins to match design's whitespace), saves to `storage/app/invoices/{number}.pdf`, caches `pdf_path` on the invoice.
- Re-renders when invoice fields change.

### QR-Rechnung
- `sprain/swiss-qr-bill` builds the bill from:
  - **Creditor:** `business_profile` (name, address, IBAN or QR-IBAN, country `CH`).
  - **Debtor:** client address.
  - **Amount:** `total_rappen / 100`, currency `CHF`.
  - **Reference:** `qr_reference` on the invoice. Generated as a QRR (creditor reference) at issue time — a 27-digit string with mod-10 recursive check digit. Stored on the invoice.
- Output: SVG, embedded at the bottom of `invoices/pdf.blade.php` per Swiss QR-bill layout (perforated payment slip, exactly the standard dimensions).
- If `business_profile.qr_iban` is set, use it (QRR reference); otherwise use `iban` with a free-text reference field (SCOR).

### Statuses
- `draft` → can be edited, lines mutable. Number is allocated on draft creation (see open question). Send button enabled when all required fields valid.
- `draft → sent`: stamps `issued_on = today`, `due_on = today + 30 days` (configurable in business profile), `sent_at`, renders PDF, mails it, writes `invoice_events.sent`.
- `sent`: read-only line items; can mark paid or void.
- `paid`: terminal (until voided).
- `void`: terminal; entries' `invoice_id` is cleared so they can be re-invoiced.
- `overdue` is computed: `status = 'sent' AND due_on < CURRENT_DATE`. Surfaced in UI by accessor. A daily scheduled job writes an `invoice_events.overdue_stamped` row on the day an invoice tips over — used for the design's "1 day overdue" copy.

### Sending
- "Send" button → validates → wraps in a job (queue `database`) that: renders PDF, dispatches `InvoiceMail` to `clients.email` with PDF attached, marks `sent_at = now()`, status `sent`, writes events.
- Failures (SMTP error) keep the status as `draft`; surface the error via Laravel's flash messages.

### Reminders
- Daily scheduled job (`ernte:invoices:remind` at 09:00 local) selects invoices where `status = 'sent'`, `due_on < CURRENT_DATE`, and no `invoice_events.reminded` within `reminder_days_after_due` days. Sends reminder email; logs event. Configurable per-business-profile.

## 7. UX system

### Design tokens (CSS variables, used in both Blade and Vue)

```css
:root[data-theme="paper"] {
  --paper: #f5f1ea;
  --ink: #1a1a1a;
  --ink-2: #3d3d3d;
  --ink-3: #6b6b6b;
  --ink-4: #9a9a9a;
  --forest: #2d4a3a;
  --rust: #c97b3c;
  --red: #b54834;
  --gold: #b8941f;
  --accent: var(--forest);    /* overridden from settings */
  --border: #e8e1d4;
  --border-strong: #c9c0ad;
  --bg-3: #efe9dc;
}
:root[data-theme="dark"] { /* inverted set, sampled from current dark CSS in design */ }
:root[data-density="comfortable"] { --row-h: 36px; --pad-y: 10px; --pad-x: 14px; }
:root[data-density="compact"]     { --row-h: 28px; --pad-y: 6px;  --pad-x: 10px; }

--fs-xs: 11px; --fs-sm: 13px; --fs-md: 15px; --fs-lg: 24px; --fs-xl: 36px;
font-family: 'JetBrains Mono', monospace;
font-variant-numeric: tabular-nums;   /* applied on money/duration cells */
```

Initial CSS is ported directly from `design/ernte/project/styles.css`.

### Layout

- **Topbar:** wordmark (→ dashboard), workspace label (`workspace: {user.name}@{hostname}`), command palette stub button, **always-visible running-timer chip** (pulses red, shows project name + elapsed, click → `/timer`, stop button → `POST /timer/stop`), user avatar chip.
- **Sidebar:** nav items with glyph + label + count; "Pinned" section listing top 4 projects ordered by `last_activity_at`; "Recent" listing last 3 visited entities (stored in `localStorage`); "This week" mini bar chart (7 day bars × hours, current day highlighted in `--accent`).
- **Status footer:** `connected · localhost:{APP_PORT} · v{version} (self-hosted) · db mariadb {version} · {db size} · backup {x ago} · uptime {x}d {y}h`.
  - `APP_PORT` and version from `config('app.*')`.
  - DB version + size queried at boot, cached 60s.
  - Backup time read from the latest row in a small `backups` table written by the backup command (initially "never" — that's fine, design tolerates it).
  - Uptime via `app()->startTime()` or `/proc/uptime`.

### Tweaks panel
Right-side overlay, opened from a small gear in the topbar (not in the design's exact spot — we put it next to the user chip to keep it discoverable). Persists changes to `users.settings` with a debounce of 500ms.

### Charts
All custom SVG Vue components, each ~30–80 LOC, **no chart library**:
- `Sparkline.vue` — 14-day per-project hours.
- `BudgetBar.vue` — segmented overflow on `over`.
- `BurnDown.vue` — ideal dashed line + actual + today marker (computed from start/deadline dates).
- `Heatmap.vue` — 60-cell project activity (12 weeks).
- `WeekBars.vue` — sidebar bars.

Each receives already-aggregated data via Inertia props.

### Search / command palette
- ⌘K opens a modal with one input. As-you-type calls `GET /api/search?q=` → returns mixed results: `[{ type: 'project'|'client'|'invoice', id, label, sublabel, url }]`.
- Up to 8 results, keyboard-navigable; Enter follows.
- Implementation: `LIKE` query over the 3 tables. Fast enough at this scale; revisit if it ever feels slow.

## 8. Deployment

### Local dev — DDEV

DDEV is the primary local development environment. It boots an opinionated Docker stack (nginx + PHP-FPM + MariaDB + mailhog + adminer) keyed off a `.ddev/config.yaml` in the project root.

**`.ddev/config.yaml`**
```yaml
name: ernte
type: laravel
docroot: public
php_version: "8.3"
database:
  type: mariadb
  version: "11.4"
webserver_type: nginx-fpm
nodejs_version: "20"
router_http_port: "80"
router_https_port: "443"
# Optional: pin host port if you want localhost:7878 to match the footer copy
additional_hostnames: []
web_environment:
  - APP_PORT=7878
```

**`.ddev/web-build/Dockerfile.chromium`** — adds Chromium for Browsershot to the web container:
```Dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium fonts-liberation libnss3 libatk-bridge2.0-0 libxkbcommon0 \
 && rm -rf /var/lib/apt/lists/*
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV BROWSERSHOT_CHROME_PATH=/usr/bin/chromium
```

**One-time bootstrap** (after `git clone`):
```bash
ddev start
ddev composer install
ddev npm ci
ddev artisan key:generate
ddev artisan migrate --seed
ddev npm run dev   # vite, hot reload
```

**Daily loop:** `ddev start` (project comes up at `https://ernte.ddev.site` by default; we can pin `additional_fqdns: [ernte.local]` or just use the auto URL — the footer copy reads from `APP_URL`, no hardcoded `localhost:7878`).

Scheduler + queue worker run via DDEV `web_extra_daemons`:
```yaml
web_extra_daemons:
  - name: scheduler
    command: "php artisan schedule:work"
    directory: /var/www/html
  - name: queue
    command: "php artisan queue:work --queue=default,emails --tries=3"
    directory: /var/www/html
```

### Production self-hosting — docker-compose

For deploying on your own server (separate from dev):

- `app`: PHP-FPM 8.3 + nginx + Chromium + supervisord, runs `php-fpm`, `php artisan schedule:work`, and `php artisan queue:work --queue=default,emails`. Exposes `7878:80` to match the design's footer.
- `db`: `mariadb:11`. Volume `./db-data`.
- App volume `./storage` (uploaded logos, generated PDFs, backups).
- Single root `.env`. `APP_URL=http://localhost:7878`.

### `bin/install`
Idempotent script callable either inside DDEV (`ddev exec bin/install`) or inside the production container:
`composer install`, `npm ci && npm run build`, `php artisan key:generate`, `php artisan migrate`, `php artisan db:seed --class=BootstrapSeeder` (creates the user from `ERNTE_USER_*` env vars + business profile from `BUSINESS_PROFILE_*` if not yet present).

### Backups
- `php artisan ernte:backup` → `mysqldump` to a gzipped file under `storage/app/backups/` + tarball of `storage/app/invoices`. Writes a row to `backups (path, size, created_at)` for footer display.
- Scheduled daily at 03:00 by the scheduler daemon.

## 9. Domain rules / invariants (test these)

1. **One running timer** — invariant on `time_entries`. Test: starting a timer while one is running stops the old one in a single transaction; no two rows have `ended_at IS NULL` for one user simultaneously.
2. **Money math** — all currency operations on integer rappen; `subtotal/vat/total` always satisfy `total = subtotal + vat + exempt_amounts` to the rappen.
3. **Invoice numbering** — concurrent draft creation never collides; numbers are gapless within a year unless an invoice is voided (gap is permitted in that case, by design).
4. **Entry attachment** — when `time_entries.invoice_id` is set, the entry no longer appears in `unbilled` lists; clearing it via void unlocks them.
5. **QR reference uniqueness** — `qr_reference` unique per invoice; stable once generated.
6. **VAT rate stamping** — invoice keeps its own `vat_rate`; later changes to `business_profile.default_vat_rate` do not retroactively change issued invoices.
7. **PDF determinism** — re-rendering a sent invoice produces the same PDF unless lines/totals changed.

## 10. Implementation order (build B — schema-first)

Phase 0 — repo bootstrap
1. `ddev config --project-type=laravel --php-version=8.3 --database=mariadb:11.4` in the project root; commit `.ddev/config.yaml`.
2. Add `.ddev/web-build/Dockerfile.chromium` for Browsershot's Chromium binary; `ddev restart`.
3. `ddev composer create-project laravel/laravel .` (or scaffold by hand if dir non-empty); commit.
4. Install Breeze Inertia/Vue starter (`ddev composer require laravel/breeze --dev && ddev artisan breeze:install vue`); configure Vite + JetBrains Mono.
5. Port `design/ernte/project/styles.css` into `resources/css/app.css`; set up the data-theme/data-density root attributes.
6. `AppLayout.vue` with topbar/sidebar/statusbar shells (no live data yet).

Phase 1 — schema + domain
1. Migrations for all tables (`users`, `business_profile`, `clients`, `projects`, `tasks`, `time_entries`, `invoices`, `invoice_lines`, `invoice_events`, `backups`).
2. Models + relationships + casts (money as integer, `started_at`/`ended_at` as `datetime`).
3. `BootstrapSeeder` + factories that mirror `design/ernte/project/data.jsx` so the UI has the same data as the prototype.
4. Domain services: `TimerService` (start/stop/switch/discard, transactional), `InvoiceBuilder` (create from entries, line grouping), `InvoiceNumberer`, `QrReferenceGenerator`.
5. Pest tests for all of section 9.

Phase 2 — views (one per slice)
1. Projects/Index — full table, stats strip, filter chips, sparklines, sort.
2. Projects/Show — overview tab with burn-down + tasks + recent entries; details/heatmap sidebar.
3. Timer/Today — hero timer, entries list, today summary, by-project, quick start, shortcuts.
4. Clients/Index, Clients/Create, Clients/Edit.
5. Invoices/Index — stats, filters, table.
6. Invoices/Create — period picker, entry checklist, line grouping.
7. Invoices/Show — document view (Blade fragment) + activity sidebar + actions.
8. Invoice PDF template + Browsershot + QR-bill rendering.
9. Email send + reminder job + scheduler.
10. Settings + Tweaks persistence.
11. Reports placeholder + ⌘K palette + keyboard shortcuts.
12. Statusbar real values + backup command.

Phase 3 — package
1. Production `docker-compose.yml` + Dockerfile + supervisord.conf (separate from DDEV config).
2. `bin/install`, `.env.example`, README documenting DDEV-first dev loop and production deploy path.
3. Smoke-test end-to-end on a fresh `git clone` → `ddev start` → app reachable in browser.

## 11. Open questions / assumptions

- **Workspace label** in topbar (`workspace: julian@home` in the design) — assume `{user.name}@{php_uname('n')}`. Confirm during implementation.
- **Draft number allocation** — current spec allocates on draft creation. Alternative: allocate on first transition to `sent`. Spec sticks with on-creation for simpler UX (the user always sees the number); voids leave gaps, which is acceptable.
- **Manual entry duration units** — assume HH:MM input + project picker; no rounding.
- **Time zone** — UTC in DB, local timezone for display from `app.timezone` config (default `Europe/Zurich`). User-configurable later.
- **Email "from" identity** — `business_profile.email`; replyTo same. Configurable via `.env`.
- **PDF locale/language** — German labels on the invoice (Rechnung, Betrag, MwSt, etc.). Confirm during implementation.

---

## Appendix A — entity diagram (text)

```
users ─── (1:1) ── business_profile

clients ──< projects ──< tasks
                      ╲
                       ╲
                        ╲── time_entries ──> invoices (nullable FK)
                                                    │
                                                    ├── invoice_lines
                                                    └── invoice_events
```

## Appendix B — files we'll keep from the design bundle

- `design/ernte/project/styles.css` → port verbatim, then theme-extract.
- `design/ernte/project/data.jsx` → translate into a database seeder once during bootstrap.
- `design/ernte/project/views.jsx` → reference only for layout / behavior, **not** copied. Rewritten as Vue components.
