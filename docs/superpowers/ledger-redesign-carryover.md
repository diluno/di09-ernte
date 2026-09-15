# Ledger redesign — carryover after slice 1 (2026-09-15)

Slice 1 (plan `plans/2026-09-15-ledger-redesign-invoices.md`) shipped the Ledger tokens, the app shell, the Invoices list and the Invoice detail. Mocks and the value spec live in `design/ledger/`.

## Pages still on pre-Ledger markup

They inherit the new shell, tokens, 64px `.page-title`, buttons, tables and stats strip, but their own layout has not been reworked and they have no mocks yet:

- Projects: Index, Show, Create, Edit (budget bars, glyphs, tasks, burndown)
- Timer: Today (timer hero, entry rows, manual entry form)
- Clients: Index, Show, Create, Edit (client cards, heatmap)
- Estimates: Index, Show, Create, Edit (Index still uses `.doc-id` + `is-open` rows; Show still has the aside title editor)
- Recurring invoices: Index, Create, Edit
- Invoices: Create, Edit (line editor, summary card)
- Settings: Profile, VatRates; Profile/Edit
- Auth: Login and the other guest pages (GuestLayout)
- CommandPalette (retoned only)

## Tokens to retire

`--forest`, `--rust`, `--gold`, `--accent`, `--accent-on`, `--ink-4`, `--bg*` and `--bg-hover/active` are aliases onto the Ledger palette so untouched pages keep working. Delete each once no page references it (`grep -rn "var(--forest)" resources`).

## Open items

- Iosevka Aile is named in `--font-mono` but not bundled; it only renders where installed locally. Add woff2 files to `resources/fonts/` plus an `@font-face` in `app.css` if the mono should be consistent everywhere.
- Year navigation on the month chart is two small arrow buttons in the legend column (the mock had none).
- "Send reminder now" (`POST /invoices/{invoice}/remind`) bypasses the pause flag and cadence by design; the job's `force` flag is what makes that possible.
- Below 1280px nothing was tuned; the old `@media` rules remain.
