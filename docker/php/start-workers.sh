#!/bin/bash

# Queue Worker Startup Script
# This script starts queue workers on container startup

WORKERS=${QUEUE_WORKERS:-10}
TRIES=${QUEUE_WORKER_TRIES:-3}
TIMEOUT=${QUEUE_WORKER_TIMEOUT:-300}
MEMORY=${QUEUE_WORKER_MEMORY:-256}
SLEEP=${QUEUE_WORKER_SLEEP:-3}
MAX_JOBS=${QUEUE_WORKER_MAX_JOBS:-1000}

echo "========================================="
echo "Starting $WORKERS queue workers..."
echo "========================================="
echo "Configuration:"
echo "  Tries: $TRIES"
echo "  Timeout: ${TIMEOUT}s"
echo "  Memory: ${MEMORY}MB"
echo "  Sleep: ${SLEEP}s"
echo "  Max Jobs: $MAX_JOBS"
echo "========================================="

# Install composer dependencies if not present or outdated
cd /var/www

# Check if we need to update dependencies (composer.lock missing or Laravel version mismatch)
NEEDS_UPDATE=false
if [ ! -f /var/www/vendor/autoload.php ] || [ ! -f /var/www/composer.lock ]; then
    NEEDS_UPDATE=true
elif [ -f /var/www/composer.lock ]; then
    # Check if Laravel 11 is in composer.lock, if not we need to update
    if ! grep -q '"laravel/framework"' /var/www/composer.lock || ! grep -q '"version": "v11\.' /var/www/composer.lock; then
        echo "Detected Laravel version mismatch, removing old composer.lock..."
        rm -f /var/www/composer.lock
        NEEDS_UPDATE=true
    fi
fi

if [ "$NEEDS_UPDATE" = true ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo "Dependencies installed successfully!"
else
    # Verify dependencies are up to date
    echo "Verifying Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Wait for database to be ready
echo "Waiting for database connection..."
sleep 5

# Start queue workers in the background
for i in $(seq 1 $WORKERS); do
    php /var/www/artisan queue:work \
        --queue=email-migration \
        --sleep=$SLEEP \
        --tries=$TRIES \
        --timeout=$TIMEOUT \
        --memory=$MEMORY \
        --max-jobs=$MAX_JOBS &
    echo "Worker $i started (PID: $!)"
done

echo "========================================="
echo "All $WORKERS workers started successfully!"
echo "Workers are waiting for jobs in 'email-migration' queue"
echo "========================================="

# Keep the script running to maintain workers
wait
