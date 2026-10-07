# Receipt upload: Dropbox connection, extraction, unnumbered filing

**Date:** 2026-10-07
**Status:** Parts A, B and C implemented on branch `camt-import` (2026-10-07). Dropbox connected locally and `_Inbox` created; Claude reading verified live with a synthetic receipt; no real receipt filed or moved yet; pages not checked in a browser
**Part of:** `docs/receipts-module.md` (build step 1). Builds on nothing from the camt
import except the app shell; the two can ship in either order.

## Problem

Receipts reach the bookkeeping folder by hand: download or photograph, rename, drag into
`Diluno/Receipts/<YYYY>_Q<N>/<MM>/` in Dropbox. It is easy to forget one, and nothing
records what a receipt is until the scripts try to match it at month-end.

This slice lets Sam hand a receipt to ernte the moment it arrives. ernte reads it, files
it into the right month folder in Dropbox without a number, and keeps a record. Numbering
against bank entries is the following slice; until then `sorter.py` and `ccmatch.py` keep
numbering the files in the synced folder exactly as today.

## Goals

- Connect ernte to Sam's personal Dropbox once, and keep the connection alive.
- Upload PDFs and photos from desktop or phone, several at a time.
- Read vendor, date, total, currency and all other amounts from each receipt.
- File each receipt as a PDF into its month folder in Dropbox, unnumbered.
- Never overwrite or delete anything in Dropbox, and never touch a path outside the
  Receipts folder.
- Show what was uploaded, what could not be read or filed, and let Sam correct it.

## Non-goals

- Matching receipts to bank entries and writing `NN_` prefixes (next slice).
- Filing outgoing invoice PDFs (separate slice; needs frozen bank positions).
- Viseca bill import and `Kreditkarte/` numbering.
- Email forwarding, weekly digest, MCP tools.
- ~~Importing files that already sit in Dropbox~~ — now Part C.
- Capture from the iOS companion app. The web page must work on a phone instead.

## Two independently shippable parts

- **Part A — Dropbox connection.** OAuth, token refresh, the confined client, a settings
  panel and a doctor check. Shippable alone; nothing writes yet.
- **Part B — Receipts.** Upload, conversion, extraction, filing, list and detail pages.

## Decisions

1. **Plain HTTP client, no Dropbox package.** Six endpoints are needed and file IDs,
   `mode=add` and `autorename=false` must be controlled exactly. A small
   `App\Services\Dropbox\DropboxClient` on Laravel's `Http` facade does this and is
   trivially faked in tests. (The brief suggested `spatie/flysystem-dropbox`; a filesystem
   disk hides the file ID the module depends on.)
2. **`pdftotext` keeps the text layer; Claude still reads the document.** Sam installed
   poppler on production on 2026-10-07 (this decision first read "no `pdftotext` step",
   because it was missing). PDFs still go to Claude as documents for the fields — a vendor
   is often only in a logo, which a text dump loses — but `pdftotext -layout` runs first
   and its output is stored. The numbering slice needs that text for merchant keywords and
   exact amounts, as `sorter.py` and `ccmatch.py` use it today. No text layer, or no
   `pdftotext` binary, is not an error: the column stays null.
3. **File after extraction, not before.** Extraction takes seconds; waiting for it lets a
   photo be filed under a meaningful name instead of `IMG_4821`. If extraction fails for
   good, the receipt is filed anyway and flagged.
4. **PDF uploads keep their original filename; photos get a generated one**
   (`<document date>_<Vendor>.pdf`, e.g. `2026-09-14_Coop.pdf`). The convention keeps
   original names, but a camera has none worth keeping.
5. **Parking folder = month of the receipt's own date** (decided by Sam 2026-10-07,
   replacing the brief's "upload date"). If no date could be read, the month of the upload
   date (Zurich time) is used and the receipt is flagged. Editable per receipt. The
   numbering slice moves a receipt if its bank entry is in another month.
6. **Dropbox is the store.** Once a receipt is filed, ernte's local copy is deleted;
   previews and re-extraction read the file from Dropbox by ID.
7. **Images become PDF via the existing Chromium renderer.** Imagick normalises the image
   (orientation, size, JPEG); the PDF itself is produced the way invoice PDFs are.
   ImageMagick's own PDF writer is often disabled by server policy.

## Part A — Dropbox connection

### Dropbox app (created by Sam)

- Scoped app, **Full Dropbox** access — the Receipts folder is shared with the accountant
  and stays where it is, so an app folder will not do.
- Scopes: `files.metadata.read`, `files.content.read`, `files.content.write`,
  plus `account_info.read` to show which account is connected.
- Redirect URIs: `https://<production host>/settings/dropbox/callback` and the local
  DDEV URL.

### Configuration

```
DROPBOX_APP_KEY=
DROPBOX_APP_SECRET=
DROPBOX_RECEIPTS_ROOT=/Diluno/Receipts
```

### Connection storage

New columns on `business_profile`:

| column                    | type               | notes                          |
|---------------------------|--------------------|--------------------------------|
| `dropbox_refresh_token`   | text nullable      | `encrypted` cast               |
| `dropbox_account_label`   | string nullable    | account email, for display     |
| `dropbox_connected_at`    | datetime nullable  |                                |

The short-lived access token (about four hours) is kept in the cache and refreshed from
the refresh token on demand, and once more on a 401.

### OAuth flow

| method | path                           | purpose                                                    |
|--------|--------------------------------|------------------------------------------------------------|
| GET    | `/settings/dropbox/connect`    | redirect to Dropbox with `token_access_type=offline`, `state` in session |
| GET    | `/settings/dropbox/callback`   | verify `state`, exchange the code, store the refresh token |
| POST   | `/settings/dropbox/disconnect` | revoke the token at Dropbox and clear the columns          |

Settings page gets a "Dropbox" panel: connected account and date, the configured root
folder and whether it exists, connect / disconnect.

### `DropboxClient`

| method                                   | Dropbox endpoint                    |
|------------------------------------------|-------------------------------------|
| `metadata(string $pathOrId)`             | `files/get_metadata`                |
| `createFolder(string $path)`             | `files/create_folder_v2`            |
| `upload(string $path, string $contents)` | `files/upload`, `mode=add`, `autorename=false`, `mute=true` |
| `move(string $fileId, string $toPath)`   | `files/move_v2`, `autorename=false` |
| `download(string $fileId)`               | `files/download`                    |
| `temporaryLink(string $fileId)`          | `files/get_temporary_link`          |

- **Confinement.** Every path argument is normalised and must lie under
  `DROPBOX_RECEIPTS_ROOT`; anything else throws before a request is made. A file ID is
  resolved with `metadata` first and its current path checked the same way. The token can
  reach the whole Dropbox; this guard is what keeps ernte inside one folder.
- **No delete method exists** on the client.
- A name conflict surfaces as a typed `DropboxConflict` exception; a missing file as
  `DropboxNotFound`.

### Doctor

`ernte:doctor` reports: app key configured, connection present, token refresh works, root
folder exists. With Part B it also reports whether `pdftotext` is found (advisory).

## Part B — Receipts

### Lifecycle

```
uploaded ──convert (images)──► extract ──► file to Dropbox ──► filed
                                  │              │
                                  └─ failed      └─ failed (conflict, offline, not connected)
```

Two independent states, because a receipt can be unreadable yet filed, or read but not
yet filed:

- `extraction_status`: `pending` · `done` · `failed`
- `filing_status`: `pending` · `filed` · `failed` · `missing` (file no longer in Dropbox)

A receipt **needs attention** when extraction failed, confidence is low, no date was
read, or filing is
`failed` / `missing`.

### Data model: `receipts`

| column               | type                 | notes                                                  |
|----------------------|----------------------|--------------------------------------------------------|
| `id`                 | bigint pk            |                                                        |
| `original_name`      | string               | as uploaded                                            |
| `filename`           | string nullable      | name it is filed under                                 |
| `content_hash`       | char(64) unique      | SHA-256 of the uploaded bytes, before any conversion   |
| `original_mime`      | string               |                                                        |
| `size_bytes`         | unsigned int         |                                                        |
| `local_path`         | string nullable      | private disk; cleared after filing                     |
| `extraction_status`  | string               |                                                        |
| `extraction_error`   | string nullable      |                                                        |
| `vendor`             | string nullable      |                                                        |
| `document_date`      | date nullable        |                                                        |
| `total_minor`        | bigint nullable      | stated total in minor units of `currency`              |
| `currency`           | char(3) nullable     |                                                        |
| `amounts`            | json nullable        | every amount found: `[{"amount":"143.90","currency":"USD"}]` |
| `invoice_number`     | string nullable      |                                                        |
| `payment_method`     | string nullable      | `bank` · `card` · `unknown` (a guess)                  |
| `confidence`         | string nullable      | `high` · `medium` · `low`                              |
| `extraction`         | json nullable        | raw model output, for debugging                        |
| `text_layer`         | longtext nullable    | `pdftotext -layout` output; null for scans and photos  |
| `fields_edited`      | boolean              | Sam corrected a field; re-extraction must not overwrite |
| `target_year`        | smallint nullable    | parking folder; set after extraction                   |
| `target_month`       | tinyint nullable     |                                                        |
| `target_edited`      | boolean              | Sam chose the month; extraction must not change it     |
| `filing_status`      | string               |                                                        |
| `filing_error`       | string nullable      |                                                        |
| `dropbox_file_id`    | string nullable      | `id:…`; survives renames and moves                     |
| `dropbox_path`       | string nullable      | last path ernte saw; informational                     |
| `filed_at`           | datetime nullable    |                                                        |
| `note`               | text nullable        |                                                        |
| timestamps           |                      |                                                        |

`amounts` keeps both the stated total and every other figure because the numbering slice
needs both (instalments, QR-slip figures that differ from the invoice total).

### Upload

- Page **Receipts** (`/receipts`) with a drop zone and a file input
  (`accept="application/pdf,image/*"`, `multiple`), which on a phone offers the camera
  and the photo library.
- Accepted: PDF, JPEG, PNG, HEIC. Up to 20 MB per file.
- The browser posts one file per request, so a batch of large photos never exceeds the
  request size limit, and shows per-file progress.
- **Duplicates.** The server hashes the bytes; if the hash exists, nothing is stored and
  the response links to the existing receipt.
- A new receipt is stored on the private local disk and a job chain is queued.
  `target_year/month` are set when extraction finishes: from `document_date`, or from the
  upload date in Zurich when no date was read. A month Sam set by hand is not overwritten.
- Uploading works while Dropbox is disconnected; receipts wait as `filing_status = pending`.

### Jobs (queue `default`, three tries, timeout below the worker's 120 s)

1. **`PrepareReceiptPdf`** — images only. Imagick: auto-orient, strip metadata, longest
   side at most 2400 px, JPEG quality 85. The JPEG is wrapped into a one-page PDF by the
   Chromium renderer. PDFs pass through untouched, so their bytes in Dropbox equal the
   upload.
2. **`ExtractReceipt`** — first stores the PDF's text layer (`pdftotext -layout`, 30 s
   timeout, binary path from `PDFTOTEXT_PATH`, default `pdftotext`); empty output or a
   missing binary leaves `text_layer` null and the job carries on. Then
   sends the PDF to Claude as a document with a JSON schema for the
   fields above, using the same client and structured-output style as `EstimateDrafter`.
   Model from `ANTHROPIC_RECEIPT_MODEL`, defaulting to the model already configured.
   Skipped fields stay null; the model is told not to guess a total it cannot see.
   Does not overwrite fields when `fields_edited` is set.
3. **`FileReceipt`** — see below. Runs even when extraction failed.

### Filing

- Folder: `<root>/<YYYY>_Q<N>/<MM>/`, created if missing (quarter, then month).
- Name:
  - PDF upload → original name, with characters Dropbox rejects replaced.
  - Photo → `<document_date>_<Vendor>.pdf`; vendor reduced to letters, digits and hyphens.
    If extraction gave no date or vendor: `Beleg_<upload date>_<first 6 of hash>.pdf`.
- `upload` with `mode=add`, `autorename=false`.
- **Conflict.** For a generated name, `_2`, `_3`, … is tried (up to 9). For an original
  name the receipt becomes `filing_status = failed` with "a file with this name already
  exists"; Sam renames it in ernte and retries. ernte never overwrites.
- On success: store `dropbox_file_id`, `dropbox_path`, `filed_at`; delete the local copy.
- Any other failure after the retries: `filing_status = failed` with the reason, local
  copy kept, "retry" available. A receipt is never left half-filed: the Dropbox upload is
  a single request, and the file ID is only stored once Dropbox confirms it.

### After filing

- **Changing the month** moves the file: `metadata` by ID first (the accountant or Sam may
  have moved or renamed it), then `move` to the new folder under its current name.
- **Renaming** in ernte works the same way.
- If `metadata` reports the file gone, the receipt becomes `filing_status = missing`;
  ernte does not re-create it on its own.
- A file that already carries an `NN_` prefix when ernte looks at it (the scripts numbered
  it) is left alone and shown as "numbered: NN"; ernte only refreshes `dropbox_path`.
- **Removing a receipt from ernte** deletes the record and any local copy. A filed file
  stays in Dropbox; the confirmation says so.

### Pages

- **`/receipts`** — upload zone; filter chips *All · Needs attention · Not filed*; month
  filter. Columns: document date, vendor, total with currency, folder (`2026_Q3/09`),
  status. Sidebar item "Receipts" shows the needs-attention count.
- **`/receipts/{receipt}`** — the document on the left (local file, or a Dropbox temporary
  link once filed), fields on the right: vendor, document date, total, currency, invoice
  number, payment method, target month, filename, note. Actions: save, re-run extraction,
  retry filing, remove.
- Both pages must be usable at phone width; the list collapses to vendor, total and status.

### Routes

| method | path                            | purpose                                |
|--------|---------------------------------|----------------------------------------|
| GET    | `/receipts`                     | list                                   |
| POST   | `/receipts`                     | upload one file (JSON response)        |
| GET    | `/receipts/{receipt}`           | detail                                 |
| GET    | `/receipts/{receipt}/file`      | stream the local copy or redirect to a temporary link |
| PATCH  | `/receipts/{receipt}`           | edit fields, month, filename, note     |
| POST   | `/receipts/{receipt}/extract`   | re-run extraction                      |
| POST   | `/receipts/{receipt}/file`      | retry filing                           |
| DELETE | `/receipts/{receipt}`           | remove from ernte                      |

## Part C — Dropbox inbox and existing files (added 2026-10-07)

Sam wants to scan on the phone with the Dropbox app's document scanner instead of ernte's
upload page, and receipts already sit in Dropbox.

### Inbox

- Folder `<root>/_Inbox` (`DROPBOX_INBOX_FOLDER`), inside the shared folder (decided by
  Sam), created by ernte if missing. Being inside the root, the confinement rule is unchanged.
- `ernte:receipts:check-inbox` runs every five minutes; "Check Dropbox inbox" on the
  Receipts page runs it at once.
- Each **PDF** not yet known by its file ID is downloaded, hashed, registered with
  `source = inbox`, `filing_status = inbox`, and queued: read, then **moved** (not
  uploaded) into the month folder of its date.
- Naming: a scanner or camera name (`Scan 7 Oct 2026.pdf`, `IMG_4821.pdf`) is replaced by
  `<date>_<Vendor>.pdf`, with `_2`… on a clash; any other name is kept, and a clash leaves
  the file in the inbox. The same rule now applies to PDFs uploaded through ernte.
- A file stays in the inbox, flagged, when no date could be read (Sam sets the month and
  presses "File now"), on a name clash, or when it is a **duplicate** of a known receipt
  (Sam deletes it in Dropbox; ernte then drops the duplicate's record).
- Non-PDF files in the inbox are skipped and counted: ernte cannot replace a photo by its
  PDF without deleting, which it never does.

### Existing files

- `ernte:receipts:adopt {year} {quarter} [--dry-run]` lists PDFs in that quarter's month
  folders and `Kreditkarte/` subfolders that ernte does not know, and after confirmation
  registers them **in place** (`source = existing`, already `filed`, month taken from the
  folder) and queues reading. Nothing is moved or renamed; numbered files keep their
  prefix. Files with a leading underscore (statements) are left out.
- Deliberately manual and per quarter: every adopted file is sent to Claude.

### Data model additions

`receipts.source` (`upload` · `inbox` · `existing`), `receipts.duplicate_of_id`;
`content_hash` is no longer unique in the database (a duplicate in the inbox has its own
record), uniqueness for uploads is enforced in code. New `filing_status` value `inbox`.

## Testing

- **DropboxClient** (`Http::fake`): token refresh and retry on 401; every method refuses a
  path outside the root, including `..` and a file ID that resolves outside; conflict and
  not-found map to their exceptions; `upload` always sends `mode=add`, `autorename=false`.
- **OAuth:** state mismatch rejected; refresh token stored encrypted; disconnect clears it.
- **Upload:** PDF and image accepted, other types and oversize rejected; duplicate returns
  the existing receipt and stores nothing.
- **PrepareReceiptPdf:** a rotated JPEG comes out upright as a one-page PDF. HEIC case
  skipped where the local Imagick lacks HEIC.
- **ExtractReceipt:** text layer stored for a text PDF, null for an image-only PDF and
  when the binary is missing (the job still succeeds); target month from the document date; from the Zurich upload date
  (at a UTC day boundary) when no date was read; a hand-set month is kept. Fields mapped
  from a faked model response; null total stays null;
  edited fields survive a re-run; failure sets `failed` and still queues filing.
- **FileReceipt:** folder creation; original name kept for PDFs; generated name and suffix
  on conflict for photos; conflict on an original name → `failed`, nothing overwritten;
  local copy deleted only after success; not connected → stays `pending`.
- **After filing:** month change resolves the current path by ID before moving; missing
  file → `missing`; a prefixed file is not renamed.
- **Feature:** list and detail props, filters, needs-attention count. Both pages must be
  in the Vite manifest for the feature tests.

## Rollout

1. Sam creates the Dropbox app and adds key, secret and root to the production `.env`.
2. **Raise the upload limit on production.** PHP-FPM currently allows 2 MB per file and
   8 MB per request; a phone photo is larger. Set the maximum upload size to 25 MB in
   Forge (it adjusts PHP and nginx together).
3. Deploy Part A, connect Dropbox in Settings, check `ernte:doctor`.
4. Deploy Part B. Upload two or three real receipts and check them in the Dropbox folder
   before relying on it.

Checked on production (2026-10-07): PHP 8.5 with Imagick supporting HEIC, JPEG, PNG and
PDF; no ImageMagick command-line tools; queue worker `--tries=3 --timeout=120`.
`pdftotext` 24.02.0 at `/usr/bin/pdftotext` (poppler installed by Sam, verified over SSH
2026-10-07). Local DDEV has `pdftotext` 25.03.0 via `.ddev/web-build/Dockerfile.poppler`.

## Open questions

- ~~Generated names for photos~~ — accepted by Sam (2026-10-07).
- ~~Parking by upload or document date~~ — document date (2026-10-07).
- **Local DDEV:** does its Imagick read HEIC? If not, HEIC uploads only work on
  production and the test is skipped locally.
- **Receipts sent to Anthropic.** Extraction sends each receipt to the Claude API, as
  estimates drafting already does with briefs. Receipts can carry personal data of third
  parties. Accepted by Sam (2026-10-07); a local-first variant (read text PDFs on the
  server, send only scans and photos) remains possible later.
