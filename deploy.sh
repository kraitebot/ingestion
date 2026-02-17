#!/usr/bin/env bash
set -Eeuo pipefail

# Detect hostname and set directory
HOSTNAME=$(hostname)
SITE_DIR="/home/ploi/${HOSTNAME}.martingalian.com"

cd "$SITE_DIR"

echo "🚀 Starting deployment for ${HOSTNAME}..."

# Safety check: Only deploy if safe to restart
echo "🔍 Checking if it's safe to deploy..."
if php artisan list | grep -q "martingalian:safe-to-restart"; then
    if php artisan martingalian:safe-to-restart; then
        echo "✅ Safe to deploy - no steps are running."
    else
        echo "❌ Deployment aborted: Steps are currently being processed."
        echo "   Wait for steps to complete before deploying."
        exit 1
    fi
else
    echo "⚠️  Command 'martingalian:safe-to-restart' not found (first deployment?)"
    echo "   Proceeding with deployment..."
fi

# Stop supervisors (ONLY on ingestion server)
if [ "$HOSTNAME" = "ingestion" ]; then
    # Capture current supervisor PIDs before stopping
    echo "📸 Capturing current supervisor PIDs..."
    OLD_SUPERVISOR_PIDS=$(sudo /usr/bin/supervisorctl status | grep RUNNING | awk '{print $4}' | tr -d ',' | sort | tr '\n' ' ' || true)
    echo "   Old PIDs: ${OLD_SUPERVISOR_PIDS:-none}"

    echo "🛑 Stopping all supervisors..."
    sudo /usr/bin/supervisorctl stop all

    # Verify supervisors are stopped
    echo "🔍 Verifying supervisors are stopped..."
    if sudo /usr/bin/supervisorctl status | grep -q "RUNNING"; then
        echo "❌ Some supervisors are still running!"
        sudo /usr/bin/supervisorctl status
        exit 1
    fi
    echo "✅ All supervisors stopped."
else
    echo "⏭️  Skipping supervisor stop (not ingestion server)..."
fi

# Stop crontab (all servers)
echo "📸 Capturing current cron PID..."
OLD_CRON_PID=$(pgrep -x cron | head -1 || true)
echo "   Old cron PID: ${OLD_CRON_PID:-none}"

echo "🛑 Stopping crontab..."
sudo /usr/sbin/service cron stop

echo "🔍 Verifying crontab is stopped..."
if sudo /usr/sbin/service cron status | grep -q "running"; then
    echo "❌ Crontab is still running!"
    exit 1
fi
echo "✅ Crontab stopped."

# Get latest code first
echo "📦 Fetching latest code..."
git fetch origin master
git reset --hard origin/master

# Verify git fetch was successful
CURRENT_COMMIT=$(git rev-parse HEAD)
REMOTE_COMMIT=$(git rev-parse origin/master)
if [ "$CURRENT_COMMIT" != "$REMOTE_COMMIT" ]; then
    echo "❌ ABORT: Git reset failed! HEAD does not match origin/master."
    echo "   Current: $CURRENT_COMMIT"
    echo "   Expected: $REMOTE_COMMIT"
    exit 1
fi
echo "✅ Ingestion repo at: ${CURRENT_COMMIT:0:7}"

# Update APP_URL based on hostname
echo "🔧 Updating APP_URL for ${HOSTNAME}..."
sed -i "s|^APP_URL=.*|APP_URL=https://${HOSTNAME}.martingalian.com|" .env
echo "✅ APP_URL set to https://${HOSTNAME}.martingalian.com"

# Nuke any custom repositories (kills local path repos cleanly)
echo "🔧 Cleaning composer repositories..."
tmpfile=$(mktemp)
jq 'del(.repositories)' composer.json > "$tmpfile" && mv "$tmpfile" composer.json

# NUCLEAR OPTION: Remove vendor and clear Composer cache
echo "💣 Clearing vendor directory and Composer cache..."
rm -rf vendor/
composer clear-cache

# Resolve martingalian/core from remote (in case lock was bound to a path)
echo "📚 Updating martingalian/core..."
composer update martingalian/core --no-interaction --prefer-dist --no-dev

# Fresh install of all dependencies
echo "📥 Installing dependencies from scratch..."
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

# Verify martingalian/core was installed from GitHub (not path-based)
echo "🔍 Verifying martingalian/core installation..."
INSTALLED_CORE_REF=$(jq -r '.packages[] | select(.name == "martingalian/core") | .source.reference // empty' composer.lock 2>/dev/null || echo "")
INSTALLED_CORE_TYPE=$(jq -r '.packages[] | select(.name == "martingalian/core") | .source.type // empty' composer.lock 2>/dev/null || echo "")

if [ -z "$INSTALLED_CORE_REF" ] || [ "$INSTALLED_CORE_TYPE" = "path" ]; then
    echo "⚠️  martingalian/core not properly installed from GitHub! Attempting forced update..."
    echo "   Type: ${INSTALLED_CORE_TYPE:-unknown}"
    echo "   Ref: ${INSTALLED_CORE_REF:-none}"

    # Force fresh fetch from GitHub
    echo "🔄 Clearing composer cache and retrying..."
    composer clear-cache
    rm -rf vendor/martingalian/

    # Re-run composer update to fetch from GitHub
    composer update martingalian/core --no-interaction --prefer-dist --no-dev
    composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

    # Re-verify
    INSTALLED_CORE_REF=$(jq -r '.packages[] | select(.name == "martingalian/core") | .source.reference // empty' composer.lock 2>/dev/null || echo "")
    INSTALLED_CORE_TYPE=$(jq -r '.packages[] | select(.name == "martingalian/core") | .source.type // empty' composer.lock 2>/dev/null || echo "")

    if [ -z "$INSTALLED_CORE_REF" ] || [ "$INSTALLED_CORE_TYPE" = "path" ]; then
        echo "❌ ABORT: martingalian/core STILL not installed from GitHub!"
        echo "   Type: ${INSTALLED_CORE_TYPE:-unknown}"
        echo "   Ref: ${INSTALLED_CORE_REF:-none}"
        echo "   Check that the package exists on GitHub/Packagist."
        exit 1
    fi

    echo "✅ Forced update successful!"
fi

echo "✅ martingalian/core installed: ${INSTALLED_CORE_REF:0:7} (${INSTALLED_CORE_TYPE})"

# Regenerate autoloader
echo "🔄 Regenerating autoloader..."
composer dump-autoload --no-dev --optimize

# Maintenance mode
echo "🔒 Entering maintenance mode..."
php artisan down --retry=60 --render="errors::503" || true

# Clear ALL Laravel caches (CRITICAL: Before migrations to read fresh ENV vars)
echo "🧹 Clearing all caches..."
php artisan optimize:clear

# Nuke all logs (files and folders recursively)
echo "💣 Nuking all logs..."
rm -rf storage/logs/* || true

# Database migration (ONLY on ingestion server)
if [ "$HOSTNAME" = "ingestion" ]; then
    echo "🗄️  Running migrations..."

    # CRITICAL SAFETY CHECK: Double-verify hostname before migrations
    if [ "$HOSTNAME" != "ingestion" ]; then
        echo "❌ CRITICAL: Attempted to run migrations on non-ingestion server!"
        echo "   Hostname: $HOSTNAME"
        echo "   Aborting deployment for safety."
        exit 1
    fi

    php artisan migrate:fresh --seed --force
else
    echo "⏭️  Skipping migrations (not ingestion server)..."
fi

# Clear debug tables (runs on all servers, but command handles hostname validation)
echo "🧹 Clearing debug tables..."
php artisan debug:clear-tables

# Install NPM dependencies
echo "📦 Installing NPM dependencies..."
npm install --prefer-offline --no-audit

# Rebuild assets (THIS ENSURES FRONTEND CHANGES ARE COMPILED)
echo "🎨 Building frontend assets..."
npm run build

# Rebuild caches
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Storage link
php artisan storage:link || true

# Reload PHP-FPM (OPCache refresh)
echo "♻️  Reloading PHP-FPM (OPCache refresh)..."
sudo /usr/bin/systemctl reload php8.4-fpm || true

# Start supervisors (ONLY on ingestion server)
if [ "$HOSTNAME" = "ingestion" ]; then
    echo "🚀 Starting all supervisors..."
    sudo /usr/bin/supervisorctl start all

    # Wait for supervisors to initialize
    sleep 2

    # Verify supervisors are started
    echo "🔍 Verifying supervisors are started..."
    if sudo /usr/bin/supervisorctl status | grep -q "STOPPED\|FATAL\|EXITED"; then
        echo "❌ Some supervisors failed to start!"
        sudo /usr/bin/supervisorctl status
        exit 1
    fi
    echo "✅ All supervisors started."

    # Verify PIDs have changed (new processes = new code)
    echo "🔍 Verifying supervisor PIDs have changed..."
    NEW_SUPERVISOR_PIDS=$(sudo /usr/bin/supervisorctl status | grep RUNNING | awk '{print $4}' | tr -d ',' | sort | tr '\n' ' ' || true)
    echo "   New PIDs: ${NEW_SUPERVISOR_PIDS:-none}"

    if [ -n "$OLD_SUPERVISOR_PIDS" ] && [ "$OLD_SUPERVISOR_PIDS" = "$NEW_SUPERVISOR_PIDS" ]; then
        echo "❌ Supervisor PIDs did not change! Old code may still be running!"
        echo "   Old: $OLD_SUPERVISOR_PIDS"
        echo "   New: $NEW_SUPERVISOR_PIDS"
        exit 1
    fi
    echo "✅ Supervisor PIDs changed - running new code."
else
    echo "⏭️  Skipping supervisor start (not ingestion server)..."
fi

# Queue restart (all servers - signals workers to gracefully restart)
echo "🔄 Restarting queue workers..."
php artisan queue:restart

# Start crontab (all servers)
echo "🚀 Starting crontab..."
sudo /usr/sbin/service cron start

echo "🔍 Verifying crontab is started..."
sleep 1
if ! sudo /usr/sbin/service cron status | grep -q "running"; then
    echo "❌ Crontab failed to start!"
    exit 1
fi
echo "✅ Crontab started."

# Verify cron PID has changed (new process = new code)
echo "🔍 Verifying cron PID has changed..."
NEW_CRON_PID=$(pgrep -x cron | head -1 || true)
echo "   New cron PID: ${NEW_CRON_PID:-none}"

if [ -n "$OLD_CRON_PID" ] && [ "$OLD_CRON_PID" = "$NEW_CRON_PID" ]; then
    echo "❌ Cron PID did not change! Old code may still be running!"
    echo "   Old: $OLD_CRON_PID"
    echo "   New: $NEW_CRON_PID"
    exit 1
fi
echo "✅ Cron PID changed - running new code."

# Exit maintenance mode
echo "✅ Exiting maintenance mode..."
php artisan up

# Final optimization (cache everything)
echo "🚀 Final optimization..."
php artisan optimize

echo "🎉 Deployment complete for ${HOSTNAME}!"
