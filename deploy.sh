#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Kraite Deploy Script v3
# Runs ON the server as ROOT (SSH as root).
# Project commands (artisan, composer) run as waygou via su/sudo.
# Called AFTER kraite:cooldown --status confirms STATUS:COOLED_DOWN.
# Does NOT bring the server back online — kraite:warmup does that separately.
#
# SAFETY NOTES:
# - Never run artisan/composer/git as root — waygou owns the project files.
#   Root-created files get root:root ownership and PHP-FPM (www-data) can't read them.
# - The server composer.json uses VCS repos (github), not ../packages/ path repos.
#   git reset --hard would overwrite it — we backup/restore around the reset.
#   (bash reads the full script before executing, so the running script is
#   unaffected by git reset --hard mid-execution.)
# - config:cache must run as waygou — root-cached .php files block PHP-FPM.
# - SERVER_ROLE is read from artisan AFTER reset, not from .env BEFORE reset,
#   because .env survives the reset (gitignored) but composer.json does not.
# =============================================================================

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
WHOAMI=$(whoami)

# This script is designed to be called as root (SSH) so it can fix ownership.
# It delegates all project commands to waygou.
echo "=== Kraite Deploy ==="
echo "Host: $(hostname)"
echo "Runner: $WHOAMI"
echo "Path: $PROJECT_DIR"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# --- Step 1: Verify cooldown ---
# Run as waygou — artisan must never run as root.
if ! su - waygou -c "cd $PROJECT_DIR && php artisan kraite:cooldown --status" 2>&1 | grep -q "STATUS:COOLED_DOWN"; then
    echo "ERROR: Server is NOT cooled down. Run 'php artisan kraite:cooldown' first."
    exit 1
fi
echo "[1/8] Cooldown verified"

# --- Step 2: Pull latest code ---
# CRITICAL: The server's composer.json uses VCS repos (github), not path repos.
# git reset --hard would replace it with the dev version (../packages/ paths).
# Backup before reset, restore after.
cp "$PROJECT_DIR/composer.json" /tmp/deploy-composer.json
cp "$PROJECT_DIR/composer.lock" /tmp/deploy-composer.lock 2>/dev/null || true

# Git commands run as waygou so the working tree stays waygou-owned.
su - waygou -c "cd $PROJECT_DIR && git fetch origin master"
su - waygou -c "cd $PROJECT_DIR && git reset --hard origin/master"

# Restore production composer files (VCS repos, not dev path repos).
cp /tmp/deploy-composer.json "$PROJECT_DIR/composer.json"
cp /tmp/deploy-composer.lock "$PROJECT_DIR/composer.lock" 2>/dev/null || true
chown waygou:www-data "$PROJECT_DIR/composer.json" "$PROJECT_DIR/composer.lock" 2>/dev/null || true

COMMIT=$(su - waygou -c "cd $PROJECT_DIR && git log --oneline -1")
echo "[2/8] Code: $COMMIT"

# --- Step 3: Install dependencies ---
# Composer MUST run as waygou. Root-created vendor/ files block PHP-FPM.
su - waygou -c "cd $PROJECT_DIR && composer install --no-interaction --no-dev --optimize-autoloader --quiet"
echo "[3/8] Composer: installed"

# --- Step 4: Fix ownership + permissions ---
# Do this BEFORE running artisan commands so PHP-FPM can read the new files.
# Run as root — only root can chown.
chown -R waygou:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[4/8] Permissions: fixed"

# --- Step 5: Read server role ---
# Read SERVER_ROLE via artisan (not from .env directly) so we get the resolved
# config value after the git reset. APP_ENV per server = athena/apollo/ares —
# this is how Horizon picks the right supervisor block.
SERVER_ROLE=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('kraite.server_role', 'web');\"" 2>/dev/null | tail -1 || echo "web")
echo "[5/8] Server role: $SERVER_ROLE"

# --- Step 6: DB backup + migrate (ingestion only) ---
if [ "$SERVER_ROLE" = "ingestion" ]; then
    BACKUP_DIR="$PROJECT_DIR/storage/backups"
    mkdir -p "$BACKUP_DIR"
    chown waygou:www-data "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"

    # Read DB creds from Laravel config (as waygou, since config:cache may not exist yet).
    DB_HOST=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.host');\"" 2>/dev/null | tail -1)
    DB_NAME=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.database');\"" 2>/dev/null | tail -1)
    DB_USER=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.username');\"" 2>/dev/null | tail -1)
    DB_PASS=$(su - waygou -c "cd $PROJECT_DIR && php artisan tinker --execute=\"echo config('database.connections.mysql.password');\"" 2>/dev/null | tail -1)

    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --single-transaction 2>/dev/null | gzip > "$BACKUP_FILE"
    chown waygou:www-data "$BACKUP_FILE"
    echo "[6/8] DB backup: $(du -h "$BACKUP_FILE" | cut -f1)"

    su - waygou -c "cd $PROJECT_DIR && php artisan migrate --force --no-interaction"
    echo "[6/8] Migrations: done"
else
    echo "[6/8] Migrations: skipped (role=$SERVER_ROLE)"
fi

# --- Step 7: Build frontend (if applicable) ---
if [ -f "$PROJECT_DIR/package.json" ] && grep -q '"build"' "$PROJECT_DIR/package.json" 2>/dev/null; then
    su - waygou -c "cd $PROJECT_DIR && npm install --quiet 2>/dev/null && npm run build --quiet 2>/dev/null"
    echo "[7/8] Frontend: built"
else
    echo "[7/8] Frontend: N/A"
fi

# --- Step 8: Rebuild caches ---
# MUST run as waygou — root-owned cached files block PHP-FPM.
su - waygou -c "cd $PROJECT_DIR && php artisan config:cache"
su - waygou -c "cd $PROJECT_DIR && php artisan route:cache"
su - waygou -c "cd $PROJECT_DIR && php artisan view:cache"
# Ensure cache files are group-readable (waygou creates them, www-data must read).
chmod 644 "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
chgrp www-data "$PROJECT_DIR/bootstrap/cache"/*.php 2>/dev/null || true
echo "[8/8] Caches: rebuilt"

# NOTE: PHP-FPM reload, Horizon restart, and supervisor start belong in warmup.
# deploy.sh leaves the server in maintenance mode. Warmup brings it online
# with fresh opcache AFTER the operator has verified health.

echo ""
echo "=== Deploy complete ==="
echo "Commit: $COMMIT"
echo "Role:   $SERVER_ROLE"
echo "Status: Server still in maintenance mode"
echo "Next:   php artisan kraite:warmup  (or /kraite-warmup <hostname>)"
