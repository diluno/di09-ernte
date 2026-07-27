# Laravel Forge Deployment

This is the Phase 3 production path for Ernte. It supersedes the older docker-compose deployment note in the original design spec.

## Assumptions

- Laravel Forge provisions the server, Nginx site, SSL certificate, PHP-FPM, database, scheduler, and queue daemon.
- PHP 8.3 or newer, Node 20, Composer 2, and MariaDB/MySQL are available on the Forge server.
- Deploys are **zero-downtime** (the deploy script uses Forge release macros), so the live code is served from the `current` release symlink — e.g. `/home/forge/ernte.example.com/current` — not directly from the site root `/home/forge/ernte.example.com`. Any daemon or scheduled job must target the `current` path, otherwise it runs in a directory with no `artisan` and fails with `Could not open input file: artisan`.
- `QUEUE_CONNECTION=database` is used; the reminder job queue is `emails`.
- Generated invoice PDFs and backups stay on the local Forge server under `storage/app/private`.

## One-Time Server Recipe

Run the recipe in `deploy/forge/provision-chrome-and-backups.sh` once on the Forge server. It installs:

- Google Chrome for Browsershot PDF generation.
- A MySQL client only if `mysqldump` is missing. Forge/MySQL servers often already provide `/usr/bin/mysqldump`; in that case the script skips the client package to avoid package-source conflicts.
- Minimal libraries Chrome commonly needs on Ubuntu servers.

After it finishes, set this in the Forge site's environment:

```dotenv
BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome-stable
PUPPETEER_SKIP_DOWNLOAD=true
```

If `apt-get update` fails with `File has unexpected size` or `Mirror sync in progress`, the Ubuntu mirror is temporarily inconsistent. The provision script clears apt package lists, retries, and then switches only DigitalOcean Ubuntu mirror entries from `mirrors.digitalocean.com/ubuntu` to `archive.ubuntu.com/ubuntu` before retrying again.

## Environment

Use `.env.forge.example` as the production checklist. In Forge, fill the real values for:

- `APP_URL`, `APP_KEY`, `APP_VERSION`, `APP_PORT`
- `DB_*`
- `MAIL_*`
- `ERNTE_USER_*`
- `BUSINESS_*`
- `BROWSERSHOT_CHROME_PATH`

Generate the key once in the site shell if Forge has not already done it:

```bash
php artisan key:generate --show
```

Paste the printed key into `APP_KEY`.

## Deploy Script

Paste the contents of `deploy/forge/deploy.sh` into the Forge deployment script editor. It uses Forge's `$CREATE_RELEASE()` macro and `$FORGE_RELEASE_DIRECTORY` variable, so it is a Forge script template rather than a script to run directly over SSH.

The script creates a release, installs production Composer dependencies, installs Node dependencies, builds Vite assets, prepares storage directories, runs migrations, runs the idempotent bootstrap seeder, warms Laravel caches, checks the release with `php artisan ernte:doctor --advisory`, activates the release by swapping the `current` symlink, prunes older releases, and restarts queue workers. Advisory mode prints configuration failures without blocking the release, so first deploys can still activate while SMTP/SSL/Browsershot are being finished.

## Queue Daemon

Create one Forge daemon:

```bash
php artisan queue:work database --queue=default,emails --sleep=3 --tries=3 --timeout=120
```

Recommended Forge settings:

- User: `forge`
- Directory: the **current release** path, for example `/home/forge/ernte.example.com/current` (not the site root — zero-downtime deploys keep the code under `current`)
- Processes: `1`
- Stop seconds: `10`

The deploy script calls `php artisan queue:restart`, so Forge's daemon will pick up new code after each deploy.

## Scheduler

Create one Forge scheduled job that runs every minute. Forge scheduled jobs run from the user's home directory (`/home/forge`), which has no `artisan`, so the command **must** `cd` into the current release first:

```bash
cd /home/forge/ernte.example.com/current && php artisan schedule:run
```

A bare `php artisan schedule:run` fails silently every minute with `Could not open input file: artisan`, which stops *all* of the jobs below from ever running.

Laravel's scheduler then handles:

- `ernte:invoices:generate-recurring` daily at 06:00
- `ernte:backup` daily at 03:00
- `ernte:invoices:remind` daily at 09:00
- `ernte:invoices:stamp-overdue` daily

## Smoke Test

After deploy, run these from the Forge site shell:

```bash
php artisan ernte:doctor
php artisan ernte:backup
php artisan ernte:invoices:stamp-overdue
php artisan ernte:invoices:remind
```

Then check the web app:

1. `https://your-domain.example/up` returns HTTP 200.
2. Login with `ERNTE_USER_EMAIL`.
3. Open `/settings` and confirm the business profile is editable.
4. Open `/invoices`; draft PDF preview should render.
5. If SMTP is configured and a test client has an email address, send one draft invoice and confirm it receives a PDF attachment.
6. Confirm the status bar shows the production host from `APP_URL`, database information, and a non-`never` backup timestamp after the manual backup.

Common `ernte:doctor` fixes:

- `APP_URL` must be the HTTPS production URL, for example `https://ernte.dil.uno`.
- `MAIL_MAILER=log` is fine for local development only. Set a real production mailer such as `smtp`, with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`.
- `BROWSERSHOT_CHROME_PATH` must point at an executable Chrome/Chromium binary. If the provision recipe installed Google Chrome, use `/usr/bin/google-chrome-stable`. Confirm on the server with `which google-chrome-stable chromium chromium-browser`.

## Restore Notes

`php artisan ernte:backup` stores backups under `storage/app/private/backups/{timestamp}`. Each backup contains:

- `database.sql.gz`
- `invoices.tar.gz`, when generated PDFs exist
- `manifest.json`

To restore, put the app in maintenance mode, import the SQL dump into the Forge database, extract `invoices.tar.gz` back under `storage/app/private/invoices`, run `php artisan optimize:clear`, then bring the app back up.

## MCP Server

ernte exposes its estimates over MCP at `POST /api/mcp` (streamable HTTP), authed with
the same Sanctum tokens as the rest of `/api` and rate-limited to 60 requests/minute.
Tools: `list_clients`, `list_estimates`, `get_estimate`, `draft_estimate_lines`,
`create_estimate`, `update_estimate`, `send_estimate`, `accept_estimate`,
`decline_estimate`, `convert_estimate_to_invoice`.

Mint a token with the existing auth endpoint:

```sh
curl -s -X POST https://ernte.dil.uno/api/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"…","device_name":"claude-mcp"}'
```

Then register it with Claude Code:

```sh
claude mcp add --transport http ernte https://ernte.dil.uno/api/mcp \
  --header "Authorization: Bearer <token>"
```

Revoke from tinker with `PersonalAccessToken::where('name', 'claude-mcp')->delete()`.

`send_estimate` emails the client and is not reversible — the tool requires an explicit
estimate number so it cannot fire on a vague instruction, but there is no confirmation
step beyond that. `draft_estimate_lines` needs `ANTHROPIC_API_KEY` set in the site env;
every other tool works without it.
