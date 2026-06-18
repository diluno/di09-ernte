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
$FORGE_PHP artisan ernte:doctor --advisory --no-interaction

SITE_DIRECTORY="$(dirname "$(dirname "$FORGE_RELEASE_DIRECTORY")")"
RELEASES_DIRECTORY="$SITE_DIRECTORY/releases"
CURRENT_LINK="$SITE_DIRECTORY/current"
NEXT_LINK="$SITE_DIRECTORY/current-temp"

echo "=> Activating release"
ln -sfn "$FORGE_RELEASE_DIRECTORY" "$NEXT_LINK"
mv -Tf "$NEXT_LINK" "$CURRENT_LINK"

echo "=> Purging old releases"
mapfile -t OLD_RELEASES < <(
    find "$RELEASES_DIRECTORY" -mindepth 1 -maxdepth 1 -type d ! -path "$FORGE_RELEASE_DIRECTORY" -printf '%T@ %p\n' \
        | sort -rn \
        | awk 'NR > 3 { print $2 }'
)

for OLD_RELEASE in "${OLD_RELEASES[@]}"; do
    rm -rf -- "$OLD_RELEASE"
done

$FORGE_PHP artisan queue:restart
