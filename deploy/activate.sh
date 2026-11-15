#!/usr/bin/env bash
#
# Runs on the server as the site user, from inside a freshly uploaded release of apps/api (the deploy workflow
# pipes it over SSH). Links the shared .env, storage and database, migrates, caches, then switches `current`
# to this release in one atomic step and keeps the last five releases for rollbacks.
set -euo pipefail

APP_DIR="${APP_DIR:-/home/mofaregh/htdocs/mofaregh.smszs.tech}"
RELEASE="$(pwd -P)"
PHP=8.4

case "$RELEASE" in
    "$APP_DIR"/releases/*) ;;
    *) echo "Run this from a release directory under $APP_DIR/releases." >&2; exit 1 ;;
esac

ln -sfn "$APP_DIR/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage"
ln -sfn "$APP_DIR/shared/storage" "$RELEASE/storage"
mkdir -p "$RELEASE/bootstrap/cache"
chmod -R g+w "$RELEASE/bootstrap/cache"

php artisan migrate --force --no-interaction
php artisan optimize

ln -sfn "$RELEASE" "$APP_DIR/current.tmp"
mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"

sudo /usr/bin/systemctl reload "php${PHP}-fpm"

# A queue worker started by the scheduler keeps the old code in memory: ask it to stop after its current job.
php "$APP_DIR/current/artisan" queue:restart >/dev/null

# Keep the five newest releases.
cd "$APP_DIR/releases"
ls -1dt -- */ | tail -n +6 | xargs -r rm -rf --

echo "Live: $RELEASE"
