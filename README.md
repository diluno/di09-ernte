# Ernte

Self-hosted time tracking and Swiss invoicing for one operator.

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
- [Forge deploy script](deploy/forge/deploy.sh)
- [Chrome/backups provision recipe](deploy/forge/provision-chrome-and-backups.sh)

Forge must run one scheduler job (`php artisan schedule:run`) and one queue daemon (`php artisan queue:work database --queue=default,emails --sleep=3 --tries=3 --timeout=120`).

## Tests

```bash
ddev artisan test
```
