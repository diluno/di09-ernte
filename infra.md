# Infrastructure — Ernte (di09)

_Last audited: 2026-07-08 (verified over SSH)._

## Hosting
- **Provider:** Laravel Forge on a Hetzner VM ("willow", ~4 GB RAM), shared with other sites (also serves beszel.dil.uno)
- **Server IP:** 167.233.123.148
- **SSH alias:** `willow` (`ssh forge@167.233.123.148`, defined in `~/.zshrc`)
- **Previous home:** DO droplet 206.81.16.58 (`traktor`) — migrated off; stale known_hosts entry may linger
- **Repo:** git@github.com:diluno/di09-ernte.git (branch `main`)

## Runtime (verified 2026-07-08)
- **PHP:** 8.5.7 (composer.json constraint ^8.3)
- **Node:** 22.22.3
- **DB:** MySQL 8.4.9, database `ernte`, user `ernte` (local dev uses SQLite via DDEV)
- **Queue:** one Forge daemon (confirmed running):
  `php8.5 artisan queue:work database --queue=default,emails --sleep=3 --tries=3 --timeout=120` against `current/`
- **Scheduler:** runs (daily backups confirmed at 03:00 Europe/Zurich); cron entry lives in root's crontab, not visible to the forge user. App schedule (routes/console.php):
  - `ernte:backup` — daily 03:00
  - `ernte:invoices:generate-recurring` — daily 06:00
  - `ernte:invoices:remind` — daily 09:00
  - `ernte:invoices:stamp-overdue` — daily
- **PDF generation:** Browsershot with system Chrome at `/usr/bin/google-chrome-stable`
  (installed via `deploy/forge/provision-chrome-and-backups.sh`; `PUPPETEER_SKIP_DOWNLOAD=true`)

## Deploys
- **Zero-downtime** Forge releases: script in `deploy/forge/deploy.sh` (pasted into the Forge deploy-script editor; uses `$CREATE_RELEASE()` macro).
- Steps: composer install (no-dev) → npm ci + `npm run build` (Vite) → storage dirs → `migrate --force` → `db:seed --class=BootstrapSeeder` → `artisan optimize` → `ernte:doctor --advisory` → swap `current` symlink → keep 3 releases → `queue:restart`.
- Live code served from `/home/forge/ernte.dil.uno/current` — daemons/cron must target `current`, not the site root.
- Runbook: `docs/deployment/forge.md`. Production env checklist: `.env.forge.example`.

## Domains & Mail
- **Domain:** ernte.dil.uno → 167.233.123.148. Forge default hostname: di09-ernte-v7vosalx.on-forge.com.
- **DNS:** Cloudflare (dil.uno nameservers kyle/natasha.ns.cloudflare.com)
- **SSL:** Let's Encrypt, auto-renewed via Forge cron (/etc/cron.d)
- **Mail:** SMTP (TLS, port 587); provider/host in the Forge site env. Non-production mail is redirected to the operator in `AppServiceProvider`.

## Secrets
- Local: `.env` (DDEV). Production: Forge site environment editor. Never in the repo.

## Known quirks
- **OPcache is at the 128 MB default on willow too** — this exhausted on the old 1 GB droplet and caused per-request recompiles/slow nav. Consider raising `opcache.memory_consumption` here as well.
- Deploy script does **not** reload php8.5-fpm — if stale-bytecode/OPcache symptoms appear after a deploy, add an `flock`-guarded `sudo service php8.5-fpm reload` to the script.
- Forge's recurring "Tunnel exited with a non-zero code [1]" after deploys is cosmetic (tunnel drop after a successful deploy), not a failure — verify by checking `current` points at the newest release.
- Daemons or cron pointing at the site root instead of `current/` fail with `Could not open input file: artisan`.
- A `*.quarantine-*` release dir sits in the site root from the migration — leftover, ignorable/deletable.
- Old apt-mirror fallback logic in the provision script targeted DigitalOcean mirrors; harmless on Hetzner.
- `ernte:doctor --advisory` lets first deploys activate even with incomplete SMTP/Chrome config — check its output after deploying.
- Backups land in `storage/app/private/backups/` on the server (daily, ~24 kept).
