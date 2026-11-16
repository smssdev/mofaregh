# Deploying Mofaregh

Staging runs at **https://mofaregh.smszs.tech** on the Hostinger VPS that also hosts orbit, hafiz and moalem. The
server is managed by CloudPanel, and the site is set up the same way as `orbit.smszs.tech`.

Every push to `main` whose CI passes is deployed by [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml):
GitHub Actions installs Composer packages (no dev), builds the Vue app into `apps/api/public/spa`, uploads `apps/api`
to a new directory under `releases/`, and pipes [`activate.sh`](activate.sh) over SSH, which links the shared files,
migrates, caches and switches the `current` symlink in one step. It can also be run by hand from the Actions tab.

## Server layout

```
/home/mofaregh/htdocs/mofaregh.smszs.tech/
  releases/<timestamp>-<sha>/   one per deploy (the five newest are kept)
  current -> releases/...       the live release
  public  -> current/public     what CloudPanel's vhost serves
  shared/.env                   production settings (APP_KEY lives here)
  shared/database.sqlite        the database
  shared/storage/               logs, sessions, uploads in progress, audio (storage/app/audio)
```

## How it was set up (one time)

1. `clpctl site:add:php --domainName=mofaregh.smszs.tech --phpVersion=8.4 --vhostTemplate='Laravel 12' --siteUser=mofaregh --siteUserPassword=…`
2. Vhost: the backend listens on `127.0.0.1:8093` and the HTTPS server proxies to it (`proxy_pass http://127.0.0.1:8093;`).
   The template's default 8080 is taken by the `legends-app` container on this server; orbit uses 8091 and moalem
   8092. The same change was saved in CloudPanel's own record, so editing the vhost in the panel keeps it.
3. `releases/`, `shared/` (`.env` with a fresh `APP_KEY`, `storage/`, `database.sqlite`) and `public` as a symlink to
   `current/public`.
4. The `mofaregh` user has a `github_actions` key in its `authorized_keys`, may run
   `sudo systemctl reload php8.4-fpm` (`/etc/sudoers.d/mofaregh-deploy`), and runs the scheduler from its crontab
   (`* * * * * cd …/current && php artisan schedule:run`). The scheduler also drains the queue every minute, which
   is where ffmpeg normalizes uploads.
5. GitHub: secrets `SSH_HOST`, `SSH_USER` (`mofaregh`), `SSH_KEY`, and the repository variable
   `APP_DIR` = `/home/mofaregh/htdocs/mofaregh.smszs.tech`.
6. DNS: an `A` record `mofaregh` → the VPS address, in hPanel (the zone for `smszs.tech` is on Hostinger's DNS).
7. HTTPS: `clpctl lets-encrypt:install:certificate --domainName=mofaregh.smszs.tech` once DNS points at the server.
8. First admin, after the first deploy:
   `sudo -u mofaregh php /home/mofaregh/htdocs/mofaregh.smszs.tech/current/artisan mofaregh:create-admin <email>`
   prints a generated password once.

## Day to day

| Task | How |
|---|---|
| Change settings | Edit `shared/.env`, then `cd current && php artisan optimize` |
| Logs | `shared/storage/logs/` and `/home/mofaregh/logs/` (nginx, PHP) |
| Queue stuck? | `cd current && php artisan queue:work --stop-when-empty` to drain by hand; failed jobs: `php artisan queue:failed` |
| Roll back | `ln -sfn …/releases/<older> …/current && sudo systemctl reload php8.4-fpm` (migrations are not undone) |
| Back up | `shared/database.sqlite` and `shared/storage/app/audio/` |
| Reset an admin password | `php artisan mofaregh:create-admin <email> --reset` |

## Limits to know

- Audio sits on the VPS disk (about 15 GB free at setup). Move `AUDIO_DISK` to S3-compatible storage before real
  volumes arrive.
- SQLite is fine for a staging team; switch `DB_CONNECTION` to PostgreSQL/MySQL before production load.
- Python: every deploy copies `services/text-engine` and `services/asr` into the release (`python/`) and
  `activate.sh` installs them into `shared/venv`, then writes `MOFAREGH_PYTHON` to `shared/.env` once. It needs
  `python3` (3.10+) and `python3-venv` on the server; if they are missing the deploy still succeeds and its log
  shows a `WARN python:` line, and the editor says the feature is off.
- ASR is Whisper on the CPU (`ASR_MODEL=medium`, `ASR_THREADS=2` in `shared/.env`). The model (about 1.5 GB) is
  downloaded by the first transcription and needs about 2 GB of memory while it runs; an hour of audio can take
  about as long as the audio itself. One file is transcribed at a time by the scheduler's `asr` queue worker.
