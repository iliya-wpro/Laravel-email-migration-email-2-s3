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
sleep 5

# Run database migrations
echo "Running database migrations..."
php /var/www/artisan migrate --force --no-interaction

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
