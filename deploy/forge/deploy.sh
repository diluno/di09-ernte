#!/usr/bin/env bash
set -euo pipefail

$CREATE_RELEASE()

cd "$FORGE_RELEASE_DIRECTORY"

export PUPPETEER_SKIP_DOWNLOAD="${PUPPETEER_SKIP_DOWNLOAD:-true}"

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci || npm install
npm run build

mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

$FORGE_PHP artisan storage:link
$FORGE_PHP artisan migrate --force --no-interaction
$FORGE_PHP artisan db:seed --class=BootstrapSeeder --force --no-interaction
$FORGE_PHP artisan optimize
$FORGE_PHP artisan ernte:doctor --no-interaction

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
