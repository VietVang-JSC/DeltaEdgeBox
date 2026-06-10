#!/bin/bash
# Deploy delta-pos-edge-box
# Usage: bash deploy.sh

set -e

echo "=== Pulling latest code ==="
git pull origin testing-box

echo "=== Installing dependencies ==="
composer install --no-dev --prefer-dist --no-interaction

echo "=== Running migrations ==="
php artisan migrate --force

echo "=== Clearing cache ==="
php artisan optimize:clear

echo "=== Restart server ==="
# Kill old server
pkill -f "artisan serve" 2>/dev/null || true
sleep 1
# Start new server
nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/server.log 2>&1 &

echo "=== Done ==="
