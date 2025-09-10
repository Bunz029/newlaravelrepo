#!/bin/bash

# Railway build script for Laravel
echo "Starting Railway build process..."

# Install dependencies
composer install --no-dev --optimize-autoloader

# Generate application key if not exists
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Set default database connection to mysql for Railway
if [ -z "$DB_CONNECTION" ]; then
    export DB_CONNECTION=mysql
fi

# Create storage directories and set permissions
echo "Setting up storage directories..."
mkdir -p storage/app/public/maps
mkdir -p storage/app/public/buildings
mkdir -p storage/app/public/rooms
mkdir -p storage/app/public/employees
mkdir -p storage/app/public/faculty
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
chmod -R 755 storage/app/public

# Create storage link for file uploads
echo "Creating storage link..."
php artisan storage:link

# Set proper ownership (if possible)
chown -R www-data:www-data storage/app/public 2>/dev/null || true

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Clear and cache configuration
echo "Optimizing Laravel..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Optimize for production
php artisan optimize

echo "Railway build completed successfully!"
echo "Storage link should now be working!"
