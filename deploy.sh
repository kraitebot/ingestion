#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Kraite Deploy Script v5 (per-hostname-user)
# Runs ON the server as ROOT (SSH as root).
# Project commands (artisan, composer, git) run as the hostname-named user
# via `su` (e.g. on athena → `su - athena`, on eos → `su - eos`). The
# 2026-05-23 hardening principle replaced the old single-`waygou` user with
# a sudo user matching each server's hostname. See
# ~/Herd/.credentials/kraite/hardening.json → `principles`.
# Called AFTER kraite:cooldown --status confirms STATUS:COOLED_DOWN.
# Does NOT bring the server back online — kraite:warmup does that separately.
#
# SAFETY NOTES:
# - Never run artisan/composer/git as root — the hostname-named user owns
#   the project files. Root-created files get root:root ownership and
#   PHP-FPM (www-data) can't read them.
# - The repo ships composer.json with ../packages/ path repos for local dev,
#   and composer.production.json with VCS repos + versioned constraints for
#   production. After git checkout, this script swaps composer.production.json
#   over composer.json so the resolved manifest is the one tracked in git for
#   the deployed tag. End of server-local composer.json drift.
# - config:cache must run as the hostname user — root-cached .php files
#   block PHP-FPM.
# - SERVER_ROLE is read from artisan AFTER reset, not from .env BEFORE reset,
#   because .env survives the reset (gitignored) but composer.json does not.
# =============================================================================

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
KRAITE_USER="$(hostname)"

echo "=== Kraite Deploy ==="
echo "Host: $(hostname)"
echo "Runner: $(whoami)"
echo "Role: ${SERVER_ROLE:-unknown}"
echo "Path: $PROJECT_DIR"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# --- Step 1: Verify cooldown ---
# FORCE_DEPLOY=1 escape hatch: bypass the cooldown gate when athena's scheduler
# is already dispatching to a queue this box would normally drain (cooldown
# --status reports STATUS:ACTIVE because of accumulating queue depth even
# though the app is in maintenance + Horizon is processing). Use sparingly —
# only when the operator has independently verified the box is safe to deploy
# (e.g., during the v1.49.8 release flow where workers showed STATUS:ACTIVE
# while still in maintenance mode because athena's resumed scheduler was
# filling the queue faster than Horizon drained it).
if [ "${FORCE_DEPLOY:-0}" = "1" ]; then
    echo "[1/9] Cooldown check BYPASSED (FORCE_DEPLOY=1)"
elif ! su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan kraite:cooldown --status" 2>&1 | grep -q "STATUS:COOLED_DOWN"; then
    echo "ERROR: Server is NOT cooled down. Run 'php artisan kraite:cooldown' first."
    echo "       Or, if you've independently verified the box is safe, re-run with FORCE_DEPLOY=1."
    exit 1
else
    echo "[1/9] Cooldown verified"
fi

# --- Step 2: Ensure $KRAITE_USER has composer GitHub auth ---
# Without this, composer update for private kraitebot repos fails with 401.
# Global config is per-user — root's auth does NOT apply to the hostname user.
if ! su - $KRAITE_USER -c 'composer config --global --list 2>/dev/null' | grep -q 'github-oauth.github.com'; then
    echo "WARNING: $KRAITE_USER missing composer GitHub OAuth — skipping auto-setup."
    echo "Run: su - $KRAITE_USER -c 'composer config --global github-oauth.github.com <token>'"
fi
echo "[2/9] Composer auth: verified"

# --- Step 3: Pull latest code (by TAG, not branch HEAD) ---
# deploy.sh expects $DEPLOY_TAG to be set by the caller. If missing, abort.
# This guarantees the server runs a pinned, CI-verified version — never
# whatever happens to be on master (which may have untested commits).
if [ -z "${DEPLOY_TAG:-}" ]; then
    echo "ERROR: DEPLOY_TAG is not set. Pass it as: DEPLOY_TAG=v1.37.1 bash deploy.sh"
    echo "The server MUST deploy a specific tagged version, not branch HEAD."
    exit 1
fi

# Reset to HEAD first to clean any dirty index state (staged changes from
# prior composer update, migration cruft, etc.) that would block the checkout.
su - $KRAITE_USER -c "cd $PROJECT_DIR && git reset --hard HEAD && git clean -fd"
su - $KRAITE_USER -c "cd $PROJECT_DIR && git fetch origin --tags"
su - $KRAITE_USER -c "cd $PROJECT_DIR && git checkout $DEPLOY_TAG"

# Swap composer.production.json (VCS repos, versioned constraints) over
# composer.json (which the repo ships with ../packages path repos for dev).
# This is the source of truth for production dependencies — the previous
# /tmp/deploy-composer.json backup/restore dance let the server's prod
# manifest drift from repo state (incident 2026-05-22: app/helpers.php
# autoload entry survived the dead-code sweep on every server because
# the server-local manifest was never updated).
if [ ! -f "$PROJECT_DIR/composer.production.json" ]; then
    echo "ERROR: composer.production.json missing at tag $DEPLOY_TAG."
    echo "Production deploys swap composer.production.json over composer.json."
    echo "Add the file to the repo and re-tag, or fall back to v1.49.5 (pre-swap deploy.sh)."
    exit 1
fi
su - $KRAITE_USER -c "cd $PROJECT_DIR && cp composer.production.json composer.json"
chown $KRAITE_USER:www-data "$PROJECT_DIR/composer.json"

COMMIT=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && git log --oneline -1")
echo "[3/9] Code: $COMMIT"

# --- Step 4: Install + update dependencies ---
# Order matters: composer update (4 path packages) runs BEFORE composer install.
#
# Why this order? The shipped composer.lock comes from a dev environment
# where the four kraite-owned packages resolve via local path repos and
# get locked as `dev-master`. Only `kraitebot/core` carries a
# `branch-alias` (`dev-master → 1.x-dev`) in its composer.json — the
# three brunocfalcao packages do not, so their `dev-master` lock entry
# does NOT satisfy the production constraints `^6.0` / `^1.12` / `^1.0`.
# That means `composer install` against the shipped lock aborts with
# "Required package … is in the lock file as dev-master but that does
# not satisfy your constraint …" — exactly the failure that bit the
# v1.49.1 athena deploy on 2026-05-16.
#
# Calling `composer update <named-packages>` first sidesteps the lock
# validation for the unnamed packages and regenerates the four entries
# with their latest tagged versions (resolved via the production VCS
# repos, since composer.json was already swapped to the prod manifest).
# Once those four are pinned to real tags, the lock matches every
# composer.json constraint and the subsequent `composer install` is a
# clean no-op against the freshly-rewritten lock.
#
# 2026-05-13 v1.40.1 incident — kept here for the record: the previous
# form named only kraitebot/core + brunocfalcao/step-dispatcher; deploy
# ran clean but the resulting lock kept all four kraite-owned packages
# on dev-master across every server until a manual
# `composer update <all four>` was issued per host. List all four every
# time — partial updates leave unnamed packages on dev-master and
# cross-references then block the named ones too.
su - $KRAITE_USER -c "cd $PROJECT_DIR && composer update kraitebot/core brunocfalcao/step-dispatcher brunocfalcao/blade-feather-icons brunocfalcao/laravel-helpers --no-interaction --no-dev"
su - $KRAITE_USER -c "cd $PROJECT_DIR && composer install --no-interaction --no-dev --optimize-autoloader --quiet"
CORE_VERSION=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='kraitebot/core']" 2>/dev/null || echo "unknown")
SD_VERSION=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='brunocfalcao/step-dispatcher']" 2>/dev/null || echo "unknown")
echo "[4/9] Composer: installed (core $CORE_VERSION, step-dispatcher $SD_VERSION)"

# HARD RULE: no dev-master on production. Verify no packages resolved to dev-*.
DEV_PKGS=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "
import json,sys
d=json.load(sys.stdin)
devs=[f\"{p['name']}: {p['version']}\" for p in d['packages'] if p['version'].startswith('dev-')]
if devs: print('\n'.join(devs))
" 2>/dev/null || true)
if [ -n "$DEV_PKGS" ]; then
    echo "ERROR: dev-master packages detected in production!"
    echo "$DEV_PKGS"
    echo "Fix the version constraints in composer.json. Aborting."
    exit 1
fi

# --- Step 5: Fix ownership + permissions ---
# Run as root — only root can chown. Do this BEFORE artisan commands
# so PHP-FPM can read the new files.
chown -R $KRAITE_USER:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[5/9] Permissions: fixed"

# --- Step 6: Read server role ---
SERVER_ROLE=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('kraite.server_role', 'web');\"" 2>/dev/null | tail -1 || echo "web")
echo "[6/9] Server role: $SERVER_ROLE"

# --- Step 7: DB backup + migrate (ingestion only) ---
# Backups land in $PROJECT_DIR/db-backups/ — a flat directory at the
# project root, intentionally separate from Laravel's storage/ tree so
# operator rollback recipes don't have to dig through framework state.
# Files are timestamped (pre-deploy-YYYYMMDD_HHMMSS.sql.gz) and NEVER
# deleted by deploy — full history is preserved for point-in-time
# rollback. The backup is a HARD gate: if mysqldump fails (zero bytes
# or non-zero exit) the deploy aborts BEFORE running migrations, so a
# migration is never executed without a fresh, restorable snapshot.
if [ "$SERVER_ROLE" = "ingestion" ]; then
    BACKUP_DIR="$PROJECT_DIR/db-backups"
    mkdir -p "$BACKUP_DIR"
    chown $KRAITE_USER:www-data "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"

    DB_HOST=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.host');\"" 2>/dev/null | tail -1)
    DB_NAME=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.database');\"" 2>/dev/null | tail -1)
    DB_USER=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.username');\"" 2>/dev/null | tail -1)
    DB_PASS=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.password');\"" 2>/dev/null | tail -1)

    # `set -o pipefail` is already enabled at the top of this script, so a
    # mysqldump failure surfaces here as a non-zero exit even though it sits
    # on the left side of a pipe. `set -e` then aborts the whole deploy.
    #
    # Flag choices:
    #  --single-transaction → consistent snapshot without locking tables
    #  --routines           → include stored procedures/functions
    #  --triggers           → include table triggers
    #  --no-tablespaces     → skip tablespace dump; the `kraite` MySQL user
    #                         does not have the PROCESS privilege, and MySQL
    #                         8 defaults to dumping tablespaces unless this
    #                         flag is set. Without --no-tablespaces, mysqldump
    #                         errors out with "Access denied; you need the
    #                         PROCESS privilege" before writing any rows.
    #  (no --events)        → omitted because the `kraite` user lacks the
    #                         EVENT privilege, and the kraite schema does
    #                         not declare any scheduled events to capture.
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --single-transaction --routines --triggers --no-tablespaces | gzip > "$BACKUP_FILE"

    # Defence in depth — pipefail covers exec failures, but if mysqldump
    # ever succeeds-with-empty-output (e.g. permission to connect but not
    # to dump), the gzip would also "succeed" and leave a near-zero-byte
    # file. Refuse to migrate against an empty snapshot.
    if [ ! -s "$BACKUP_FILE" ] || [ "$(stat -c %s "$BACKUP_FILE" 2>/dev/null || stat -f %z "$BACKUP_FILE")" -lt 1024 ]; then
        echo "[7/9] DB backup FAILED — snapshot is empty or under 1KB at $BACKUP_FILE. Aborting before migrations."
        exit 1
    fi

    chown $KRAITE_USER:www-data "$BACKUP_FILE"
    echo "[7/9] DB backup: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

    su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan migrate --force --no-interaction"
    echo "[7/9] Migrations: done"
else
    echo "[7/9] Migrations: skipped (role=$SERVER_ROLE)"
fi

# --- Step 8: Build frontend (if applicable) ---
if [ -f "$PROJECT_DIR/package.json" ] && grep -q '"build"' "$PROJECT_DIR/package.json" 2>/dev/null; then
    su - $KRAITE_USER -c "cd $PROJECT_DIR && npm install --quiet 2>/dev/null && npm run build --quiet 2>/dev/null"
    echo "[8/9] Frontend: built"
else
    echo "[8/9] Frontend: N/A"
fi

# --- Step 9: Rebuild caches ---
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan config:cache"
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan route:cache"
# view:cache only on servers that have views (ingestion/workers don't)
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan view:cache" 2>/dev/null || true
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
chgrp www-data "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[9/9] Caches: rebuilt"

# --- Step 10: Fleet topology drift check ---
# Hard floor: assert every `config('kraite.horizon.workers')` key has a
# matching `servers.hostname` row before workers respawn. Drift here
# means StepRouter cannot translate banned IPs into the hostname that
# belongs to a config key — ban filtering silently fails for the drifted
# worker, the deactivation cascade never fires, and steps land on a
# worker that immediately re-fails the API call. Better to abort the
# deploy than ship a broken routing fabric.
#
# Runs AFTER config:cache so the cached config (the one workers actually
# read) is the one being verified. Fails with exit code 1 on drift, which
# aborts deploy.sh under `set -e`.
echo ""
echo "--- Step 10: Fleet topology check ---"
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan kraite:verify-fleet-topology --fail-on-drift --quiet-on-success"
echo "[10/10] Fleet topology: aligned"

echo ""
echo "=== Deploy complete ==="
echo "Commit: $COMMIT"
echo "Core:   $CORE_VERSION"
echo "Role:   $SERVER_ROLE"
echo "Status: Server still in maintenance mode"
echo "Next:   php artisan kraite:warmup  (or /kraite-warmup <hostname>)"
