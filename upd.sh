#!/bin/bash
set -e
source .env

# Install dependencies in parallel
composer install & COMPOSER_PID=$!
npm install & NPM_PID=$!

# Wait for composer to finish before running artisan commands
wait $COMPOSER_PID

# Clear all caches at once (replaces config:clear, route:clear, view:clear, event:clear, cache:clear)
#php artisan optimize
php artisan migrate --force
php artisan schedule:clear-cache
php artisan queue:restart
php artisan telescope:prune --hours=72 &
php artisan config:clear

# Wait for npm and build frontend (runs in parallel with telescope:prune)
wait $NPM_PID
npm run build

# Wait for all background jobs to complete
wait



# Clear logs
echo "" > storage/logs/laravel.log

