#!/bin/bash

# Ensure we're in the project directory
cd ~/ingestion || exit 1

echo "=== Updating Global Composer Packages ==="
composer global update

echo ""
echo "=== Updating Project Composer Packages ==="
echo "(Includes Laravel Boost and other Laravel packages)"
composer update

echo ""
echo "=== Clearing Laravel Caches ==="
php artisan config:clear
php artisan cache:clear
php artisan view:clear

echo ""
echo "=== Updating Laravel Boost Guidelines ==="
php artisan boost:update

echo ""
echo "=== Updating Project NPM Packages ==="
npm update

echo ""
echo "=== Updating Global NPM Packages ==="
npm -g update

echo ""
echo "=== Updating Playwright Browsers ==="
# Stop mysql to prevent apt conflicts during dependency installation
echo "  Stopping MySQL..."
sudo service mysql stop 2>/dev/null
sleep 2
sudo pkill -9 mysqld 2>/dev/null || true
sleep 1
# Install deps separately, then browser (avoids Playwright's internal sudo wrapper)
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq libasound2t64 libatk-bridge2.0-0t64 libatk1.0-0t64 libatspi2.0-0t64 libcairo2 libcups2t64 libdbus-1-3 libdrm2 libgbm1 libglib2.0-0t64 libnspr4 libnss3 libpango-1.0-0 libx11-6 libxcb1 libxcomposite1 libxdamage1 libxext6 libxfixes3 libxkbcommon0 libxrandr2 xvfb fonts-noto-color-emoji fonts-unifont libfontconfig1 libfreetype6 xfonts-cyrillic xfonts-scalable fonts-liberation fonts-ipafont-gothic fonts-wqy-zenhei fonts-tlwg-loma-otf fonts-freefont-ttf 2>/dev/null || true
npx playwright install chromium 2>/dev/null || echo "  (skipped - playwright not configured)"
echo "  Starting MySQL..."
sudo service mysql start 2>/dev/null

echo ""
echo "=== Updating Claude Code ==="
claude update

echo ""
echo "=== Updating uv (Python package manager) ==="
uv self update 2>/dev/null || echo "  (skipped)"

echo ""
echo "=== Updating Continuous-Claude-v3 ==="
(cd ~/Continuous-Claude-v3 && git fetch origin && git reset --hard origin/main)

echo ""
echo "=== Updating Claude Marketplace Plugins ==="
for dir in ~/.claude/plugins/marketplaces/*/; do
    if [ -d "$dir/.git" ]; then
        name=$(basename "$dir")
        echo "  → $name"
        (cd "$dir" && git fetch origin --quiet && git reset --hard origin/main --quiet 2>/dev/null || git reset --hard origin/master --quiet 2>/dev/null || echo "    (skipped - no main/master branch)")
    fi
done

echo ""
echo "=== All Updates Complete! ==="
