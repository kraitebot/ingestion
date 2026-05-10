#!/usr/bin/env bash
# Start the local ingestion stack (Horizon + dispatch daemon).
# Run once: bash start-local.sh
# Stop: Ctrl+C (kills both)

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

trap 'echo "Stopping..."; kill 0; exit 0' INT TERM

echo "Starting Horizon..."
php artisan horizon &

echo "Starting dispatch daemon..."
php artisan kraite:dispatch-daemon &

echo ""
echo "Local stack running. Ctrl+C to stop."
echo "  Horizon:  PID $!"
echo "  Daemon:   running in background"
echo ""

wait
