# Multiple client contacts + per-document recipient selection

**Date:** 2026-07-02
**Status:** Approved (design)

## Problem

A client currently has exactly one contact — the `contact_name` and `email` columns on
the `clients` table. Every invoice, estimate, and reminder is emailed to that single
address (`Mail::to($document->client->email)`). Real clients have several relevant people
(e.g. a project lead and an accounting address), and different documents may need to reach
different subsets of them. We need to store multiple contacts per client and choose which
of them receive each invoice, estimate, and recurring invoice — possibly several at once.

## Goals

- Store an arbitrary number of contacts per client (name, email, optional role label).
- Let the user mark a subset of a client's contacts as the **default recipients**.
- When creating an invoice / estimate / recurring invoice, pre-fill recipients from the
  client's defaults and let the user add/remove any of the client's contacts.
- Send each document to all its selected recipients.
- Keep an accurate record of who a sent document actually went to, even if a contact is
  later edited or deleted.

## Non-goals (YAGNI)

- Per-document-type default lists (invoices vs estimates vs recurring going to different
  people automatically). A single shared default list per client. Can be split later.
- Contact-level metadata beyond name / email / role (no phone, no postal address — the
  billing address stays on the client).
- Free-typing an ad-hoc email that isn't a saved contact. Recipients are always chosen
  from the client's contacts. (A contact can be added inline, then selected.)

## Decisions

1. **One shared default list per client** — a `is_default` boolean on each contact, not
   per-type flags.
2. **Recipients are snapshotted per document** as `{name, email}` pairs in a JSON column,
   captured from the selected contacts. Storing the address (not a `contact_id` FK) keeps a
   sent document's audit trail accurate if the contact is later changed or removed.
3. **Recipients are set at document creation and remain editable until the document is
   sent.** Drafts already carry their recipients.
4. **Addressing:** the first recipient goes on `To`, the rest on `Cc`.

## Data model

### New table: `contacts`

| column        | type            | notes                                        |
|---------------|-----------------|----------------------------------------------|
| `id`          | bigint pk       |                                              |
| `client_id`   | fk → clients    | cascade on delete                            |
| `name`        | string          | required                                     |
| `email`       | string          | required                                     |
| `role`        | string nullable | optional label, e.g. "Accounting"            |
| `is_default`  | boolean         | default `false`; part of the default set     |
| `sort_order`  | integer         | default `0`; display order                   |
| timestamps    |                 |                                              |

- `Client hasMany Contacts` (ordered by `sort_order`, then `id`).
- `Contact belongsTo Client`.

### Migration / backfill

- Create `contacts`.
- For every existing client with a non-empty `email`, insert one contact:
  `name = contact_name ?: name`, `email = email`, `is_default = true`, `sort_order = 0`.
- Drop `contact_name` and `email` from `clients` after backfill.

### Documents: recipient snapshot

Add a nullable `recipients` JSON column to `invoices`, `estimates`, and
`recurring_invoices`. Shape:

```json
[{ "name": "Marc Gschwend", "email": "marc@moveable.ch" }]
```

- On document create, `recipients` is initialised from the client's default contacts.
- `recurring_invoices.recipients` is the template: each generated invoice copies the
  recurring invoice's `recipients` into its own column at generation time.
- Backfill existing documents' `recipients` from their client's (now migrated) default
  contact so historical sends still resolve.

## Send paths

Three call sites currently read `$document->client->email`:

- `App\Services\Invoicing\InvoiceLifecycle` (invoice send)
- `App\Services\Estimating\EstimateLifecycle` (estimate send)
- `App\Jobs\SendInvoiceReminderMail` (reminder)

Change each to send to the document's stored `recipients`:

```php
$recipients = $document->recipients ?? [];
if (empty($recipients)) {
    throw new \DomainException('Cannot send … because no recipients are selected.');
}
$to = collect($recipients)->map(fn ($r) => new Address($r['email'], $r['name']));
Mail::to($to->first())->cc($to->slice(1)->all())->send(new …);
```

- The existing "client has no email" guard becomes a "no recipients selected" guard.
- Event logging (`email_to`) records the full recipient list instead of a single address.
- Reminders reuse the invoice's stored `recipients`.

## UI

### Contact management (client)

- On the client **create/edit** form: a contacts editor — a repeatable list of rows with
  `name`, `email`, `role`, and a "default recipient" checkbox; add / remove / reorder.
- On the client **show** page: the `CONTACT` panel lists all contacts (name, email, role),
  marking the default ones.
- `StoreClientRequest` / `UpdateClientRequest` validate a `contacts` array; the controller
  syncs contacts (create/update/delete) alongside the client.
- `ClientDetail::payload` / `ClientProjections` expose `contacts` instead of the single
  `contact_name` / `email`.

### Recipient picker (documents)

A shared recipient picker used on the invoice, estimate, and recurring-invoice create/edit
forms:

- Lists the client's contacts as checkboxes, pre-checked for the client's defaults.
- The checked set is submitted and stored as the document's `recipients` snapshot.
- Shown on the draft; editable until the document is sent.

## Testing

- **Migration/backfill:** existing single-contact clients produce exactly one default
  contact; documents backfill to that contact.
- **Contact CRUD:** add/edit/remove contacts on a client; default flag round-trips.
- **Recipient defaulting:** creating an invoice/estimate/recurring for a client pre-fills
  recipients from the client's default contacts.
- **Send fan-out:** sending a document with multiple recipients puts the first on `To` and
  the rest on `Cc`; the sent event records all of them.
- **Guard:** sending a document with no recipients throws and does not mark it sent.
- **Recurring template copy:** a generated invoice inherits the recurring invoice's
  recipients.

## Rollout / sequencing

Likely two shippable slices:

1. **Contacts foundation** — `contacts` table, backfill, `Client` relation, contact
   management UI, drop the old columns. Documents keep sending to the (now migrated)
   default contact via a resolved recipient list.
2. **Per-document recipient selection** — `recipients` columns, defaulting, recipient
   picker on the three document forms, send-path fan-out, recurring template copy.

Slice 1 leaves the app fully working (multiple contacts, still auto-addressing defaults);
slice 2 layers on explicit per-document choice.
