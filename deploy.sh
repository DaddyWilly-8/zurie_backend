#!/usr/bin/env bash
#
# Zuriè backend — one-command production deploy.
#
# Run this from the deployed working copy on the server (SSH, or a cPanel
# Git Version Control post-pull hook pointed at this script). It is safe to
# re-run: every step is idempotent, and `set -euo pipefail` means the first
# failing step stops the whole deploy immediately rather than leaving the
# app half-upgraded.
#
# See docs/RUNBOOK.md for what to do if a step here fails mid-deploy.

set -euo pipefail
cd "$(dirname "$0")"

log() { echo "[deploy] $*"; }
fail() { echo "[deploy] ERROR: $*" >&2; exit 1; }

# --- Locate PHP -------------------------------------------------------
# cPanel exposes each PHP version under its own binary name (ea-php84,
# ea-php83, ...) rather than a bare `php` on PATH. PHP_BIN can be set
# explicitly (e.g. `PHP_BIN=ea-php84 ./deploy.sh`) to skip this search.
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for candidate in ea-php84 ea-php83 ea-php82 php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi
[ -n "$PHP_BIN" ] || fail "no PHP binary found (tried ea-php84, ea-php83, ea-php82, php). Set PHP_BIN explicitly."
log "Using PHP: $(command -v "$PHP_BIN") ($($PHP_BIN -v | head -1))"

# --- Locate Composer ---------------------------------------------------
# Shared hosting often only has composer.phar sitting in the project root,
# not a global `composer` on PATH.
if command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD=(composer)
elif [ -f composer.phar ]; then
  COMPOSER_CMD=("$PHP_BIN" composer.phar)
else
  fail "no composer found (tried \`composer\` on PATH and ./composer.phar)."
fi

# --- Pull latest -------------------------------------------------------
log "Fetching latest main..."
git fetch origin main
git reset --hard origin/main

# --- Dependencies --------------------------------------------------------
log "Installing PHP dependencies (production, no dev)..."
"${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --no-interaction

# --- Migrate -------------------------------------------------------------
# Runs before the cache rebuild below so a failure here (the step most
# likely to fail) stops before any cache references the new code with an
# unmigrated database underneath it.
#
# AUTO_MIGRATE=0 turns this into a code-only deploy: the schema is left
# completely untouched and pending migrations are only *reported*. The
# automatic deploy workflow uses this, so no push ever alters the live
# database unattended — you run `migrate --force` yourself when a change
# needs it. A hand-run `./deploy.sh` keeps AUTO_MIGRATE=1 (the default) and
# migrates as before.
if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
  log "Running database migrations..."
  "$PHP_BIN" artisan migrate --force
else
  log "AUTO_MIGRATE=0 — skipping migrations (code-only deploy)."
  if "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -qi "pending"; then
    log "WARNING: pending database migrations were NOT applied."
    log "WARNING: run \`$PHP_BIN artisan migrate --force\` on the server — the API may error until you do."
  else
    log "No pending migrations."
  fi
fi

# --- Storage symlink (idempotent — safe if it already exists) -----------
"$PHP_BIN" artisan storage:link >/dev/null 2>&1 || true

# --- Rebuild caches --------------------------------------------------------
# No view:cache here — this is a JSON-API-only app with no resources/views
# directory, and `artisan view:cache` throws (not a no-op) when that
# directory doesn't exist, which would abort the whole deploy right here
# under `set -euo pipefail`.
log "Rebuilding config/route/event caches..."
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan event:cache

# --- Restart queue workers -------------------------------------------------
# Picks up the new code on the next job instead of running stale workers
# against a codebase that's already moved on.
"$PHP_BIN" artisan queue:restart

log "Deploy complete. Now at $(git rev-parse --short HEAD) ($(git log -1 --format=%s))."
