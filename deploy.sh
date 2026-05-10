#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Kraite Deploy Script v2
# Runs ON the server as the waygou user.
# Called AFTER kraite:cooldown --status confirms STATUS:COOLED_DOWN.
# Does NOT bring the server back online — kraite:warmup does that separately.
# =============================================================================

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PROJECT_DIR"

# Read server role from .env
SERVER_ROLE=$(grep "^SERVER_ROLE=" .env 2>/dev/null | cut -d= -f2 || echo "web")
HOSTNAME=$(hostname)

echo "=== Kraite Deploy ==="
echo "Host: $HOSTNAME"
echo "Role: $SERVER_ROLE"
echo "Path: $PROJECT_DIR"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# --- Step 1: Verify cooldown ---
if ! php artisan kraite:cooldown --status 2>&1 | grep -q "STATUS:COOLED_DOWN"; then
    echo "ERROR: Server is NOT cooled down. Run 'php artisan kraite:cooldown' first."
    exit 1
fi
echo "[1/7] Cooldown verified"

# --- Step 2: Pull latest code ---
git fetch origin master
git reset --hard origin/master
COMMIT=$(git log --oneline -1)
echo "[2/7] Code: $COMMIT"

# --- Step 3: Install dependencies ---
composer install --no-interaction --no-dev --optimize-autoloader --quiet
echo "[3/7] Composer: installed"

# --- Step 4: DB backup + migrate (ingestion only) ---
if [ "$SERVER_ROLE" = "ingestion" ]; then
    BACKUP_DIR="$PROJECT_DIR/storage/backups"
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"

    # Read DB creds from Laravel config
    DB_HOST=$(php artisan tinker --execute="echo config('database.connections.mysql.host');" 2>/dev/null)
    DB_NAME=$(php artisan tinker --execute="echo config('database.connections.mysql.database');" 2>/dev/null)
    DB_USER=$(php artisan tinker --execute="echo config('database.connections.mysql.username');" 2>/dev/null)
    DB_PASS=$(php artisan tinker --execute="echo config('database.connections.mysql.password');" 2>/dev/null)

    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --single-transaction 2>/dev/null | gzip > "$BACKUP_FILE"
    echo "[4/7] DB backup: $(du -h "$BACKUP_FILE" | cut -f1)"

    php artisan migrate --force --no-interaction
    echo "[4/7] Migrations: done"
else
    echo "[4/7] Migrations: skipped (role=$SERVER_ROLE)"
fi

# --- Step 5: Build frontend (if applicable) ---
if [ -f "package.json" ] && grep -q '"build"' package.json 2>/dev/null; then
    npm install --quiet 2>/dev/null
    npm run build --quiet 2>/dev/null
    echo "[5/7] Frontend: built"
else
    echo "[5/7] Frontend: N/A"
fi

# --- Step 6: Rebuild caches ---
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "[6/7] Caches: rebuilt"

# --- Step 7: Fix permissions ---
chmod -R 775 storage bootstrap/cache
chmod 644 bootstrap/cache/*.php 2>/dev/null || true
echo "[7/7] Permissions: fixed"

# NOTE: PHP-FPM reload, Horizon restart, and supervisor start are handled
# by kraite:warmup — NOT here. deploy.sh leaves the server in maintenance
# mode for the operator to verify before bringing online.

echo ""
echo "=== Deploy complete ==="
echo "Commit: $COMMIT"
echo "Status: Server still in maintenance mode"
echo "Next:   php artisan kraite:warmup"
