# Laravel Forge Deployment

This is the Phase 3 production path for Ernte. It supersedes the older docker-compose deployment note in the original design spec.

## Assumptions

- Laravel Forge provisions the server, Nginx site, SSL certificate, PHP-FPM, database, scheduler, and queue daemon.
- PHP 8.3 or newer, Node 20, Composer 2, and MariaDB/MySQL are available on the Forge server.
- The app runs from a normal Forge site directory such as `/home/forge/ernte.example.com`.
- `QUEUE_CONNECTION=database` is used; the reminder job queue is `emails`.
- Generated invoice PDFs and backups stay on the local Forge server under `storage/app/private`.

## One-Time Server Recipe

Run the recipe in `deploy/forge/provision-chrome-and-backups.sh` once on the Forge server. It installs:

- Google Chrome for Browsershot PDF generation.
- `default-mysql-client`, which provides `mysqldump` for `php artisan ernte:backup`.
- Minimal libraries Chrome commonly needs on Ubuntu servers.

After it finishes, set this in the Forge site's environment:

```dotenv
BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome-stable
PUPPETEER_SKIP_DOWNLOAD=true
```

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

Use `deploy/forge/deploy.sh` as the Forge deployment script. In the Forge UI, set the site path before calling it:

```bash
export FORGE_SITE_PATH=/home/forge/ernte.example.com
export FORGE_SITE_BRANCH=${FORGE_SITE_BRANCH:-main}
bash deploy/forge/deploy.sh
```

The script pulls the branch, installs production Composer dependencies, installs Node dependencies, builds Vite assets, creates storage directories, runs migrations, runs the idempotent bootstrap seeder, warms Laravel caches, restarts queue workers, and finishes with `php artisan ernte:doctor`.

## Queue Daemon

Create one Forge daemon:

```bash
php artisan queue:work database --queue=default,emails --sleep=3 --tries=3 --timeout=120
```

Recommended Forge settings:

- User: `forge`
- Directory: the site path, for example `/home/forge/ernte.example.com`
- Processes: `1`
- Stop seconds: `10`

The deploy script calls `php artisan queue:restart`, so Forge's daemon will pick up new code after each deploy.

## Scheduler

Create one Forge scheduled job that runs every minute:

```bash
php artisan schedule:run
```

Laravel's scheduler then handles:

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

## Restore Notes

`php artisan ernte:backup` stores backups under `storage/app/private/backups/{timestamp}`. Each backup contains:

- `database.sql.gz`
- `invoices.tar.gz`, when generated PDFs exist
- `manifest.json`

To restore, put the app in maintenance mode, import the SQL dump into the Forge database, extract `invoices.tar.gz` back under `storage/app/private/invoices`, run `php artisan optimize:clear`, then bring the app back up.
