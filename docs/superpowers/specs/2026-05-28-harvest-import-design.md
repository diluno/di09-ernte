# Harvest Import — Design Spec

**Date:** 2026-05-28
**Status:** Approved (design), pending implementation plan

## Problem

ernte is a self-hosted Harvest replacement. A user migrating off Harvest needs to
bring their existing **clients, projects, invoices, and estimates** into ernte in
one shot, so historical billing records live alongside new ernte-created ones.
Time entries (the worklog) are explicitly **not** migrated — this moves billing
records, not time tracking.

## Approach

A `php artisan harvest:import` command orchestrating a thin Harvest API client and
four per-entity importers, all under `app/Services/Harvest/`. This mirrors the
codebase's existing grain: small focused service classes (`app/Services/Invoicing/`,
`app/Services/Estimating/`) plus console commands (`BackupCommand`, `DoctorCommand`,
`RemindInvoicesCommand`, `StampOverdueInvoicesCommand`).

**Rejected alternatives:**
- *One monolithic command* — a ~500-line untestable command, against the project's
  small-unit grain.
- *Two-phase `harvest:fetch` → `harvest:import` via JSON files* — nice audit trail,
  but the user chose "pull from the API directly," so the extra step is unneeded.

## Decisions (from brainstorming)

- **Source:** Harvest API v2, pulled directly. Token + Account ID supplied at
  runtime, never persisted.
- **Scope:** clients, projects, invoices (+ line items), estimates (+ line items).
  Time entries and tasks are not imported.
- **Numbering:** preserve Harvest's original invoice/estimate numbers; advance
  ernte's counters past any that match its formats so future docs can't collide.
- **Re-run:** wipe-then-import — each run clears ernte's clients/projects/invoices/
  estimates and re-imports from scratch.
- **Contacts:** best-effort secondary `/contacts` fetch to populate client
  `contact_name`/`email`; non-fatal if it fails.
- **Imported invoice `project_id`:** left null (Harvest invoices span projects).
- **Currency:** single-currency (CHF) assumption; non-CHF records imported as-is
  with a warning.

No database migrations are required — preserving numbers, wipe-then-import, and
in-run ID mapping need no schema changes.

## The command

```
php artisan harvest:import [--token=] [--account=] [--dry-run] [--force]
```

- **Credentials:** `--token`/`--account`, falling back to `HARVEST_ACCESS_TOKEN` /
  `HARVEST_ACCOUNT_ID` env vars. If neither is present, the command errors with a
  clear message. Credentials are used only for the duration of the run.
- **`--dry-run`:** fetch everything, print a summary (counts per entity + a few
  sample mappings), write nothing. No wipe, no inserts.
- **`--force`:** skip the interactive confirmation prompt (for scripted use).
- **Confirmation:** because the import is destructive, a non-`--force` run prompts
  `This will DELETE all clients, projects, invoices and estimates in ernte. Continue? (type "yes")`.

## Execution order (so a failure never leaves a wiped-but-empty DB)

1. **Fetch** all Harvest data into memory (clients, contacts, projects, invoices,
   estimates), following pagination. Network/auth failures abort here — DB untouched.
2. **Guard:** if `time_entries` has any rows, the confirmation prompt additionally
   warns that *N* tasks and *M* time entries will be cascade-deleted and requires a
   typed `yes`. `--force` authorizes this non-interactively. (See the wipe section.)
3. **Transaction:** `DB::transaction(fn () => …)` wrapping: wipe → insert → counter
   bump. Any error inside rolls the whole thing back to the pre-import state.
4. **Report** a summary (rows created per entity, warnings).

In `--dry-run`, only steps 1 and 4 run.

## Wipe + the time-entry cascade guard

Wipe order is FK-safe: **estimates → invoices → projects → clients**, then reset
`invoice_counters` and `estimate_counters`.

- `estimates`/`invoices` cascade to their `*_lines` and `*_events`; deleting
  invoices sets `time_entries.invoice_id` to null.
- **`time_entries.project_id` and `tasks.project_id` are `cascadeOnDelete`** — so
  deleting projects also deletes all tasks and time entries. For a migration done
  before ernte is used in earnest this is harmless (those tables are empty). To
  prevent accidental worklog loss, when `time_entries` is non-empty the
  confirmation prompt spells out how many tasks and time entries will be deleted and
  requires a typed `yes`; `--force` authorizes it non-interactively.
- Deleting `clients` is otherwise blocked by `restrictOnDelete` from invoices/
  projects/estimates, which is why clients are wiped last.

## Mapping: Harvest → ernte

Amounts: Harvest values are decimals (e.g. `1234.50`); ernte stores integer rappen
(`round(value * 100)`).

### Clients (`GET /clients`, enriched by `GET /contacts`)

| ernte field | Source |
|---|---|
| `name` | `client.name` |
| `short_code` | generated: first 4 alphanumerics of name, uppercased; de-duped within the run by appending a digit |
| `address_line_1` | `client.address` (Harvest's single multi-line address string, stored whole) |
| `address_line_2`, `postal_code`, `city` | null (Harvest doesn't split these) |
| `country` | `'CH'` default |
| `contact_name` | first matching contact's `first_name`+`last_name` (best-effort) |
| `email` | first matching contact's `email` (best-effort) |
| `vat_id`, `default_rate_rappen` | null (no Harvest equivalent) |
| `archived_at` | set when `client.is_active === false` |

### Projects (`GET /projects`)

| ernte field | Source |
|---|---|
| `client_id` | mapped from `project.client.id` → imported client |
| `name` | `project.name` |
| `code` | `project.code` or generated from name if blank; de-duped within the run |
| `status` | `is_active ? 'active' : 'archived'` |
| `billable` | `project.is_billable` |
| `rate_rappen` | `round(project.hourly_rate * 100)` (0 if null) |
| `budget_hours` | `round(project.budget)` when `budget_by` is hours-based, else 0 |
| `budget_amount_rappen` | `round(project.budget * 100)` when `budget_by` is amount-based, else 0 |
| `started_on` / `deadline_on` | `project.starts_on` / `project.ends_on` |
| `glyph` | default `'▦'` |
| `retainer*` | defaults (false / null) — no Harvest equivalent |

### Invoices (`GET /invoices`, includes `line_items`)

| ernte field | Source |
|---|---|
| `number` | `invoice.number` (**preserved verbatim**) |
| `client_id` | mapped from `invoice.client.id` |
| `project_id` | null |
| `status` | `draft→draft`, `open→sent`, `paid→paid`, `closed→void` |
| `issued_on` / `due_on` | `invoice.issue_date` / `invoice.due_date` |
| `sent_at` / `paid_at` | `invoice.sent_at` / `invoice.paid_at` |
| `currency` | `invoice.currency` (warn if not CHF) |
| `vat_rate` | `invoice.tax` (percentage; 0 if null) |
| `total_rappen` | `round(invoice.amount * 100)` (Harvest total, incl. tax) |
| `vat_rappen` | `round((invoice.tax_amount + invoice.tax2_amount) * 100)` |
| `subtotal_rappen` | `total_rappen − vat_rappen` (keeps subtotal+vat = Harvest total exactly, regardless of discounts) |
| `notes` | `invoice.notes` |
| `qr_reference`, `pdf_path` | null (no QR/PDF generated for historical imports) |

Each invoice also gets one `invoice_events` row of kind `created` with payload
`{source: 'harvest', harvest_id: <id>}`.

**Line items** → `invoice_lines`:

| ernte line field | Source |
|---|---|
| `description` | `line_item.description` |
| `hours` | `line_item.quantity` |
| `rate_rappen` | `round(line_item.unit_price * 100)` |
| `amount_rappen` | `round(line_item.amount * 100)` |
| `vat_exempt` | `! line_item.taxed` |
| `sort_order` | array index |

### Estimates (`GET /estimates`, includes `line_items`)

Same shape as invoices, with:

| ernte field | Source |
|---|---|
| `number` | `estimate.number` (**preserved verbatim**) |
| `status` | `draft→draft`, `sent→sent`, `accepted→accepted`, `declined→declined` (1:1) |
| `issued_on` | `estimate.issue_date` |
| `valid_until` | null (Harvest estimates have no validity date) |
| `decided_at` | `estimate.accepted_at` or `estimate.declined_at` when present |
| `sent_at` | `estimate.sent_at` |
| `converted_invoice_id` | null (Harvest doesn't expose the link) |
| totals / `vat_rate` / line items | same rules as invoices |

Each estimate gets one `estimate_events` row of kind `created`, payload
`{source: 'harvest', harvest_id: <id>}`.

## Numbering preservation

Imported documents keep Harvest's exact numbers (set directly on the `number`
column, which is `unique`). After inserting, the command **bumps the counters** so
future ernte-generated numbers can't collide:

- For each year, `invoice_counters.last_n` is set to at least the max `NNN` among
  imported invoice numbers matching `^\d{4}-(\d+)$`.
- Likewise `estimate_counters.last_n` from numbers matching `^OF-\d{4}-(\d+)$`.
- Harvest numbers that don't match ernte's formats need no bump (they can't collide
  with a generated `YYYY-NNN` / `OF-YYYY-NNN`).

New documents created later still get ernte-style numbers; mixed numbering
(historical Harvest numbers + new ernte numbers) is expected.

## Architecture / file structure

- `app/Console/Commands/HarvestImportCommand.php` — parses options/credentials,
  prints the confirmation, drives `ImportRunner`, renders the summary table.
- `app/Services/Harvest/HarvestApi.php` — constructed with token + account id;
  methods `clients()`, `contacts()`, `projects()`, `invoices()`, `estimates()`,
  each returning a `Collection` of decoded records with pagination followed and the
  required headers (`Authorization: Bearer`, `Harvest-Account-Id`, `User-Agent`)
  set. Uses Laravel's `Http` client. Throws a typed `HarvestApiException` on
  non-2xx / auth failure.
- `app/Services/Harvest/ImportRunner.php` — `fetch()` (network, returns a
  `HarvestData` bag) and `import(HarvestData $data): ImportSummary` (the
  transaction: guard → wipe → call importers → bump counters). Holds the
  Harvest-id → ernte-model maps in memory.
- `app/Services/Harvest/ClientImporter.php`, `ProjectImporter.php`,
  `InvoiceImporter.php`, `EstimateImporter.php` — each takes the relevant fetched
  records (+ the client/project id maps where needed) and persists ernte rows,
  returning its own id map. Pure mapping + insertion; no network.

Each unit has one responsibility and is testable in isolation (mappers given
fixture arrays; `HarvestApi` against `Http::fake()`).

## Error handling

- **Missing credentials** → command error, no network call.
- **Auth/API errors** (401/403/429/5xx) → `HarvestApiException` with the status and
  Harvest's error body; command reports it and exits non-zero. DB untouched (fetch
  precedes the transaction).
- **Rate limiting** (Harvest allows ~100 requests / 15s) → `HarvestApi` paces
  requests and retries once on HTTP 429 after the `Retry-After` delay.
- **Mapping/DB errors** inside the transaction → full rollback; the command reports
  which entity failed.
- **Non-CHF currency** → per-record warning collected into the summary; the record
  is still imported with its numeric amounts treated as CHF.

## Testing

All tests use `Http::fake()` with captured sample Harvest JSON pages (one fixture
per endpoint, including a multi-page case to exercise pagination). No live API.

- `HarvestApi`: sends required headers; follows pagination; raises
  `HarvestApiException` on 401/500; retries once on 429.
- `ClientImporter`: field mapping; `short_code` generation + de-dup; contact
  enrichment; `is_active=false` → `archived_at`.
- `ProjectImporter`: client linkage; rate/budget mapping by `budget_by`; status +
  billable; code de-dup.
- `InvoiceImporter`: number preserved; status map (all four states); amount math
  (`subtotal+vat = total`, line items → lines, `taxed=false` → `vat_exempt`);
  `created` event written.
- `EstimateImporter`: status map (1:1); `decided_at` from accepted/declined; number
  preserved.
- Numbering: counters bumped past matching imported numbers; non-matching numbers
  leave counters at 0; a subsequently generated number doesn't collide.
- Runner: fetch-then-transaction ordering — a forced insert failure rolls back and
  leaves pre-existing data intact; the time-entry guard aborts a non-forced run
  when time entries exist; `--dry-run` writes nothing.
- Command: missing-credentials error; summary output; `--force` skips the prompt.

## Out of scope

- Time entries / tasks (worklog) and people/users.
- Expenses, retainers, payments, recurring invoices.
- Multi-currency conversion.
- Incremental / idempotent re-sync (the chosen model is wipe-then-import).
- Re-sending or PDF/QR generation for imported invoices.
