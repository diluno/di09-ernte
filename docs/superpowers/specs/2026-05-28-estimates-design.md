# Estimates Feature — Design Spec

**Date:** 2026-05-28
**Status:** Approved (design), pending implementation plan

## Problem

ernte can produce **invoices** (Rechnungen) for work already done, but has no way
to send a client an **estimate / quote** (Offerte) for work *before* it's done.
We want a parallel "Estimates" feature: create a draft quote with manually-entered
line items, send it to the client as a PDF, track whether the client accepts or
declines, and — when accepted — convert it into a draft invoice with one click.

Estimates are offers, not payment requests, so they carry **no Swiss QR-bill, no
payment/reminder lifecycle, and no time-entry linking**. They are forward-looking
documents with manual line entry.

## Approach

**Parallel `Estimate` stack** that mirrors the existing invoice implementation
one-for-one, minus everything payment-related. This is the lowest-risk path: the
invoice code is shipped and tested, and the codebase has no shared "billing
document" abstraction today. We duplicate the document-specific pieces and reuse
only genuinely stable leaf utilities.

The **only** change to existing invoice code: extract the static totals math from
`InvoiceBuilder::computeTotals` into a small shared `App\Support\LineTotals` helper
that both `InvoiceBuilder` and `EstimateBuilder` call. Everything else is new.

### Rejected alternatives

- **Shared billing-document base class / shared lines table.** A large, risky
  refactor of working invoice code for a two-document system — over-engineering.
- **Generic numberer keyed by table (adopted by both features).** Would require
  refactoring `InvoiceNumberer`; not worth the added risk. We duplicate the
  ~15-line atomic logic instead.

## Key decisions (from brainstorming)

- **Convert flow:** accepted estimate → one-click "Create invoice" that builds a
  **draft** invoice from the estimate's lines and links the two. The user then
  sends that invoice through the normal invoice flow (which adds the QR-bill).
- **Line source:** manual entry only (description + hours + rate per line). No
  time-entry pre-fill.
- **Lifecycle:** `draft → sent → accepted / declined`. **`expired` is a computed
  flag, not a stored status** (`status = sent` AND `valid_until` is past) — mirrors
  how invoices compute `overdue`. No scheduled job needed.
- **No QR-bill** on the estimate PDF (Swiss Offerten have no payment slip).
- **Number format:** `OF-2026-001` (own counter; `OF-` prefix disambiguates from
  invoice `2026-001`).
- **Validity default:** `valid_until = issued_on + 30 days`, stamped on send.

## Data model (4 new tables)

All monetary amounts stored in **rappen** (integer), matching invoices.

### `estimates`
- `id`
- `number` (string, unique) — format `OF-YYYY-NNN`
- `client_id` (FK → clients, **restrictOnDelete**)
- `project_id` (FK → projects, nullable, **nullOnDelete**)
- `issued_on` (date, nullable) — stamped on send
- `valid_until` (date, nullable) — stamped on send (issued + 30 days)
- `status` (enum: `draft`, `sent`, `accepted`, `declined` — default `draft`)
- `currency` (string(3), default `CHF`)
- `vat_rate` (decimal(5,2), default `8.10`)
- `subtotal_rappen`, `vat_rappen`, `total_rappen` (unsignedBigInteger)
- `notes` (text, nullable)
- `sent_at` (datetime, nullable)
- `decided_at` (datetime, nullable) — set when accepted/declined
- `converted_invoice_id` (FK → invoices, nullable, **nullOnDelete**) — set on convert
- `pdf_path` (string, nullable) — cached PDF for sent estimates
- `timestamps`
- **Indexes:** `status`, `client_id`, `valid_until`

Compared to `invoices`, this **drops**: `period_start/period_end`, `due_on`,
`qr_reference`, `paid_at`. It **adds**: `valid_until`, `decided_at`,
`converted_invoice_id`.

### `estimate_lines`
Identical shape to `invoice_lines`:
- `id`, `estimate_id` (FK, **cascadeOnDelete**)
- `description` (text), `hours` (decimal(10,2)), `rate_rappen` (unsignedBigInteger)
- `amount_rappen` (unsignedBigInteger) — recomputed server-side as `round(hours × rate)`
- `vat_exempt` (boolean, default false), `sort_order` (unsignedInteger, default 0)
- `timestamps`
- **Index:** `(estimate_id, sort_order)`

### `estimate_events`
- `id`, `estimate_id` (FK, **cascadeOnDelete**)
- `kind` (enum: `created`, `sent`, `accepted`, `declined`, `converted`, `pdf_generated`)
- `occurred_at` (datetime), `payload` (json, nullable)
- `timestamps`
- **Index:** `(estimate_id, occurred_at)`

### `estimate_counters`
- `year` (unsignedSmallInteger, PK), `last_n` (unsignedInteger, default 0), `timestamps`
- Atomic allocation via `INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID()`
  (same pattern as `invoice_counters`).

## Models

- **`Estimate`** — fillable + casts (`issued_on`/`valid_until` → date,
  `sent_at`/`decided_at` → datetime, `vat_rate` → decimal:2, `*_rappen` → integer).
  - Relations: `client()`, `project()`, `lines()`, `events()`,
    `convertedInvoice()` (belongsTo Invoice).
  - Computed: `getExpiredAttribute()` (`status === 'sent' && valid_until < today`),
    `getHoursAttribute()` (sum of line hours).
  - Scopes: `scopeOpen()` (status `sent`), `scopeAccepted()`, `scopeDeclined()`.
- **`EstimateLine`**, **`EstimateEvent`**, **`EstimateCounter`** — mirror the
  invoice equivalents (`EstimateCounter` uses custom PK `year`, non-incrementing).

## Services (`app/Services/Estimating/`)

- **Shared utility (`app/Support/LineTotals.php`)** — new. Holds the totals math
  lifted from `InvoiceBuilder::computeTotals`:
  `compute(array $lineAmounts, array $vatExempts, float $vatRate): array` returning
  `['subtotal_rappen', 'vat_rappen', 'total_rappen']`. VAT applies only to
  non-exempt lines. `InvoiceBuilder` is updated to delegate to this helper.
- **`EstimateNumberer`** — `nextFor(int $year): string` → `OF-YYYY-NNN`, atomic via
  `estimate_counters` (mirrors `InvoiceNumberer`).
- **`EstimateBuilder`** (deps: `EstimateNumberer`):
  - `createDraft(Client, ?Project, array $lines): Estimate` — transactional;
    allocates number, **recomputes** every line amount + totals server-side
    (anti-tamper), stamps `vat_rate`/`currency` from `BusinessProfile::current()`,
    writes a `created` event. No time-entry suggestion (manual only).
- **`EstimateLifecycle`** (deps: `EstimatePdfRenderer`, `InvoiceBuilder`):
  - `send(Estimate)` — `draft → sent`; stamp `issued_on = today`,
    `valid_until = today + 30d`; render & cache PDF; dispatch `EstimateMail`;
    write `pdf_generated` + `sent` events. Requires client email; whole thing is
    transactional (mail failure rolls back, estimate stays draft).
  - `accept(Estimate)` — `sent → accepted`; stamp `decided_at`; `accepted` event.
  - `decline(Estimate)` — `sent → declined`; stamp `decided_at`; `declined` event.
  - `convertToInvoice(Estimate): Invoice` — require `status === 'accepted'` and
    `converted_invoice_id` is null. Build a **draft** invoice from the estimate's
    lines via `InvoiceBuilder::createDraft` (client, project, lines copied; period
    defaults to today/today; no entry IDs). Set `converted_invoice_id`, write a
    `converted` event, return the new Invoice. Invalid state → `DomainException`.
- **`EstimatePdfRenderer`** (no QR dependency):
  - `html(Estimate): string`, `pdf(Estimate): string` (caches, sets `pdf_path`),
    `pdfBytes(Estimate): string` (no cache, for draft downloads). Uses
    Spatie/Browsershot exactly like invoices.

## HTTP layer

### `EstimateController`

| Method | Route | Returns |
|--------|-------|---------|
| `index(Request)` | `GET /estimates` | Inertia `Estimates/Index` |
| `create()` | `GET /estimates/new` | Inertia `Estimates/Create` |
| `store(StoreEstimateRequest, EstimateBuilder)` | `POST /estimates` | redirect → show |
| `show(Estimate)` | `GET /estimates/{estimate:number}` | Inertia `Estimates/Show` |
| `preview(Estimate, EstimatePdfRenderer)` | `GET /estimates/{estimate:number}/preview` | raw HTML (iframe) |
| `pdf(Estimate, EstimatePdfRenderer)` | `GET /estimates/{estimate:number}/pdf` | draft: stream; sent: cached download |
| `update(UpdateEstimateRequest, Estimate)` | `PATCH /estimates/{estimate}` | redirect → show |
| `send(Estimate, EstimateLifecycle)` | `POST /estimates/{estimate}/send` | redirect → show |
| `accept(Estimate, EstimateLifecycle)` | `POST /estimates/{estimate}/accept` | redirect → show |
| `decline(Estimate, EstimateLifecycle)` | `POST /estimates/{estimate}/decline` | redirect → show |
| `convert(Estimate, EstimateLifecycle)` | `POST /estimates/{estimate}/convert` | redirect → **invoice** show |

- Route model binding by `number` for show/preview/pdf/lifecycle (matches invoices).
- All routes inside the `auth` middleware group.
- `DomainException` from services is caught and flashed; `Throwable` (PDF/mail) logged.
- `create` is **single-phase** (client dropdown + empty line editor) — no
  two-phase time-entry pre-fill that invoices have.

### Form requests
- **`StoreEstimateRequest`**: `client_id` required|exists; `project_id`
  nullable|exists (+ belongs to client); `lines` required|array|min:1;
  `lines.*.description` required|string|max:1000; `lines.*.hours`
  required|numeric|min:0; `lines.*.rate_rappen` required|integer|min:0;
  `lines.*.vat_exempt` sometimes|boolean; `notes` nullable|string|max:5000.
- **`UpdateEstimateRequest`**: `authorize()` returns true only when the bound
  estimate is `draft`. Rules cover `notes` + `lines` (only drafts are editable).

### Projections (`app/Support/EstimateProjections.php`)
- `index(string $filter, ?string $search): Collection` — list DTOs; filters
  `all` / `draft` / `sent` / `accepted` / `declined` / `expired` (virtual).
- `stats(): array` — top-of-page summary: **open total** (sum of `total_rappen`
  for `sent` estimates), **accepted YTD** (sum for estimates accepted this year),
  and **acceptance rate** (accepted ÷ (accepted + declined), decided estimates only).
- DTO fields: `id`, `number`, `status`, `expired`, `issued_on`, `valid_until`,
  `hours`, `total`, `client` (`id`,`name`), `project_name`.

## Vue pages (`resources/js/Pages/Estimates/`)

Follow existing conventions: `defineOptions({ layout: AppLayout })`, literal page
path strings, literal route paths (not Ziggy), `useForm`, de-CH money formatting.

- **`Index.vue`** — filter tabs (all/draft/sent/accepted/declined/expired), stats
  panel, debounced search, table (#number, client, issued, valid-until, hours,
  total, status badge; `expired` shown red). "New estimate" → `/estimates/new`.
- **`Create.vue`** — single page: client `<select>`, optional project `<select>`,
  editable line rows (description, hours, rate, computed amount, vat_exempt), add/
  remove/reorder, notes, and a totals sidebar (subtotal / VAT / total). Submits
  `lines` with `rate_rappen` (×100 from CHF input) + `vat_exempt` to `POST
  /estimates`; server recomputes all math.
- **`Show.vue`** — full-height iframe of `/preview`; sidebar with activity timeline
  (event kinds → labels) and action buttons by status: **draft** → Send / Edit;
  **sent** → Accept / Decline; **accepted** → Convert to invoice (hidden once
  `converted_invoice_id` set, replaced by a link to that invoice); Download PDF
  always available.
- **Navigation:** add an **"Offerten"** link to the `AppLayout` sidebar beside
  Invoices.

## PDF & mail templates

- **`resources/views/estimates/pdf.blade.php`** — the invoice PDF template minus
  the QR-bill section; German labels ("Offerte", "Gültig bis" instead of
  "Fällig"); same creditor/client/lines/totals layout, money via
  `number_format(rappen/100, 2)`, dates `d.m.Y`.
- **`EstimateMail`** — subject `Offerte {number} - {profile.name}`, view
  `resources/views/emails/estimates/sent.blade.php`, attaches cached PDF as
  `Offerte-{number}.pdf`.

## Error handling

- Invalid state transitions (e.g. convert a non-accepted estimate, edit a sent
  estimate, send without client email) → `DomainException`, flashed to the user.
- PDF / mail failures → logged; send is transactional so a mail failure leaves the
  estimate in `draft`.
- All financial math is server-side; client-submitted amounts are never trusted.

## Testing

Mirror the invoice suite (Pest, `beforeEach` sets up `BusinessProfile`, `User`,
`Client`, `Project`; `browsershot` group for slow PDF tests):

- `tests/Feature/Http/EstimateControllerTest.php` — index/filter/search, create,
  store (validation + server-side recompute), show, update (draft-only),
  send/accept/decline/convert flows, redirect targets, Inertia component + data
  shape.
- `tests/Feature/Services/EstimateBuilderTest.php` — number allocation, line
  amount + totals recompute, VAT-exempt handling, profile defaults.
- `tests/Feature/Services/EstimateLifecycleTest.php` — state transitions,
  `decided_at` stamping, convert builds linked draft invoice + blocks double
  convert + rejects non-accepted.
- `tests/Feature/Services/EstimateNumbererTest.php` — sequential/atomic, prefix.
- `tests/Feature/Schema/EstimateStructureTest.php` — columns, FKs, cascade/null
  behavior, indexes.
- `tests/Feature/Mail/EstimateMailTest.php` — subject, attachment, recipient.
- `App\Support\LineTotals` covered via existing invoice tests + the new builder
  tests (and existing invoice tests must stay green after the extraction).
- Factories: `EstimateFactory` (+ `sent()`, `accepted()`, `declined()` states),
  `EstimateLineFactory`.

## Out of scope (YAGNI)

- Swiss QR-bill on estimates.
- Reminders / overdue / expiry scheduled jobs (`expired` is computed on read).
- Time-entry linking / pre-fill.
- Reverse link from an invoice back to its source estimate (link is one-way,
  estimate → invoice).
