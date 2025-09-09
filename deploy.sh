#!/bin/bash

# Railway deployment script for Laravel
echo "Starting Laravel deployment..."

# Generate application key if not exists
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Set default database connection to mysql for Railway
if [ -z "$DB_CONNECTION" ]; then
    export DB_CONNECTION=mysql
fi

# Run migrations
php artisan migrate --force

# Clear and cache configuration
php artisan config:clear
php artisan config:cache

# Clear and cache routes
php artisan route:clear
php artisan route:cache

# Clear and cache views
php artisan view:clear
php artisan view:cache

# Optimize for production
php artisan optimize

echo "Laravel deployment completed!"
