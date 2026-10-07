# camt.053 import: bank entries + invoices marked paid

**Date:** 2026-10-07
**Status:** Implemented on branch `camt-import` (2026-10-07), not yet reviewed or merged
**Part of:** `docs/receipts-module.md` (build step 0, first slice). No Dropbox in this slice.

## Problem

Invoices are marked paid by hand, with `paid_at = now()` rather than the day the money
arrived. Nothing in ernte knows what actually happened on the bank account, so a forgotten
or wrongly attributed payment goes unnoticed, and the receipts module has no bank rows to
number files against.

ZKB eBanking exports the account as camt.053 XML, which the accountant now asks for every
quarter anyway. Importing those files gives ernte the bank's own record: it can mark
invoices paid on the real booking date and becomes the base table for the receipts module.

## What the real export looks like

Findings from the export of 2026-10-07 (63 files, 102 entries, Jan–Mar and Jul–Oct 2026):

- One file per day with movement, one `Stmt` per file, namespace `camt.053.001.04`.
- Every entry is a single transaction; there are no collective bookings.
- Every entry has a unique `Ntry/AcctSvcrRef`. `NtryRef` and the transaction-level
  references are missing or repeated and cannot serve as a key.
- **No credit carries a creditor reference.** ernte has no QR-IBAN configured, so QR bills
  go out with the plain IBAN and no reference. Payers write free text such as
  `Rechnung 2026-012`, `.2026-006 / Umsetzung …`, `#2026-007`, or nothing at all.
- Two invoices with the same total can be open at once (2026-004 and 2026-010, 648.60).
- Credits exist that belong to no ernte invoice: on 14 September a client paid 1,297.20
  for a Harvest invoice that had still gone out by mistake; Sam refunded it on
  30 September. The same amount came in again on 21 September for 2026-016.
- ZKB eBanking offers no camt.054 export. Downloading "all not yet exported files" marks
  them exported; the same files may not be offered again.

## Goals

- Upload many camt.053 files at once; store every entry exactly once, however often a file
  is re-uploaded.
- Match credits to invoices and mark those invoices paid on the booking date.
- Keep manual "mark paid" working; a later import links the bank entry and corrects the date.
- Show what was matched and how, what needs a decision, and let any match be undone.
- Warn when statements are missing between two imported days.

## Non-goals

- Dropbox, filing invoice PDFs, `NN_` prefixes on files (next slice).
- Receipts, debit matching, Viseca bills, merchant aliases (receipts module).
- Partial payments and instalments — never happened in ten years. A credit whose amount
  differs from the invoice total is a review case, not a partial payment.
- Fetching statements from the bank automatically (EBICS).
- MCP tools and the iOS app.

## Decisions

1. **Import stores, matching is a separate step.** Upload only writes rows. Matching runs
   once after the whole upload and can be re-run. This matters because the passes below
   must see all new entries together.
2. **The link lives on the bank entry** (`statement_lines.invoice_id`), not on the invoice
   as the brief sketched. Two credits can then point at one invoice (double payment).
3. **Auto-apply** (decided by Sam): reference + amount, invoice number in text + amount,
   or amount that fits exactly one invoice. Everything else is a proposal.
4. **`paid_at` is the booking date**, also for invoices already marked paid by hand.
5. **The bank position (`NN`) follows camt order** (decided by Sam): rank within the
   booking month ordered by booking date, statement sequence number, entry index in the
   file; every booked entry counts, credits included. Derived on read in this slice,
   shown in the UI, written nowhere.
6. **Only the configured account is accepted.** A file whose account IBAN differs from the
   business profile's IBAN is rejected, so a private account's export cannot be imported
   by mistake.

## Data model

Amounts are unsigned rappen, as on invoices; direction is a separate column. Decimal
strings from the XML are converted with string arithmetic, never through floats.

### New table: `statements`

| column                    | type            | notes                                         |
|---------------------------|-----------------|-----------------------------------------------|
| `id`                      | bigint pk       |                                               |
| `source`                  | string          | `zkb` (later `viseca`)                        |
| `account_iban`            | string          | normalised                                    |
| `message_id`              | string          | `GrpHdr/MsgId`                                |
| `statement_ref`           | string          | `Stmt/Id`; unique with `source`               |
| `sequence_number`         | unsigned int    | `ElctrncSeqNb`                                |
| `from_date`, `to_date`    | date            | `FrToDt`                                      |
| `opening_balance_rappen`  | bigint signed   | `Bal` of type `OPBD`                          |
| `closing_balance_rappen`  | bigint signed   | `Bal` of type `CLBD`                          |
| `original_filename`       | string          |                                               |
| timestamps                |                 |                                               |

### New table: `statement_lines`

| column                 | type                   | notes                                              |
|------------------------|------------------------|----------------------------------------------------|
| `id`                   | bigint pk              |                                                    |
| `statement_id`         | fk → statements        | cascade on delete                                  |
| `source`               | string                 | copied from the statement                          |
| `bank_ref`             | string                 | `Ntry/AcctSvcrRef`; unique with `source`           |
| `entry_index`          | unsigned int           | 0-based position inside the file                   |
| `booked_on`            | date                   | `BookgDt`                                          |
| `value_on`             | date nullable          | `ValDt`                                            |
| `is_credit`            | boolean                | `CdtDbtInd = CRDT`                                 |
| `amount_rappen`        | unsigned bigint        |                                                    |
| `currency`             | char(3)                | account currency                                   |
| `is_reversal`          | boolean                | `RvslInd`                                          |
| `is_fee`               | boolean                | bank transaction family `CHRG`                     |
| `bank_tx_code`         | string nullable        | sub-family code, e.g. `DMCT`, `AUTT`, `POSD`       |
| `description`          | text                   | `AddtlNtryInf`                                     |
| `remittance_text`      | text nullable          | all `Ustrd`, joined                                |
| `creditor_reference`   | string nullable        | `CdtrRefInf/Ref` (QRR or SCOR)                     |
| `counterparty_name`    | string nullable        | debtor for credits, creditor for debits            |
| `transactions`         | json nullable          | only when an entry holds more than one `TxDtls`    |
| `invoice_id`           | fk → invoices nullable | null on delete                                     |
| `match_state`          | string nullable        | credits only: `unmatched`, `proposed`, `matched`, `ignored` |
| `match_method`         | string nullable        | `qr_reference`, `number_in_text`, `amount`, `manual` |
| `match_note`           | string nullable        | e.g. `amount_mismatch`, `several_candidates`       |
| `marked_invoice_paid`  | boolean                | true if this match moved the invoice `sent → paid` |
| `auto_match_disabled`  | boolean                | set when a match was undone                        |
| `note`                 | text nullable          | Sam's free-text note, any entry (credit or debit)  |
| timestamps             |                        |                                                    |

Indexes: `booked_on`, `invoice_id`, `match_state`.

- `Statement hasMany StatementLine`; `StatementLine belongsTo Invoice`;
  `Invoice hasMany StatementLine` (`bankEntries`).
- For a proposal, `invoice_id` holds the best candidate and `match_state = proposed`.

### `invoice_events.kind`

Add `payment_matched` and `reopened` to the enum.

## Parsing

`App\Services\Banking\Camt053Parser` — pure: XML string in, one statement DTO with entry
DTOs out. No database access.

- Reads elements by local name and accepts both `camt.053.001.04` and `.08`. Swiss banks
  are retiring version 04 (as far as known, in November 2026), so the first `.08` file
  becomes a second fixture. Any other root namespace is rejected with a clear message.
- XML is loaded with network access disabled and no entity substitution.
- Entries with status other than `BOOK` are skipped.
- An entry with several `TxDtls` (a collective booking) is stored with its transactions in
  `transactions` and is never auto-matched. None exist today; see open questions.

## Import

`App\Services\Banking\StatementImporter::import(string $xml, string $filename)`:

1. Parse. Reject if the account IBAN is not the business profile's IBAN.
2. If a statement with the same `source` + `statement_ref` exists, skip the file.
3. Otherwise insert the statement and its lines in one transaction. A line whose
   `bank_ref` already exists is skipped.
4. Return counts: lines added, lines already known.

Credits start as `match_state = unmatched`; debits have `match_state = null`.

### Gap warning

Statements of one account are ordered by `to_date`, then `sequence_number`. Where one
statement's closing balance differs from the next one's opening balance, the page shows
"statements missing between <date> and <date>". Days without movement produce no file, so
the balances are the only reliable check.

## Matching

`App\Services\Banking\InvoicePaymentMatcher::run()` — idempotent, runs after an upload and
from a "Run matching" button.

**Lines considered:** credits, not reversals, single transaction, in state `unmatched` or
`proposed`. Existing proposals are cleared first and recomputed. Lines in `matched` or
`ignored` are never touched.

**Candidate invoices for a line:** status `sent` or `paid`, no `matched` line linked yet.
For the amount pass (3) two more conditions apply: `issued_on` is on or before the line's
`booked_on`, and an invoice already marked paid by hand only counts if its `paid_at` lies
within 14 days of the booking date. Without that window every old invoice with a recurring
amount would compete for each new payment of the same amount.

Lines with `auto_match_disabled` are skipped (see undoing a match).

Each pass runs over all remaining lines before the next pass starts:

1. **QR reference.** `creditor_reference` equals an invoice's `qr_reference`.
   Amount equal → auto. Amount differs → proposal, note `amount_mismatch`.
2. **Invoice number in the text.** An invoice number (e.g. `2026-012`) appears in
   `remittance_text`, not embedded in a longer run of digits. Amount equal → auto.
   Amount differs → proposal, note `amount_mismatch`.
3. **Amount.** The line's amount equals the total of exactly one remaining candidate, and
   no other remaining line has that same single candidate → auto. Several candidates, or
   several lines competing for one invoice → proposal, note `several_candidates`.

Lines left over stay `unmatched`.

With the Q3 2026 export this resolves the same-amount pair: pass 2 gives the 10 August
credit to 2026-004 by its text, which leaves 2026-010 as the only candidate for the
4 August credit in pass 3.

### Applying a match (auto, confirmed proposal, or manual)

In one transaction:

- Line: `invoice_id`, `match_state = matched`, `match_method`.
- Invoice `sent` → `InvoiceLifecycle::markPaid($invoice, $bookedOn)`; the line gets
  `marked_invoice_paid = true`. The `paid` event payload records the line id and method.
- Invoice already `paid` → `paid_at` is set to the booking date if it differs; a
  `payment_matched` event records the previous `paid_at`.

`markPaid()` gains an optional date parameter; the manual button keeps passing nothing.
`paid_at` is stored as noon of the booking day, so the calendar day reads the same in
any timezone. A proposal confirmed by Sam is recorded with method `manual`.

### Undoing a match

Unmatching sets the line back to `unmatched` and clears the link. If
`marked_invoice_paid` is true, the invoice goes back to `sent` with `paid_at = null` via a
new `InvoiceLifecycle::reopen()` (`reopened` event). An invoice that Sam had marked paid by
hand stays paid; its corrected `paid_at` is not restored. The line gets
`auto_match_disabled = true`, otherwise the next matching run would repeat the match that
was just rejected; it can still be matched by hand.

The mistaken Harvest payment of 14 September is handled without a special case: 2026-016
was issued on 15 September, so it is not a candidate for that credit. The credit stays
`unmatched` for Sam to ignore, and the 21 September credit matches 2026-016 by amount.

## UI

New page **Bank** (`/bank`, `Bank/Index.vue`) in the main navigation, built from the same
list components as the invoices index.

- **Upload.** File picker and drop zone for `.xml`, multiple files. The browser posts them
  in batches of ten (PHP's default upload limit is 20 files per request, and a quarter is
  60+ files), then triggers matching once. Result line: files read, entries added,
  entries already known, invoices marked paid, entries needing review. Rejected files are
  listed with the reason.
- **Needs review.** Proposals and unmatched credits: date, amount, payer, text, and the
  candidate invoice(s). Actions: confirm, pick another invoice (any `sent` or unlinked
  `paid` invoice), ignore ("not an invoice payment"). Ignored lines can be restored.
- **Entries by month.** All entries of the month with their position, date, description,
  amount, and for credits the linked invoice and match method, with an undo action.
  Every entry has an editable note (e.g. "paid Harvest invoice 364 by mistake, refunded
  30.09."), shown inline — this is how a credit and its refund are tied together for the
  accountant.
  Gap warnings appear at the top of the affected month.

Invoice detail page: where the invoice has a linked entry, the paid line reads
"Paid <date> · bank entry <bank_ref> · matched by <method>" and links to the Bank page.

### Routes

| method | path                                   | purpose                              |
|--------|----------------------------------------|--------------------------------------|
| GET    | `/bank`                                | page                                 |
| POST   | `/bank/statements`                     | upload one batch of files            |
| POST   | `/bank/match`                          | run the matcher                      |
| POST   | `/bank/lines/{line}/match`             | confirm proposal or set invoice by hand |
| POST   | `/bank/lines/{line}/unmatch`           | undo                                 |
| POST   | `/bank/lines/{line}/ignore`            | ignore / restore                     |
| PATCH  | `/bank/lines/{line}/note`              | save the note                        |

## Testing

- **Fixtures:** `tests/Fixtures/camt/`, derived from the real export with names,
  addresses, IBANs, references and free text replaced. The real files are never committed
  (the export folder is excluded locally via `.git/info/exclude`).
- **Parser:** credit with free text, debit with QR reference, card purchase without
  transaction details, fee, standing order; amount conversion (`3891.6` → 389160);
  wrong namespace rejected; a hand-built `.08` variant and a multi-`TxDtls` entry.
- **Importer:** same file twice adds nothing; overlapping lines skipped; foreign IBAN
  rejected; gap detected from balances.
- **Matcher:** each pass; amount mismatch; same-amount pair resolved by pass order; two
  credits competing for one invoice stay proposals; invoice issued after the booking date
  is not a candidate; voided and draft invoices never match; re-run changes nothing.
- **Lifecycle:** `markPaid` with a date; already-paid invoice gets its date corrected;
  unmatch reopens only when the import had marked it paid.
- **Feature:** upload endpoint, review actions, Inertia page props. The `Bank/Index` page
  must be in the Vite manifest for the feature tests to pass.

## Acceptance

On a copy of production data, upload the Q3 2026 export. Every credit is either matched to
the invoice Sam expects, or listed under "needs review"; no invoice is marked paid
wrongly; re-uploading the same files changes nothing.

## Rollout

- Additive migrations; Forge deploys on push to `main`.
- Sam enters the QR-IBAN in Settings on production. New invoices then carry a QR reference
  and pass 1 starts to apply. Invoices already sent keep their old QR bill.
- The quarterly zip export of paid invoices stays until the Dropbox slice replaces it.

## Open questions

- **How does ZKB book QR-IBAN credits?** If they arrive as one collective credit per day,
  the individual references are in several `TxDtls` of one entry (there is no camt.054 to
  fall back on). The parser already stores those; matching per transaction would be added
  once a real example exists. Check the first QR payment after the QR-IBAN is live.
- **April–June 2026 and 1–27 January 2026** are not in the export. Not needed for
  Q3 2026, but the gap warning will flag the hole between March and July.

## Next slice (not in this spec)

Dropbox connection, then filing each matched invoice PDF once as
`NN_Diluno-GmbH-Rechnung-<nr>.pdf` in the booking month's folder. That slice must freeze
the position when a file is written and only do so for months whose statements are
complete (unbroken balance chain across the whole month).
