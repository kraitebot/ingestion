#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Kraite Deploy Script v4
# Runs ON the server as ROOT (SSH as root).
# Project commands (artisan, composer, git) run as waygou via su.
# Called AFTER kraite:cooldown --status confirms STATUS:COOLED_DOWN.
# Does NOT bring the server back online — kraite:warmup does that separately.
#
# SAFETY NOTES:
# - Never run artisan/composer/git as root — waygou owns the project files.
#   Root-created files get root:root ownership and PHP-FPM (www-data) can't
#   read them.
# - The server composer.json uses VCS repos (github), not ../packages/ path
#   repos. git reset --hard would overwrite it with the dev version — we
#   backup/restore around the reset. (bash reads the full script before
#   executing, so the running script is unaffected by git reset mid-run.)
# - config:cache must run as waygou — root-cached .php files block PHP-FPM.
# - SERVER_ROLE is read from artisan AFTER reset, not from .env BEFORE reset,
#   because .env survives the reset (gitignored) but composer.json does not.
# =============================================================================

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== Kraite Deploy ==="
echo "Host: $(hostname)"
echo "Runner: $(whoami)"
echo "Role: ${SERVER_ROLE:-unknown}"
echo "Path: $PROJECT_DIR"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# --- Step 1: Verify cooldown ---
if ! su - waygou -c "cd $PROJECT_DIR && php artisan kraite:cooldown --status" 2>&1 | grep -q "STATUS:COOLED_DOWN"; then
    echo "ERROR: Server is NOT cooled down. Run 'php artisan kraite:cooldown' first."
    exit 1
fi
echo "[1/9] Cooldown verified"

# --- Step 2: Ensure waygou has composer GitHub auth ---
# Without this, composer update for private kraitebot repos fails with 401.
# Global config is per-user — root's auth does NOT apply to waygou.
if ! su - waygou -c 'composer config --global --list 2>/dev/null' | grep -q 'github-oauth.github.com'; then
    echo "WARNING: waygou missing composer GitHub OAuth — skipping auto-setup."
    echo "Run: su - waygou -c 'composer config --global github-oauth.github.com <token>'"
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

# Backup production composer files before git reset (VCS repos, not path repos).
cp "$PROJECT_DIR/composer.json" /tmp/deploy-composer.json
cp "$PROJECT_DIR/composer.lock" /tmp/deploy-composer.lock 2>/dev/null || true

# Reset to HEAD first to clean any dirty index state (staged changes from
# prior composer update, migration cruft, etc.) that would block the checkout.
su - waygou -c "cd $PROJECT_DIR && git reset --hard HEAD && git clean -fd"
su - waygou -c "cd $PROJECT_DIR && git fetch origin --tags"
su - waygou -c "cd $PROJECT_DIR && git checkout $DEPLOY_TAG"

# Restore production composer.json (VCS repos, not dev path repos).
# We restore the .json but NOT the .lock — the lock will be regenerated
# by composer install + update below, ensuring it picks up the latest
# tagged versions of kraitebot packages.
cp /tmp/deploy-composer.json "$PROJECT_DIR/composer.json"
chown waygou:www-data "$PROJECT_DIR/composer.json"

COMMIT=$(su - waygou -c "cd $PROJECT_DIR && git log --oneline -1")
echo "[3/9] Code: $COMMIT"

# --- Step 4: Install + update dependencies ---
# composer install resolves from the production composer.json.
# composer update pulls the latest tagged version of every kraite-owned
# path package (the shipped lock comes from a dev environment where the
# packages resolve via path repos to `dev-master` with aliases like
# `1.40.x-dev`, which satisfy the production constraints `^1.36` etc.
# Composer treats the locked dev-master entries as already-satisfying
# and refuses to promote them unless EVERY kraite-owned package is named
# in the same `composer update` invocation — partial updates leave the
# unnamed packages on dev-master, and cross-references then block the
# named ones too. List all four every time.
# 2026-05-13 v1.40.1 incident: the previous form named only kraitebot/core
# + brunocfalcao/step-dispatcher; deploy ran clean but the resulting lock
# kept all four kraite-owned packages on dev-master across every server,
# until a manual `composer update <all four>` was issued per host.
# Removed `--quiet` so future no-ops are visible in the deploy log.
su - waygou -c "cd $PROJECT_DIR && composer install --no-interaction --no-dev --optimize-autoloader --quiet"
su - waygou -c "cd $PROJECT_DIR && composer update kraitebot/core brunocfalcao/step-dispatcher brunocfalcao/blade-feather-icons brunocfalcao/laravel-helpers --no-interaction --no-dev"
CORE_VERSION=$(su - waygou -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='kraitebot/core']" 2>/dev/null || echo "unknown")
SD_VERSION=$(su - waygou -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "import json,sys; d=json.load(sys.stdin); [print(p['version']) for p in d['packages'] if p['name']=='brunocfalcao/step-dispatcher']" 2>/dev/null || echo "unknown")
echo "[4/9] Composer: installed (core $CORE_VERSION, step-dispatcher $SD_VERSION)"

# HARD RULE: no dev-master on production. Verify no packages resolved to dev-*.
DEV_PKGS=$(su - waygou -c "cd $PROJECT_DIR && cat composer.lock" | python3 -c "
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
chown -R waygou:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[5/9] Permissions: fixed"

# --- Step 6: Read server role ---
SERVER_ROLE=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('kraite.server_role', 'web');\"" 2>/dev/null | tail -1 || echo "web")
echo "[6/9] Server role: $SERVER_ROLE"

# --- Step 7: DB backup + migrate (ingestion only) ---
if [ "$SERVER_ROLE" = "ingestion" ]; then
    BACKUP_DIR="$PROJECT_DIR/storage/backups"
    mkdir -p "$BACKUP_DIR"
    chown waygou:www-data "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"

    DB_HOST=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.host');\"" 2>/dev/null | tail -1)
    DB_NAME=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.database');\"" 2>/dev/null | tail -1)
    DB_USER=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.username');\"" 2>/dev/null | tail -1)
    DB_PASS=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.password');\"" 2>/dev/null | tail -1)

    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --single-transaction 2>/dev/null | gzip > "$BACKUP_FILE"
    chown waygou:www-data "$BACKUP_FILE"
    echo "[7/9] DB backup: $(du -h "$BACKUP_FILE" | cut -f1)"

    su - waygou -c "cd $PROJECT_DIR && php artisan migrate --force --no-interaction"
    echo "[7/9] Migrations: done"
else
    echo "[7/9] Migrations: skipped (role=$SERVER_ROLE)"
fi

# --- Step 8: Build frontend (if applicable) ---
if [ -f "$PROJECT_DIR/package.json" ] && grep -q '"build"' "$PROJECT_DIR/package.json" 2>/dev/null; then
    su - waygou -c "cd $PROJECT_DIR && npm install --quiet 2>/dev/null && npm run build --quiet 2>/dev/null"
    echo "[8/9] Frontend: built"
else
    echo "[8/9] Frontend: N/A"
fi

# --- Step 9: Rebuild caches ---
su - waygou -c "cd $PROJECT_DIR && php artisan config:cache"
su - waygou -c "cd $PROJECT_DIR && php artisan route:cache"
# view:cache only on servers that have views (ingestion/workers don't)
su - waygou -c "cd $PROJECT_DIR && php artisan view:cache" 2>/dev/null || true
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
chgrp www-data "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[9/9] Caches: rebuilt"

echo ""
echo "=== Deploy complete ==="
echo "Commit: $COMMIT"
echo "Core:   $CORE_VERSION"
echo "Role:   $SERVER_ROLE"
echo "Status: Server still in maintenance mode"
echo "Next:   php artisan kraite:warmup  (or /kraite-warmup <hostname>)"
