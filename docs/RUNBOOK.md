# Zuriè Backend — Operations Runbook

Practical steps for deploying, rolling back, reading logs, and restoring a
backup. See [`../CLAUDE.md`](../CLAUDE.md) and
[`ARCHITECTURE_GUIDE.md`](ARCHITECTURE_GUIDE.md) for how the system is built;
this document is only about running it.

## Deploy

```bash
./deploy.sh
```

One command, safe to re-run. It pulls `origin/main`, installs production
Composer dependencies, runs migrations, rebuilds the config/route/event
caches, and restarts queue workers. `set -euo pipefail` means the first
failing step stops the whole deploy — it never leaves the app half-upgraded
(new code with stale caches, or a route cache built against an unmigrated
database).

If the server's `php` isn't on PATH under that name (cPanel exposes
`ea-php84`, `ea-php83`, etc.), the script auto-detects it; override with
`PHP_BIN=ea-php84 ./deploy.sh` if detection picks the wrong one. Same for
Composer: it uses `composer` if present, else `./composer.phar`.

**Before the first deploy on a new server**, `composer.phar` must already
exist in the project root (or a global `composer` on PATH), and `.env` must
be filled in by hand — `deploy.sh` never touches `.env`, and `git reset
--hard` never discards it (`.env` is gitignored, so it's untracked and
`reset --hard` only touches tracked files).

### If a deploy step fails

| Step failed | What happened | What to do |
|---|---|---|
| `git fetch`/`reset` | Network or auth issue reaching GitHub | Check connectivity/deploy key, re-run `./deploy.sh` |
| `composer install` | A dependency failed to resolve/install | Re-run; if it persists, check `composer.lock` was committed and matches `composer.json` |
| `artisan migrate --force` | A migration has a bug, or the DB is in an unexpected state | **Do not re-run blindly.** Run `php artisan migrate:status` to see which migration failed, fix or write a corrective migration, then re-run. The app is still serving the *old* code's routes/config at this point (cache rebuild hasn't happened yet), so there's no user-facing outage yet — just an unmigrated DB behind newly-pulled code. See "Roll back" below if you need to buy time. |
| `queue:restart` | Rare — the queue connection itself is down | Investigate the queue connection (Redis/database); jobs queue up safely in the meantime, nothing is lost |

### Roll back

There is no separate rollback script — rolling back is the same `deploy.sh`
pointed at an older commit:

```bash
git fetch origin
git reset --hard <previous-good-commit-or-tag>
./deploy.sh   # or repeat its steps manually from this point
```

If the bad deploy included a migration that must also be reversed:

```bash
php artisan migrate:rollback --step=1   # only if the migration is safely reversible
```

Check the migration's `down()` method first — not every migration in this
codebase has one that's safe to run against live data (see
[`CLAUDE.md`](../CLAUDE.md) §6 for the ledger/stock invariants that make some
migrations one-way in practice).

### Automatic deploy (push to main → live), code-only

`.github/workflows/deploy.yml` deploys the API automatically after the CI
test suite passes on `main`: it SSHes into the server and runs
`AUTO_MIGRATE=0 ./deploy.sh` — a **code-only** deploy that pulls, installs
(`--no-dev`), and rebuilds caches but **never touches the database**. It
runs only for a **green** CI run; you can also trigger it from **Actions →
Deploy (backend, code-only) → Run workflow**.

**Migrations stay manual, on purpose.** When a merged change adds a
migration, the deploy log prints a `WARNING: pending database migrations`
line — run it yourself when you're ready:

```bash
ea-php84 artisan migrate --force
```

Deploy the schema *before or right after* the code lands, depending on
whether the change is backwards-compatible. A hand-run `./deploy.sh` (no
`AUTO_MIGRATE=0`) still migrates as part of the deploy, for when you want the
whole thing in one step.

One-time setup — the same four secrets as the storefront repo (add them to
**this** repo too, under Settings → Secrets and variables → Actions):

1. Reuse the deploy key you authorized on the server for the storefront (or
   authorize a second one).
2. Add repo secrets `DEPLOY_SSH_HOST`, `DEPLOY_SSH_PORT`, `DEPLOY_SSH_USER`,
   `DEPLOY_SSH_KEY`. Optional var `DEPLOY_APP_DIR` if the checkout isn't at
   `~/api.zurie.co.tz_backend`.

Until `DEPLOY_SSH_HOST` is set, the deploy job just logs a note and passes,
so merging this is safe before setup. If the host firewalls SSH by IP, keep
deploying with `./deploy.sh` by hand.

## Health check

```
GET /up
```

Laravel's built-in health-check route (configured in `bootstrap/app.php`).
Returns 200 when the app has booted and can serve requests. Point uptime
monitoring (or a load balancer health probe) at this — it does **not**
currently check the database connection or queue; it only proves the PHP
process and routing layer are alive. If you need a DB-aware check, extend
`Illuminate\Foundation\Configuration\Middleware`'s health check closure in
`bootstrap/app.php` rather than adding a second endpoint.

## Error monitoring (Sentry)

The SDK (`sentry/sentry-laravel`) is already wired into
`bootstrap/app.php` (`\Sentry\Laravel\Integration::handles($exceptions)`) and
is a safe no-op with no DSN configured — every environment, local included,
can leave it wired without side effects.

**To activate it:**

1. Create a Sentry project (Laravel platform) and copy its DSN.
2. Set in the server's `.env` (never commit this):
   ```
   SENTRY_LARAVEL_DSN=https://<key>@<org>.ingest.sentry.io/<project>
   SENTRY_ENVIRONMENT=production
   ```
3. `php artisan config:cache` (or just redeploy — `deploy.sh` does this).
4. Prove it's working:
   ```bash
   php artisan sentry:test
   ```
   This sends one test event and prints confirmation. Check the Sentry
   project's Issues tab — the event should appear within seconds. Without a
   DSN configured, this command fails loudly with "Could not discover DSN!"
   rather than silently doing nothing, so a misconfigured deploy is obvious
   immediately.

## Logs

```bash
tail -f storage/logs/laravel.log
```

Standard Laravel daily/single log, depending on `LOG_CHANNEL`. With Sentry
active, uncaught exceptions also show up there — the log is still the
source of truth for anything Sentry's sampling might have dropped, or for
debug-level detail Sentry doesn't capture.

## Backups

- **Nightly backup**: `php artisan backup:database` — dumps the full
  database to a timestamped `.sql` file under
  `storage/app/private/backups/`, pruning old ones. Wire this into the
  server's cron (see `routes/console.php` / the scheduler if already
  scheduled there).
- **Weekly restore verification**: `php artisan backup:verify-restore` —
  restores the most recent dump into a scratch database, compares every
  table's row count against the live database, and runs
  `finance:reconcile` against the restored copy so a "successful" backup
  is also proven to be a *trustworthy* one, not just present. Never touches
  the live database. On shared hosting where the app's DB user can't
  `CREATE DATABASE`, set `BACKUP_VERIFY_DATABASE` in `.env` to a spare
  database created once in cPanel with full privileges granted to the
  app's user — the command then restores into that database and empties
  it afterward instead of creating/dropping its own.

### Restoring for real (disaster recovery, not verification)

1. Stop the app from accepting writes if at all possible (maintenance mode:
   `php artisan down`).
2. Pick the backup file: `storage/app/private/backups/backup-<date>.sql`.
3. Restore into the **real** database (not the scratch one
   `backup:verify-restore` uses):
   ```bash
   mysql -h 127.0.0.1 -u <db_user> -p <db_database> < storage/app/private/backups/backup-<date>.sql
   ```
4. Run `php artisan finance:reconcile` against the restored database before
   bringing the app back up — confirm the books are consistent before
   customers can transact against them again.
5. `php artisan up` to leave maintenance mode.

### JetBackup (cPanel-level, whole-account backups)

JetBackup snapshots the whole cPanel account (files + database) on
whatever schedule the host configured, independent of the
`backup:database` artisan command above — it's the fallback if the
application-level backup itself is ever missing or corrupt. Restoring: from
cPanel → **JetBackup** → **Home Directory Backups** / **MySQL Backups**,
pick a snapshot, choose "Restore". This restores in place — there's no
scratch/verification step for JetBackup restores the way there is for
`backup:verify-restore`, so treat this as slower and coarser-grained
(whole-account) than the artisan commands above, not a replacement for
running them regularly.
