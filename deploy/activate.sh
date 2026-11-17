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

# Python side (Word export through the client template, and Whisper for the automatic draft): one virtualenv
# shared by every release. Nothing here may stop a deploy: without it the app runs and says the feature is off.
VENV="$APP_DIR/shared/venv"
setup_python() {
    [ -d "$RELEASE/python" ] || return 0
    command -v python3 >/dev/null || { echo "WARN python: python3 is not installed; Word export and ASR stay off."; return 0; }
    if [ ! -x "$VENV/bin/pip" ]; then
        # Debian/Ubuntu ship ensurepip in python3-venv, which needs root to install. Without it, build the
        # virtualenv without pip and bootstrap pip from PyPA's own installer (no root needed).
        if ! python3 -m venv --clear "$VENV" >/dev/null 2>&1; then
            python3 -m venv --clear --without-pip "$VENV"                 && curl -fsSL https://bootstrap.pypa.io/get-pip.py -o "$VENV/get-pip.py"                 && "$VENV/bin/python" "$VENV/get-pip.py" --quiet                 || { echo "WARN python: could not create $VENV (apt install python3-venv)."; return 0; }
            rm -f "$VENV/get-pip.py"
        fi
    fi
    "$VENV/bin/python" -m pip install --quiet --upgrade pip || true

    # First the dependencies, then our own code again: the version number does not change between deploys.
    if "$VENV/bin/pip" install --quiet "$RELEASE/python/text-engine"         && "$VENV/bin/pip" install --quiet --force-reinstall --no-deps "$RELEASE/python/text-engine"         && "$VENV/bin/python" -c "import mofaregh_text.cli"; then
        grep -q '^MOFAREGH_PYTHON=' "$APP_DIR/shared/.env" || echo "MOFAREGH_PYTHON=$VENV/bin/python" >> "$APP_DIR/shared/.env"
        echo "python: Word export ready ($("$VENV/bin/python" --version))"
    else
        echo "WARN python: text-engine did not install; Word export stays off."
    fi

    if "$VENV/bin/pip" install --quiet "$RELEASE/python/asr"         && "$VENV/bin/pip" install --quiet --force-reinstall --no-deps "$RELEASE/python/asr"         && "$VENV/bin/python" -c "import faster_whisper, mofaregh_asr"; then
        echo "python: ASR ready (model is downloaded on the first transcription)"
        # Whisper models live beside the virtualenv, in a folder this user creates and therefore can write.
        # Models a previous release already downloaded into ~/.cache are copied over instead of downloaded again.
        MODELS="$APP_DIR/shared/asr-models"
        if mkdir -p "$MODELS/hub" && [ -w "$MODELS" ]; then
            grep -q '^ASR_MODEL_DIR=' "$APP_DIR/shared/.env" || echo "ASR_MODEL_DIR=$MODELS" >> "$APP_DIR/shared/.env"
            for cached in "$HOME"/.cache/huggingface/hub/models--Systran--faster-whisper-*; do
                [ -d "$cached" ] && [ ! -e "$MODELS/hub/$(basename "$cached")" ] && cp -a "$cached" "$MODELS/hub/" || true
            done
        else
            echo "WARN python: cannot write $MODELS; Whisper models stay in ~/.cache/huggingface."
        fi
    else
        echo "WARN python: ASR (faster-whisper) did not install; automatic drafts will fail until it does."
    fi
}
setup_python || echo "WARN python: setup failed, continuing."

# A snapshot of the database before any migration touches it (shared/backups, kept 14 days).
grep -q '^BACKUP_DIR=' "$APP_DIR/shared/.env" || echo "BACKUP_DIR=$APP_DIR/shared/backups" >> "$APP_DIR/shared/.env"
BACKUP_DIR="$APP_DIR/shared/backups" php artisan mofaregh:backup --reason=pre-deploy || echo "WARN backup: pre-deploy snapshot failed."

php artisan migrate --force --no-interaction
php artisan optimize

# Health of the automatic draft, in the deploy log: Python, packages, memory, scheduler, queue, stalled or failed
# drafts, and a real three-second transcription with the "tiny" model. Never stops the deploy.
timeout 900 php artisan mofaregh:asr-check --run || echo "WARN asr-check did not finish."

ln -sfn "$RELEASE" "$APP_DIR/current.tmp"
mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"

sudo /usr/bin/systemctl reload "php${PHP}-fpm"

# A queue worker started by the scheduler keeps the old code in memory: ask it to stop after its current job.
php "$APP_DIR/current/artisan" queue:restart >/dev/null

# Keep the five newest releases.
cd "$APP_DIR/releases"
ls -1dt -- */ | tail -n +6 | xargs -r rm -rf --

echo "Live: $RELEASE"
