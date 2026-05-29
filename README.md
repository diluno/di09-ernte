# 𖧧 ernte

Self-hosted time tracking and Swiss invoicing for one operator.

Ernte is a single-user app for tracking billable time against clients and
projects, then turning that time into Swiss QR-bill invoices and estimates —
including recurring billing.

## Features

- **Time tracking** — a running timer with start/stop/switch/discard, plus
  manual time entries grouped by day.
- **Clients & projects** — client records and projects (with tasks, pinning,
  and archiving) to organize tracked time.
- **Invoices** — build invoices from tracked time, preview them, generate
  Swiss QR-bill PDFs, send them, and mark them sent/paid/void/overdue.
- **Estimates** — draft estimates, send for acceptance, and convert accepted
  estimates into invoices.
- **Recurring invoices** — schedules that automatically generate invoices on a
  cadence, with pause/resume/run-now controls.
- **Reports** — reporting and analytics across tracked time and billing.
- **Global search** and per-user **settings / business profile**.

## Tech stack

- **Backend:** PHP `^8.2`, Laravel `^12.0`, Inertia.js (Laravel) `^2.0`,
  Laravel Sanctum `^4.0`
- **Invoicing/PDF:** Swiss QR Bill `^5.3`, Browsershot `^5.4` (Chromium)
- **Frontend:** Vue `^3.4`, `@inertiajs/vue3` `^2.0`, Vite `^7.0`, Ziggy
  `^2.0`, custom CSS with design tokens (no Tailwind)
- **Tooling:** Pest `^4.7` (tests), Laravel Pint (formatting)

## Local development (DDEV)

```bash
git clone <repo> ernte && cd ernte
ddev start                  # builds web image with Chromium
ddev exec bin/install       # composer + npm + migrate + seed
ddev npm run dev            # vite watcher
ddev launch                 # opens browser
```

Default login: see `ERNTE_USER_*` in your `.env`.

## Production (Laravel Forge)

Production deployment targets Laravel Forge, not Docker. Start with:

- [Forge deployment runbook](docs/deployment/forge.md)
- [.env.forge.example](.env.forge.example)
- [Forge deploy script template](deploy/forge/deploy.sh)
- [Chrome/backups provision recipe](deploy/forge/provision-chrome-and-backups.sh)

Forge must run one scheduler job (`php artisan schedule:run`) and one queue daemon (`php artisan queue:work database --queue=default,emails --sleep=3 --tries=3 --timeout=120`).

The scheduler drives the daily background jobs: recurring-invoice generation
(`ernte:invoices:generate-recurring`), payment reminders
(`ernte:invoices:remind`), overdue stamping (`ernte:invoices:stamp-overdue`),
and backups (`ernte:backup`).

## Importing from Harvest

A one-time importer pulls clients, projects, invoices, and estimates out of
[Harvest](https://www.getharvest.com/) into Ernte:

```bash
ddev artisan harvest:import --dry-run   # fetch and report counts, write nothing
ddev artisan harvest:import             # run the import (prompts before writing)
```

Credentials come from `HARVEST_ACCESS_TOKEN` and `HARVEST_ACCOUNT_ID` in your
`.env`, or via `--token=` / `--account=`.

> **Destructive:** a real run **deletes all existing clients, projects,
> invoices, estimates** (and any time entries and tasks) before importing. It
> asks for confirmation first; pass `--force` to skip the prompt. Use
> `--dry-run` to preview counts safely.

## Tests

```bash
ddev artisan test
```
