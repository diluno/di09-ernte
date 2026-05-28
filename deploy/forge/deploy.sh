#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${FORGE_SITE_PATH:-$HOME/ernte.example.com}"
BRANCH="${FORGE_SITE_BRANCH:-main}"

cd "$APP_DIR"

git pull origin "$BRANCH"

export PUPPETEER_SKIP_DOWNLOAD="${PUPPETEER_SKIP_DOWNLOAD:-true}"

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

php artisan storage:link --force
php artisan optimize:clear
php artisan migrate --force --no-interaction
php artisan db:seed --class=BootstrapSeeder --force --no-interaction
php artisan config:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan ernte:doctor --no-interaction
