# Numbering receipts: card bill import, matching, `NN_` prefixes

**Date:** 2026-10-07
**Status:** Parts 1–3 implemented on branch `camt-import` (2026-10-07), tested against a faked Dropbox only — no real file has been numbered, copied or uploaded yet. Differences from this spec: no `merchant_aliases` table (names match on shared words), no separate "Freeze numbers" button (numbers freeze with the first file), a standing document is stored by its path relative to the Receipts root rather than a file ID, a standing copy cannot be undone by ernte (it never deletes), and standing documents are added in Settings rather than seeded
**Part of:** `docs/receipts-module.md` (build steps 2 and 3). Builds on the camt.053 import
(`statements`, `statement_lines`) and on receipt upload (`receipts`, `DropboxClient`).

## Problem

Receipts now reach their month folder unnumbered, and ernte knows every bank entry. What
the accountant needs is the link between the two: each file prefixed with the number of
the row that paid it, card receipts in `Kreditkarte/`, and a view of what is still missing.
Today `sorter.py` and `ccmatch.py` do this on the synced folder from PDF statements.

## What the existing folders show

Looked at 2026_Q1/02 and 2026_Q2/05 in Dropbox (2026-10-07):

- Month folder: `NN_<name>.pdf` for bank rows; gaps in the numbers are rows without a
  receipt (fees). The Viseca bill is numbered like any receipt (`12_Bill - May 2026.pdf`).
- Outgoing invoices are in the same sequence (`07_Diluno-GmbH-Rechnung-357.pdf`).
- `Kreditkarte/NN_<name>.pdf` is a separate sequence per bill.
- **The rent reuses one document every month:** `NN_mietvertrag-buero.pdf`, a copy of the
  original in the Receipts root. Sam confirmed (2026-10-07) this is the only standing
  document; `steuern.pdf` is not one.
- Q3 2026 is entirely unnumbered (49 files), so it is the natural first quarter for ernte.

## Goals

- Import the Viseca CSV export as the card bill.
- Give every bank row and card row its number, by the rules Sam decided.
- Propose which receipt belongs to which row; apply confident matches on Sam's click.
- Applying a match renames or moves the file in Dropbox to its numbered place.
- Put recurring documents and paid ernte invoices into the sequence as numbered copies.
- Show per month what is matched, proposed and missing.

## Non-goals

- One receipt paid in several instalments (several rows for one file).
- Filing the bank statement PDF or the Viseca bill PDF themselves; Sam keeps adding those.
- Numbering months before Q3 2026. Old months keep the scripts' numbers; ernte only reads them.
- Unattended writes to Dropbox: numbering happens when Sam presses the button.

## Decisions

1. **Bank `NN`** = position within the booking month in camt order, every booked entry
   counted (decided 2026-10-07).
2. **Card `NN`** = position within the bill by transaction date and time, oldest first,
   then `TransactionId`; negative rows (payment, refunds) are not counted (decided
   2026-10-07).
3. **Bills are derived from the bank debits, not from the export.** Sam's second export
   (28 June – 20 September 2026) held three bills and repeated the first export entirely,
   so "one export is one bill" does not hold. In date order, consecutive runs of card
   rows add up exactly to the three Viseca direct debits (1,043.50 · 912.65 · 2,572.15,
   nothing left over; a −42.50 "Bonus" credit counts towards its bill's sum). An export
   may therefore cover any period and overlap earlier ones. A bill belongs to the month
   of its direct debit.
4. **Numbers are frozen** in `statement_lines.position` the first time a month or bill is
   used for numbering, and only for complete months (see below). They never change after.
5. **ernte numbers one file at a time**, each as a single Dropbox move, state saved only
   after Dropbox confirms. The brief asked for "all of a month or nothing"; that does not
   fit receipts arriving over weeks, and a half-numbered month is how the folder looks
   during any month today.
6. **Sam triggers the writes.** The matcher runs automatically and proposes; "Number
   confident matches" per month applies them. A setting can later make this automatic.
7. **Card receipts match on the original amount and currency**, which the CSV carries
   exactly. The CHF column is rounded to 5 rappen and is only a fallback.
8. **The scripts stop for months ernte numbers.** Both would otherwise number the same
   files. Files the scripts already numbered are recognised by their prefix and linked,
   never renamed.

## Shippable parts

- **Part 1 — Card bill import and the month view.** Read-only: positions, proposals,
  missing receipts. Writes nothing to Dropbox.
- **Part 2 — Numbering receipts.** Apply and undo matches in Dropbox.
- **Part 3 — Numbered copies.** Recurring documents and paid ernte invoices.

## Data model

### `statements` (additions)

| column           | type                          | notes                                              |
|------------------|-------------------------------|----------------------------------------------------|
| `bank_line_id`   | fk → statement_lines nullable | Viseca only: the direct debit that paid this bill  |
| `charges_rappen` | bigint nullable               | Viseca only: the bill total (charges minus credits) |

For Viseca there is one **pool** statement holding card rows not yet assigned to a bill,
and one statement per bill: `statement_ref` = the direct debit's bank reference,
`from_date` / `to_date` = first and last transaction date.

### `statement_lines` (additions)

| column                  | type                    | notes                                             |
|-------------------------|-------------------------|---------------------------------------------------|
| `position`              | unsigned int nullable   | the frozen `NN`; null until frozen or not counted |
| `transacted_at`         | datetime nullable       | Viseca `Date` (with time)                         |
| `original_amount_minor` | bigint nullable         | Viseca `OriginalAmount` in minor units            |
| `original_currency`     | char(3) nullable        | Viseca `OriginalCurrency`                         |
| `no_receipt`            | boolean                 | Sam marked the row as needing no receipt          |

Viseca rows: `bank_ref` = `TransactionId`, `booked_on` = date part of `Date`,
`value_on` = `ValutaDate`, `amount_rappen` = |`Amount`|, `is_credit` = negative row,
`description` = `Details`, `counterparty_name` = `MerchantName` or else `Details`.
`is_fee` is **not** taken from the CSV's `Type` column (a 1,418.00 Apple purchase is
typed `fee`).

### `receipts` (additions)

| column              | type                          | notes                                                  |
|---------------------|-------------------------------|--------------------------------------------------------|
| `statement_line_id` | fk → statement_lines nullable | the row that paid it; several receipts may share a row |
| `match_state`       | string nullable               | `proposed` · `matched`                                 |
| `match_method`      | string nullable               | see passes; `manual`; `existing_prefix`                |
| `match_note`        | string nullable               | e.g. `several_rows`, `amount_differs`                  |
| `numbered_at`       | datetime nullable             | when ernte wrote the prefix                            |
| `auto_match_disabled` | boolean                     | set when Sam undoes a match                            |

### New table: `standing_documents` (Part 3)

| column            | type            | notes                                                      |
|-------------------|-----------------|------------------------------------------------------------|
| `id`              | bigint pk       |                                                            |
| `label`           | string          | "Office rent", "Salary", "Taxes"                           |
| `row_keyword`     | string          | matched against a bank row's description, payer and text   |
| `dropbox_file_id` | string          | the document to copy, anywhere under the Receipts root     |
| `filename`        | string          | name of the copy without prefix, e.g. `mietvertrag-buero.pdf` |
| `active`          | boolean         |                                                            |

### New table: `merchant_aliases`

`row_keyword`, `receipt_keyword`. Says that a row containing the first belongs to a
receipt whose vendor or filename contains the second (e.g. `MOL*PLOI` ↔ `ploi`). Seeded
from the keyword lists in `sorter.py` and `ccmatch.py`; a confirmed manual match offers to
save a new alias. With exact original amounts from the CSV this table matters much less
than in the scripts.

### `invoices` (addition, Part 3)

`dropbox_file_id` nullable — the numbered PDF copy, so it is never filed twice.

## Part 1 — Card bill import and the month view

### Import

The Bank page's upload accepts `.csv` next to the camt `.xml` files.

1. Parse (UTF-8 with BOM, comma-separated, header row). Required columns: `TransactionId`,
   `Date`, `Amount`, `Currency`, `OriginalAmount`, `OriginalCurrency`, `Details`. Only
   `StateType = BOOKED` rows are taken. Any other shape is rejected with a clear message.
2. Rows whose `TransactionId` is already known are skipped; new ones go into the pool.
   Payment rows (`Ihre Zahlung - Danke`) are stored but never belong to a bill.
3. **Assign bills** (also after every camt import). For each ZKB debit to "Viseca" that has
   no bill yet, oldest first: among the pool rows dated up to that debit, in date order,
   find the run ending latest whose amounts (credits negative) sum exactly to the debit.
   Found → those rows become that bill. Not found → the debit shows "card bill not
   imported" and the rows stay in the pool as "not yet billed".
4. A bill can be dissolved back into the pool by hand, should an assignment look wrong.

Checked on the real exports: 15 rows → 1,043.50 (29 July), 20 rows → 912.65 (27 August),
18 rows → 2,572.15 (29 September).

### Completeness and freezing positions

- **A bank month is complete** when a statement dated after the month's last day has been
  imported and no balance gap falls inside the month. Until then its rows show provisional
  numbers and cannot be used for numbering.
- **A card bill is complete** by construction: it only exists once its rows add up to a
  bank debit.
- Positions are written when Sam first numbers something in that month or bill, or presses
  "Freeze numbers". A later import that would alter a frozen month's order is refused with
  an explanation instead of renumbering.

### Month view

The Bank page's month section becomes the reconciliation view:

- Bank rows with number, and for each debit: its receipt, a proposal to confirm, "missing",
  "bank fee" or "no receipt needed" (toggle per row).
- Under the month, each card bill tied to it: "Kreditkarte · bill of 29 Sep · 18 rows",
  with the same columns and the original amount beside the CHF amount.
- Receipts parked in the month that match no row.
- Month status: complete / N missing. A quarter line sums its three months.

## Matching receipts to rows

`App\Services\Receipts\ReceiptRowMatcher::run()` — idempotent, runs after any statement
import, after a receipt is read or edited, and on demand. It only writes to ernte's
database.

**Receipts considered:** read, not a duplicate, no confirmed match, not `auto_match_disabled`.
**Rows considered:** bank debits that are not fees, reversals or `no_receipt`, and card
charges; each without a confirmed receipt.

A row's amount is its original amount and currency for card rows, CHF for bank rows.
"Names agree" means the receipt's vendor or filename shares a significant word with the
row's payer or description, or a merchant alias connects them.
A receipt is only a candidate for rows dated from 10 days before to 75 days after its date.

Passes, each over everything still open before the next starts:

1. **Existing prefix.** The receipt's file already carries `NN_` (numbered by the scripts
   or by hand): linked to row `NN` of its folder's month or bill. Never renamed.
2. **Payment reference.** A bank debit's QR or creditor reference appears in the
   receipt's text layer, and the amount equals the receipt's total or one of its amounts.
3. **Total and name.** Row amount equals the receipt's stated total in the same currency,
   and names agree.
4. **Total alone.** Row amount equals the stated total, and it is the only open row and
   the only open receipt with that amount in the window.
5. **Any amount and name.** Row amount equals one of the other amounts on the receipt,
   and names agree (QR-slip figures, card charges that differ from the invoice total).

Outcomes:

- Exactly one row for a receipt and one receipt for that row → `proposed`, marked
  **confident** for passes 1–4, plain for pass 5.
- **Recurring same amounts** (ploi at 13.00 EUR each month): when several receipts and
  several rows of one merchant share an amount, they are paired in date order instead of
  being called ambiguous.
- Anything else with candidates → `proposed` with note `several_rows`, the nearest in date
  first. No candidates → left open.
- Card rows in CHF that fail on the original amount are retried on the CHF amount with a
  tolerance of 0.05.
- Debit-card purchases in a foreign currency only show CHF in the camt file; they can
  match by name and date only, as a plain proposal with note `amount_differs`.

Confirming a proposal by hand records method `manual`. Undoing sets
`auto_match_disabled`, as with invoice payments.

## Part 2 — Numbering

"Number confident matches" on a month (or "Number" on one row) does, per receipt:

1. Preconditions: the receipt is filed in Dropbox; the row's month or bill is complete;
   positions are frozen (frozen now if not yet).
2. Look the file up by ID (`ReceiptFiler::refresh`). If it already has a prefix: it must
   equal the row's number, then only the link is stored; a different number is a conflict
   and nothing is written.
3. Target:
   - bank row → `<root>/<YYYY>_Q<N>/<MM>/NN_<name>.pdf`, month of the row's booking date;
   - card row → `<root>/<YYYY>_Q<N>/<MM>/Kreditkarte/NN_<name>.pdf`, month of the bill's
     bank debit; `Kreditkarte` is created if missing.
4. `DropboxClient::move` by ID, `autorename=false`. A name clash is reported and that
   receipt is skipped; the others continue.
5. On success: `match_state = matched`, `numbered_at`, new `filename`, `dropbox_path`,
   and `target_year/month` updated to where the file now is.

`NN` is zero-padded to two digits, three from 100.

**Undo** moves the file back to its parking month folder without the prefix and clears
the link. If the file was moved or renamed by someone else in the meantime, undo only
clears the link and says so.

The result of a run is listed: numbered, skipped with reason. Nothing is deleted or
overwritten at any point.

## Part 3 — Numbered copies

### Standing documents

A small list in Settings: label, keyword, document (chosen from files under the Receipts
root), filename. Seeded with the one Sam confirmed: office rent → `mietvertrag-buero.pdf`
in the root. Others can be added in Settings.

In the month view, a debit whose text contains a keyword and has no receipt shows
"copy <label>". Numbering it copies the document to `NN_<filename>` in the row's month
(`files/copy_v2`, added to `DropboxClient` with the same confinement and no overwrite).
The copy is recorded as a receipt with `source = standing`, so undo and the missing count
work the same. Standing copies are exempt from duplicate detection.

### Paid ernte invoices

A credit matched to an invoice (camt import) and not yet filed: numbering uploads the
invoice PDF as `NN_<Invoice::pdfFilename()>` into the credit's booking month and stores
`invoices.dropbox_file_id`. A second credit for the same invoice files nothing. The
quarterly "Paid PDFs" zip download stays until Sam no longer needs it.

## Testing

- **CSV import:** the September shape; BOM; negative payment row not counted; partial
  overlap rejected; re-import no-op; missing column rejected; bank debit found, not found,
  ambiguous. Fixture is synthetic, modelled on the real export.
- **Positions:** bank month and card bill ordering; equal timestamps broken by
  `TransactionId`; negative rows skipped; frozen numbers survive a later import;
  incomplete month refuses to freeze.
- **Matcher:** each pass; recurring same-amount pairing by date; 5-rappen fallback;
  window; alias; `no_receipt` and fee rows excluded; several receipts on one row by hand;
  idempotent re-run; undo is respected.
- **Numbering** (`Http::fake`): bank and card targets; `Kreditkarte` created; clash
  skipped and others continue; existing equal prefix linked without a move; differing
  prefix refused; undo moves back; file moved elsewhere → undo clears the link only.
- **Copies:** standing document copied once per row; invoice PDF uploaded once; nothing
  overwritten.
- **Feature:** month view props, status counts, actions.

## Acceptance

On a copy of production data with Q3 2026 imported (camt and the three Viseca exports) and
the 49 existing files adopted: Sam reviews the proposals month by month before the first
"Number" click. For July, the proposals are compared by hand against what the scripts
would have produced; only then is a real month numbered.

The brief's "score against two already-numbered quarters" is not possible as written:
camt files exist only from 28 January 2026 with April–June missing, old numbers followed
the PDF rows, and there are no historic Viseca exports.

## Rollout

1. Export the Viseca CSV for the bills paid in July, August and September 2026.
2. Deploy Part 1; import; check the month view against the folders.
3. Deploy Part 2; number July first, look at the folder, then August and September.
4. Stop running `sorter.py` and `ccmatch.py` from Q3 2026 on.
5. Part 3 afterwards; until then rent, salary, tax copies and invoice PDFs are placed by
   hand as today, and ernte links them through their prefix.

## Open questions

- **Which rows never have a receipt** besides bank fees: not known (Sam, 2026-10-07).
  Rows are marked "no receipt needed" one by one; a keyword list can follow once a
  pattern shows.
- ~~Two receipts for one row~~ — both get the same `NN_` prefix; rare (Sam, 2026-10-07).
- **Credit notes for card refunds:** not known (Sam, 2026-10-07). Until the accountant
  says otherwise, a credit note can be linked to its credit row in ernte for the record,
  but the file is left unnumbered where it is.
- **April–June 2026 camt files** were already marked exported at ZKB. Not needed for Q3,
  but without them Q2 can never be shown complete in ernte.
