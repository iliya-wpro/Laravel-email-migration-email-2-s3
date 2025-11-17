#!/bin/bash

# App Container Startup Script
# This script initializes the application on container startup

echo "========================================="
echo "Initializing application..."
echo "========================================="

# Install composer dependencies if not present
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo "Dependencies installed successfully!"
fi

# Setup environment if not present
if [ ! -f /var/www/.env ]; then
    echo "Setting up environment..."
    cp /var/www/.env.example /var/www/.env
    php /var/www/artisan key:generate --no-interaction
    echo "Environment configured!"
fi

# Wait for database to be ready
echo "Waiting for database..."
MAX_TRIES=30
COUNTER=0
until php /var/www/artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null || [ $COUNTER -eq $MAX_TRIES ]; do
    echo "Waiting for database connection... (attempt $((COUNTER+1))/$MAX_TRIES)"
    sleep 2
    COUNTER=$((COUNTER+1))
done

if [ $COUNTER -eq $MAX_TRIES ]; then
    echo "Warning: Database connection timeout after $MAX_TRIES attempts. Continuing anyway..."
else
    echo "Database connection established!"
fi

# Run database migrations
echo "Running database migrations..."
php /var/www/artisan migrate --force --no-interaction || echo "Migration warning: $?"

# Create S3 bucket if it does not exist
echo "Ensuring S3 bucket exists..."
php /var/www/artisan storage:create-bucket || echo "Bucket creation skipped"

# Clear and cache config
echo "Optimizing application..."
php /var/www/artisan config:clear
php /var/www/artisan cache:clear

echo "========================================="
echo "Application initialized successfully!"
echo "Starting PHP-FPM..."
echo "========================================="

# Start PHP-FPM
php-fpm
