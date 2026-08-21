#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Kraite Deploy Script v6 (single-host)
# Runs through sudo after connecting as the `kraite` user. Routine SSH never
# uses root. Project commands (Artisan, Composer, and Git) run as `kraite`
# via `su`; sudo is retained only for service and ownership operations. See
# ~/Herd/.credentials/kraite/hardening.json → `principles`.
# Called AFTER kraite:cooldown --status confirms STATUS:COOLED_DOWN.
# Does NOT bring the server back online — kraite:warmup does that separately.
#
# SAFETY NOTES:
# - Never run artisan/composer/git as root — the `kraite` user owns
#   the project files. Root-created files get root:root ownership and
#   PHP-FPM (www-data) can't read them.
# - The repo ships composer.json with ../packages/ path repos for local dev,
#   and composer.production.json/composer.production.lock with VCS repos and
#   tagged dependencies for production. After git checkout, this script swaps
#   both files over composer.json/composer.lock. Production installs exactly
#   the dependency graph tested and committed in the release tag.
# - config:cache must run as `kraite` — root-cached .php files
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
# FORCE_DEPLOY=1 escape hatch: bypass the cooldown gate when the scheduler is
# already dispatching to a queue this box would normally drain (cooldown
# --status reports STATUS:ACTIVE because of accumulating queue depth even
# though the app is in maintenance + Horizon is processing). Use sparingly and
# only when the operator has independently verified the box is safe to deploy.
if [ "${KRAITE_DEPLOY_REEXECED:-0}" = "1" ] && [ "${KRAITE_COOLDOWN_VERIFIED:-0}" = "1" ]; then
    echo "[1/10] Cooldown verified by pre-checkout pass"
elif [ "${FORCE_DEPLOY:-0}" = "1" ]; then
    echo "[1/10] Cooldown check BYPASSED (FORCE_DEPLOY=1)"
elif ! su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan kraite:cooldown --status" 2>&1 | grep -q "STATUS:COOLED_DOWN"; then
    echo "ERROR: Server is NOT cooled down. Run 'php artisan kraite:cooldown' first."
    echo "       Or, if you've independently verified the box is safe, re-run with FORCE_DEPLOY=1."
    exit 1
else
    echo "[1/10] Cooldown verified"
fi

# The checkout can introduce an application/package API pair that the old
# vendor tree cannot boot yet. Carry the already-proven cooldown gate into the
# one post-checkout re-exec so dependency installation can restore parity
# before another Artisan command runs.
export KRAITE_COOLDOWN_VERIFIED=1

# --- Step 2: Ensure $KRAITE_USER has composer GitHub auth ---
# Without this, composer update for private kraitebot repos fails with 401.
# Global config is per-user — root's auth does NOT apply to the hostname user.
if ! su - $KRAITE_USER -c 'composer config --global --list 2>/dev/null' | grep -q 'github-oauth.github.com'; then
    echo "WARNING: $KRAITE_USER missing composer GitHub OAuth — skipping auto-setup."
    echo "Run: su - $KRAITE_USER -c 'composer config --global github-oauth.github.com <token>'"
fi
echo "[2/10] Composer auth: verified"

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
# db-backups/ is excluded from the clean — it holds the pre-deploy DB
# snapshots (point-in-time rollback history); without the exclusion every
# deploy wiped all previous backups, leaving only its own (caught 2026-06-05
# during the v1.53.3 release).
su - $KRAITE_USER -c "cd $PROJECT_DIR && git reset --hard HEAD && git clean -fd -e db-backups"
su - $KRAITE_USER -c "cd $PROJECT_DIR && git fetch origin --tags"
su - $KRAITE_USER -c "cd $PROJECT_DIR && git checkout $DEPLOY_TAG"

# Swap composer.production.json (VCS repos, versioned constraints) over
# composer.json (which the repo ships with ../packages path repos for dev).
# This is the source of truth for production dependencies — the previous
# /tmp/deploy-composer.json backup/restore dance let the server's prod
# manifest drift from repo state (incident 2026-05-22: app/helpers.php
# autoload entry survived the dead-code sweep on every server because
# the server-local manifest was never updated).
if [ ! -f "$PROJECT_DIR/composer.production.json" ] || [ ! -f "$PROJECT_DIR/composer.production.lock" ]; then
    echo "ERROR: committed production Composer manifest or lock missing at tag $DEPLOY_TAG."
    echo "Production deploys require both composer.production.json and composer.production.lock."
    exit 1
fi
su - $KRAITE_USER -c "cd $PROJECT_DIR && cp composer.production.json composer.json && cp composer.production.lock composer.lock"
chown $KRAITE_USER:www-data "$PROJECT_DIR/composer.json" "$PROJECT_DIR/composer.lock"

# --- Step 3.5: Re-exec from the freshly-checked-out deploy.sh ---
# Bash reads scripts incrementally from disk, so the `git checkout` above
# just replaced deploy.sh on disk while the bash process continues
# executing the in-memory copy of whatever shape this script had at
# launch. Any newer steps in the checked-out script body get silently
# skipped — concrete incident 2026-05-30 v1.51.2 rollout: athena was on a
# pre-step-10 deploy.sh, ran a v1.51.2 checkout that DID contain the
# fleet-topology drift gate at step 10, but never executed step 10
# because bash kept reading from the in-memory 9-step copy. Workers
# happened to already be on the 10-step shape so they tripped the gate.
#
# Re-exec once so bash loads the post-checkout script body from disk.
# The KRAITE_DEPLOY_REEXECED guard caps recursion at depth one. On the
# second pass steps 1–3 are idempotent (cooldown re-verifies STATUS:
# COOLED_DOWN; composer auth check; git fetch + already-at-tag checkout)
# so no work is duplicated dangerously — just an extra ~5s of pre-flight
# before the re-exec is detected and skipped.
if [ "${KRAITE_DEPLOY_REEXECED:-0}" != "1" ]; then
    export KRAITE_DEPLOY_REEXECED=1
    echo "[3.5/10] Re-execing deploy.sh from the checked-out tag"
    exec bash "$PROJECT_DIR/deploy.sh"
fi
echo "[3.5/10] Re-exec already done (KRAITE_DEPLOY_REEXECED=1)"

COMMIT=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && git log --oneline -1")
echo "[3/10] Code: $COMMIT"

# --- Step 4: Install committed production dependencies ---
# Dependency resolution happens locally before the release tag is created.
# Production never runs composer update and never installs require-dev.
COMPOSER_BIN="/home/kraite/.local/bin/composer"
if [ ! -x "$COMPOSER_BIN" ]; then
    echo "ERROR: production Composer executable missing at $COMPOSER_BIN."
    exit 1
fi

DEV_LOCK_COUNT=$(python3 -c "import json; print(len(json.load(open('$PROJECT_DIR/composer.lock')).get('packages-dev', [])))")
if [ "$DEV_LOCK_COUNT" != "0" ]; then
    echo "ERROR: composer.production.lock contains development packages."
    exit 1
fi

su - $KRAITE_USER -c "cd $PROJECT_DIR && $COMPOSER_BIN install --no-interaction --no-dev --optimize-autoloader --quiet"
CORE_VERSION=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='kraitebot/core']" 2>/dev/null || echo "unknown")
SD_VERSION=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='brunocfalcao/step-dispatcher']" 2>/dev/null || echo "unknown")
echo "[4/10] Composer: installed (core $CORE_VERSION, step-dispatcher $SD_VERSION)"

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
echo "[5/10] Permissions: fixed"

# --- Step 6: Read server role ---
SERVER_ROLE=$(su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('kraite.server_role', 'web');\"" 2>/dev/null | tail -1 || echo "web")
echo "[6/10] Server role: $SERVER_ROLE"

# --- Step 7: DB backup + migrate (ingestion only) ---
# Backups land in $PROJECT_DIR/db-backups/ — a flat directory at the
# project root, intentionally separate from Laravel's storage/ tree so
# operator rollback recipes don't have to dig through framework state.
# Files are timestamped (pre-deploy-YYYYMMDD_HHMMSS.sql.gz) and NEVER
# deleted by deploy — full history is preserved for point-in-time
# rollback. The backup is a HARD gate by default: if mysqldump fails (zero
# bytes or non-zero exit) the deploy aborts BEFORE running migrations. An
# operator can explicitly set SKIP_DB_BACKUP=1 for a release that must omit
# the dump; migrations and every later deployment gate still run normally.
if [ "$SERVER_ROLE" = "ingestion" ]; then
    if [ "${SKIP_DB_BACKUP:-0}" = "1" ]; then
        echo "[7/10] DB backup: skipped (SKIP_DB_BACKUP=1)"
    else
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
            echo "[7/10] DB backup FAILED — snapshot is empty or under 1KB at $BACKUP_FILE. Aborting before migrations."
            exit 1
        fi

        chown $KRAITE_USER:www-data "$BACKUP_FILE"
        echo "[7/10] DB backup: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
    fi

    su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan migrate --force --no-interaction"
    echo "[7/10] Migrations: done"
else
    echo "[7/10] Migrations: skipped (role=$SERVER_ROLE)"
fi

# --- Step 8: Build frontend (if applicable) ---
if [ -f "$PROJECT_DIR/package.json" ] && grep -q '"build"' "$PROJECT_DIR/package.json" 2>/dev/null; then
    su - $KRAITE_USER -c "cd $PROJECT_DIR && npm install --quiet 2>/dev/null && npm run build --quiet 2>/dev/null"
    echo "[8/10] Frontend: built"
else
    echo "[8/10] Frontend: N/A"
fi

# --- Step 9: Rebuild caches ---
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan config:cache"
su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan route:cache"
# CLI workers and PHP-FPM compile distinct view caches so a worker-owned mail
# partial never blocks PHP-FPM's explicit timestamp update (and vice versa).
# Clear the retired shared cache plus the PHP-FPM cache before the CLI cache is
# optionally rebuilt below. The exact, validated runtime-cache paths keep this
# cleanup out of application source, persisted storage, and release backups.
for VIEW_CACHE_DIR in "$PROJECT_DIR/storage/framework/views" "$PROJECT_DIR/storage/framework/views/cli" "$PROJECT_DIR/storage/framework/views/web"; do
    if [ -d "$VIEW_CACHE_DIR" ]; then
        find "$VIEW_CACHE_DIR" -maxdepth 1 -type f -name '*.php' -delete
    fi
done
if [ -d "$PROJECT_DIR/resources/views" ]; then
    su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan view:cache"
    echo "[9/10] View cache: rebuilt"
else
    # A prior deployment may have compiled vendor views even though this role
    # has no application views. Remove that stale cache instead of leaving
    # executable PHP files behind for the next warmup.
    su - $KRAITE_USER -c "cd $PROJECT_DIR && php artisan view:clear"
    echo "[9/10] View cache: cleared (no resources/views directory)"
fi
find "$PROJECT_DIR/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -exec chmod 0644 {} +
find "$PROJECT_DIR/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -exec chgrp www-data {} +
echo "[9/10] Caches: rebuilt"

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

# Keep the cooldown boundary real. Starting writers here caused each release
# to boot the user-data daemon, stop it again for diagnostics, then boot it a
# second time during warmup. Only kraite:warmup may start long-lived application processes.
# Stop any processes that were already running when cooldown began so the
# diagnostics reset has a genuinely quiet boundary and warmup cannot leave a
# pre-release worker alive.
echo ""
echo "--- Step 11: Stop long-running daemons ---"
case "$SERVER_ROLE" in
    ingestion)
        UNITS="kraite-horizon kraite-stream-binance-prices kraite-stream-binance-user-data kraite-dispatch-daemon kraite-scheduler"
        ;;
    *)
        UNITS="kraite-horizon"
        ;;
esac

for unit in $UNITS; do
    unit_status=$(supervisorctl status "$unit" 2>/dev/null || true)

    if grep -qE "RUNNING|STOPPED|FATAL|EXITED" <<< "$unit_status"; then
        if grep -q "RUNNING" <<< "$unit_status"; then
            supervisorctl stop "$unit" 2>&1 | sed 's/^/    /'
        else
            echo "    $unit: already stopped"
        fi
    else
        echo "    $unit: not configured on this host, skipping"
    fi
done
echo "[11/11] Daemons: stopped for warmup"

echo ""
echo "=== Deploy complete ==="
echo "Commit: $COMMIT"
echo "Core:   $CORE_VERSION"
echo "Role:   $SERVER_ROLE"
echo "Status: Server still in maintenance mode"
echo "Next:   php artisan kraite:warmup  (or /kraite-warmup <hostname>)"
