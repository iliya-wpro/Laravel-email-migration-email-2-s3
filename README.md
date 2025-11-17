# Email to S3 Migration System

Laravel application for migrating email bodies and attachments to S3-compatible storage (MinIO).

## Quick Start

### 1. Start Docker Environment
```bash
docker-compose up -d
```

Services:
- App (PHP 8.2 FPM)
- Queue Workers (10 concurrent workers, auto-start)
- Nginx (http://localhost:8080)
- PostgreSQL (port 5432)
- MinIO (http://localhost:9000)

### 2. Seed Database
```bash
docker-compose exec app php artisan db:seed
```
Creates 100,000 test emails with attachments.

### 3. Run Migration
```bash
docker-compose exec app php artisan emails:migrate-to-s3
```
Dispatches all emails to the queue. Workers process automatically.

### 4. Monitor Progress
Open in browser:
```
http://localhost:8080
```

Real-time dashboard showing:
- Migration progress and success rate
- Queue status and pending jobs
- Worker utilization (Active/Idle)
- Processing speed and ETA
- System health

## Configuration

Edit `.env` for performance tuning:

```env
# Queue Workers (concurrent processing)
QUEUE_WORKERS=10              # Number of concurrent workers
QUEUE_WORKER_SLEEP=0          # Sleep between jobs (0 = no sleep)
QUEUE_WORKER_MEMORY=256       # Memory limit per worker (MB)
QUEUE_WORKER_TIMEOUT=300      # Job timeout (seconds)

# S3/MinIO
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
S3_ENDPOINT=http://minio:9000
S3_BUCKET=email-migration
```

Restart workers after config changes:
```bash
docker-compose restart queue-worker
```

## MinIO Console

Access at `http://localhost:9001`
- Username: `minioadmin`
- Password: `minioadmin`

## Commands

```bash
# Migration
docker-compose exec app php artisan emails:migrate-to-s3

# Check queue status
docker-compose exec app php artisan emails:queue-status

# List failed jobs
docker-compose exec app php artisan queue:failed

# Retry failed jobs
docker-compose exec app php artisan queue:retry all

# Clear failed jobs
docker-compose exec app php artisan queue:flush
```

## Troubleshooting

### Workers not processing
```bash
docker-compose restart queue-worker
docker-compose logs queue-worker
```

### Check active workers
```bash
docker-compose exec queue-worker ps aux | grep "queue:work"
```

### View logs
```bash
docker-compose exec app tail -f storage/logs/laravel.log
```

## License

MIT License
