# Ernte

Self-hosted time tracking & invoicing. Single user. Swiss QR-bill on invoices.

## Local development (DDEV)

```bash
git clone <repo> ernte && cd ernte
ddev start                  # builds web image with Chromium
ddev exec bin/install       # composer + npm + migrate + seed
ddev npm run dev            # vite watcher
ddev launch                 # opens browser
```

Default login: see `ERNTE_USER_*` in your `.env`.

## Tests

```bash
ddev artisan test
```

## Production deploy

See `docs/superpowers/specs/2026-05-27-ernte-design.md` § 8 (docker-compose).
