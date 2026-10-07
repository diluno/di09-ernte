# Receipts module — feature brief

Status: idea, not started. Written 2026-10-07.

## Goal

Upload a receipt the moment it arrives (phone, desktop, forwarded email). ernte reads it,
files it into the Dropbox bookkeeping folder, and — once the month's bank statement and
credit-card bill exist — numbers it against the right statement line. Per month, show what
is still missing.

The Dropbox folder stays the source of truth for files. It is shared with the accountant,
so its layout and naming must come out exactly as they are today.

## The filing convention the module must reproduce

```
Diluno/Receipts/<YYYY>_Q<N>/<MM>/
    _<YYMMDD>_Kontoauszug….pdf        one ZKB statement per month, leading underscore
    NN_<original name>.pdf            receipt paid from the bank account
    Bill - <Month> <Year>.pdf         Viseca credit-card bill (itself a bank-paid receipt)
    Kreditkarte/NN_<original name>.pdf receipt paid by credit card
```

- Bank `NN` = row number on the ZKB statement, counting every booked row in order,
  credits included; `Saldovortrag`, `Additionen`, `Schlusssaldo` are not rows.
- `Kreditkarte/` `NN` = row number on the Viseca bill, an independent sequence;
  negative rows (refunds, `Ihre Zahlung`) are not counted.
- Fee rows (`Gebühr`, `Kontoführung`) legitimately have no receipt.
- Original filenames are kept; only the prefix is added.

## Why sorting happens in two phases

The `NN_` prefix cannot be known at upload time: the statement does not exist until
month-end. So a receipt has a lifecycle:

`uploaded → extracted → filed (unnumbered, in month folder) → matched → numbered`

plus `ambiguous` and `needs review` as side states.

## Features

### 1. Capture
- Upload page: drag-and-drop, multi-file, and a phone-friendly camera/file input.
- PDF and images (JPEG/PNG/HEIC). Images are converted to PDF before filing, because the
  folder only contains PDFs.
- Later: a forwarding address (Postmark inbound) that takes the PDF attachment of a
  forwarded invoice email.
- Duplicate detection by content hash; a duplicate is rejected with a link to the original.

### 2. Extraction (on upload, queued job)
- Text layer first (`pdftotext -layout`), then Claude via the existing `anthropic-ai/sdk`
  for structured fields. Scans and photos with no text layer go to Claude as a document/image.
- Fields: vendor, document date, stated total, currency, all other amounts found,
  invoice number, payment method guess (bank / credit card / unknown), confidence.
- Keep both "stated total" and "every amount in the document" — matching needs both
  (see matching rules).
- User can correct any field on the receipt detail page.

### 3. Filing to Dropbox
- Filed unnumbered into the month folder of the receipt's **own date** (upload date if no
  date could be read) — a holding place. Editable.
- The final home is fixed by the numbering, not by the document date:
  - bank receipt → the month whose ZKB statement carries the debit;
  - credit-card receipt → `Kreditkarte/` in the month folder holding the Viseca bill that
    lists it (decided 2026-10-07).
- So matching moves the file if it was parked in a different month. When a statement or
  bill is imported, the matcher considers unnumbered receipts from that month and the two
  before it, not just that month's folder.
- Store the Dropbox **file ID** (`id:…`), not the path — it survives renames and manual
  moves by Sam or the accountant.
- Never overwrite, never delete: uploads use `mode=add, autorename=false` and fail loudly
  on a name collision.

### 4. Statement and bill import
- Uploading a `Kontoauszug` or a Viseca `Bill - …pdf` parses it into rows
  (`statement_lines`) and files it under its convention name.
- ZKB row: date, description + continuation line, booked CHF amount, optional
  foreign-currency original (`USD 143.90 abgerechnet in CHF`), fee flag.
- Viseca row: transaction date, merchant details, optional original currency amount,
  CHF amount. The bill's own booked amount is `Total Rechnungsbetrag zu unseren Gunsten`
  only — not `Totalbetrag letzte Abrechnung` (previous month) and not any line item.

### 5. Matching
Runs when a statement/bill is imported and again whenever a receipt is added or edited.
Proposes matches; applies only confident ones automatically, shows the rest for one-click
confirmation.

Pass order, strongest first (ported from `sorter.py` / `ccmatch.py`):
1. Known merchant + stated total (CHF booked amount or foreign-currency original).
2. Known merchant + any amount in the document.
3. Recurring payees identified by filename/type (rent, SVA, salary, VAT, taxes).
4. Known merchant alone, if exactly one open row.
5. Stated total equals exactly one open row (CHF, then FX original).
6. Any amount equals exactly one open row — last resort, covers instalments and QR-slip
   figures that differ from the invoice total.

Rules that must hold:
- Amount tolerance ±0.02.
- More than one candidate row → `ambiguous`, never auto-applied.
- Same-amount recurring vendors (ploi €13.00/month) are matched by **date**, not by first
  open row; otherwise each one lands a month late.
- Identical duplicate charges on one bill take the lowest open row, the next receipt the next.
- An FX-total match may not claim a row already fingerprinted to another known merchant
  (Netlify vs. Craft CMS amounts collide).
- `Einkauf` / `Online-Einkauf ZKB Visa Debit Card` rows are **bank** rows, not credit card.
- Amount parsing handles `1'297.20`, the typographic apostrophe `8’993.90` (U+2019), and
  comma decimals `€13,00`.
- Every match records how it was made (`merchant+total`, `total-fx`, `manual`, …).

Merchant aliases (statement text ↔ receipt text ↔ filename pattern) live in a table with a
small admin screen, seeded from the lists in the two scripts. Confirming a manual match
offers to save a new alias.

### 6. Outgoing invoices (decided 2026-10-07)
Spec for the first slice: `docs/superpowers/specs/2026-10-07-camt-import-invoice-payments-design.md`
(it supersedes the matching rules below where they differ — the real export showed that no
credit carries a QR reference yet, and the link moved to `statement_lines.invoice_id`).

Driven by the camt.053 import, not by marking an invoice paid. The accountant now asks for
the camt.053 each quarter anyway. Starts with Q3 2026, so no Harvest-era invoices.

- Bank rows for ZKB come from an uploaded camt.053 file; every entry is stored in
  `statement_lines`, keyed by the bank's entry reference so overlapping re-imports are harmless.
- A credit whose QR reference equals an invoice's `qr_reference` **and** whose amount equals
  the invoice total marks the invoice paid automatically, with `paid_at` = booking date.
- Reference matches but amount differs → "needs review", invoice stays `sent`. No partial
  payment model: instalments have never happened.
- No QR reference on the credit → fall back to amount + exactly one open invoice, as a
  proposal only, never auto-applied.
- Marking paid by hand stays (Sam does not upload camt files continuously). A later import
  links the already-paid invoice to its bank entry and corrects `paid_at` to the booking date.
- Filing: the folder convention is kept. The invoice PDF is written to the booking month's
  folder as `NN_Diluno-GmbH-Rechnung-<nr>.pdf` at import time, when the row is known — it is
  written once, already numbered. Marking paid by hand does not file anything.

### 7. Numbering
- Applying a match renames the file in Dropbox to `NN_<name>`, or moves it into
  `Kreditkarte/NN_<name>`.
- Collision and duplicate-target check across the whole month before any write; on
  conflict nothing in that month is renamed.
- Undo: un-matching strips the prefix and moves the file back.

### 8. Month reconciliation view
One screen per month, the main working view:
- Statement rows with their receipt, or "missing" (fee rows marked as not needed).
- Viseca bill rows likewise.
- Receipts with no row yet; ambiguous receipts with their candidate rows.
- Month status: complete / N missing. Quarter overview listing all three months.

### 9. Optional
- Weekly email digest of missing receipts.
- MCP tools on the existing ernte MCP server (`list_unmatched_receipts`, `month_status`).
- Import existing months from Dropbox so history is browsable.

## Viseca CSV export (found 2026-10-07)

Viseca offers a CSV export of card transactions, which replaces parsing the bill PDF.
Seen in the September 2026 export (19 rows):

- Columns: `TransactionId, CardId, Date, ValutaDate, Amount, Currency, OriginalAmount,
  OriginalCurrency, MerchantName, MerchantPlace, MerchantCountry, StateType, Details, Type,
  Exchange Rate`. UTF-8 with BOM, comma-separated.
- `TransactionId` is unique → safe key for re-imports.
- `OriginalAmount` + `OriginalCurrency` is the amount on the receipt, exact. `Amount` is
  CHF rounded to 5 rappen (81.08 → 81.10), so CHF receipts must be matched on the original.
- `MerchantName` is sometimes empty; `Details` always carries the raw descriptor.
- The previous bill's payment is a negative row (`Ihre Zahlung - Danke`, no `CardId`).
- The sum of the positive rows (2,572.15) equals the Viseca direct debit in the camt.053
  (29 September), which ties a bill to its bank entry and so to its month folder.
- `Type` is not reliable: a 1,418.00 Apple purchase is typed `fee`.
- There are no row numbers and the file is not in one chronological order, so the
  `Kreditkarte/` `NN` needs an explicit ordering rule (open).

## Dropbox integration

- Scoped Dropbox app with **Full Dropbox** access (the Receipts folder is shared with the
  accountant and stays where it is). Scopes: `files.metadata.read`, `files.content.read`,
  `files.content.write` — nothing else.
- OAuth code flow with `token_access_type=offline`; store the refresh token encrypted.
  Access tokens last a few hours, so the client refreshes them itself.
  `spatie/flysystem-dropbox` works as a Laravel disk if given a token provider that does this.
- The code confines itself to a configured root (`DROPBOX_RECEIPTS_ROOT=/Diluno/Receipts`)
  and refuses any path outside it — the token can reach everything, the app must not.
- All Dropbox writes go through queued jobs with retry; a failed write leaves the receipt
  in ernte marked "not filed", never half-filed.

## Data model (sketch)

- `receipts` — dropbox_file_id, original_name, content_hash, vendor, document_date,
  total, currency, amounts (json), payment_method, target_year/month, status,
  statement_line_id, match_method, extraction (json).
- `statements` — type (`zkb` / `viseca`), year, month, dropbox_file_id, booked_total.
- `statement_lines` — statement_id, position (the `NN`), date, description, amount_chf,
  amount_fx, currency_fx, is_fee, is_credit.
- `merchant_aliases` — statement_keyword, receipt_keyword, filename_keyword, source (`zkb` / `viseca`).
- `invoices` gets a nullable `statement_line_id`.

## Build order

0. camt.053 import → `statement_lines` → invoices marked paid (no Dropbox). Then Dropbox
   connection + filing of matched invoice PDFs.
1. Dropbox connection + upload + extraction + unnumbered filing. Useful on its own;
   spec: `docs/superpowers/specs/2026-10-07-receipt-upload-design.md`.
   `sorter.py` and `ccmatch.py` keep doing the numbering on the synced folder.
2. Statement/bill import and the reconciliation view (read-only matching proposals).
   Spec for steps 2–3: `docs/superpowers/specs/2026-10-07-receipt-numbering-design.md`.
3. Applying matches (rename/move in Dropbox), outgoing invoices.
4. Email forwarding, digest, MCP tools.

## Acceptance check for the matcher

Import already-sorted historical months (files with their existing `NN_` prefixes) as
fixtures and score the matcher's proposals against them, as `sorter.py --verify` does
(43/43 on Q1 2026). No auto-apply in production until it scores the same on at least two
full quarters.

## Reference

- `Diluno/Receipts/sorter.py` — ZKB statement parser and bank matching passes.
- `Diluno/Receipts/ccmatch.py` — Viseca bill parser and credit-card matching.
- Both scripts hold the current merchant keyword lists.

## Open questions

- ~~Park first, move on match?~~ Yes — moving a file to another month folder when it is
  matched is fine (Sam, 2026-10-07).
- Stray files directly in the quarter folder (not in a month) — should the module pick
  those up?
- ~~Personal Dropbox or team space?~~ Personal (2026-10-07) — no `Dropbox-API-Path-Root` header.
- Does ZKB's camt.053 list each QR payment as its own entry with its reference, or as a
  collective credit with details only in camt.054? Check on a real Q3 2026 export.
- ~~Does camt entry order equal the PDF row order?~~ Not checked by design (2026-10-07):
  bank `NN` is the camt.053 entry position within the booking month, every entry counted,
  credits included. The PDF statement is no longer the reference for numbering.
- Does the accountant ever rename or move files? If so the module should re-read file
  metadata by ID before every write.
