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

# Run database migrations
echo "Running database migrations..."
php /var/www/artisan migrate --force --no-interaction 2>/dev/null || echo "Migrations skipped (may already exist)"

# Create S3 bucket if it does not exist
echo "Ensuring S3 bucket exists..."
php /var/www/artisan storage:create-bucket 2>/dev/null || echo "Bucket creation skipped"

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
