# Email to S3 Migration System

A robust Laravel application for migrating email bodies and attachments to S3-compatible storage (MinIO).

## Features

- **Batch Processing**: Configurable batch sizes for optimal performance
- **Resume Capability**: Progress tracking stored in database for resuming interrupted migrations
- **Idempotent Operations**: Safe to run multiple times without duplicating data
- **Error Recovery**: Automatic retry with configurable max attempts
- **Memory Management**: Periodic garbage collection for processing millions of records
- **Concurrency Control**: Database-level locking prevents duplicate processing
- **Comprehensive Monitoring**: Real-time status and progress tracking

## Architecture

- **Repository Pattern**: Clean data access layer abstraction
- **Service Layer**: Business logic encapsulation
- **Value Objects**: Immutable data transfer objects
- **Factory Pattern**: Test data generation with Faker

## Setup Instructions

### 1. Clone Repository
```bash
git clone <repository-url>
cd email-migration
```

### 2. Start Docker Environment
```bash
docker-compose up -d
```

This starts:
- PHP 8.2 FPM application server
- Nginx web server (port 8080)
- PostgreSQL 15 database (port 5432)
- MinIO S3-compatible storage (ports 9000, 9001)

### 3. Install Dependencies
```bash
docker-compose exec app composer install
```

### 4. Configure Environment
```bash
cp .env.example .env
docker-compose exec app php artisan key:generate
```

### 5. Run Migrations
```bash
docker-compose exec app php artisan migrate
```

### 6. Seed Database (Optional)
Creates 100,000 test email records with attachments:
```bash
docker-compose exec app php artisan db:seed
```

### 7. Run Migration Command
```bash
# Basic migration
docker-compose exec app php artisan emails:migrate-to-s3

# With custom batch size
docker-compose exec app php artisan emails:migrate-to-s3

# Resume from previous batch
docker-compose exec app php artisan emails:migrate-to-s3 --resume=<batch-id>

# Dry run (no actual changes)
docker-compose exec app php artisan emails:migrate-to-s3 --dry-run
```

### 8. Monitor Progress
```bash
# View latest migration status
docker-compose exec app php artisan emails:migration-status

# View specific batch
docker-compose exec app php artisan emails:migration-status --batch=<batch-id>
```

### 9. Retry Failed Emails
```bash
# View failed emails
docker-compose exec app php artisan emails:retry-failed

# Reset attempt counters to retry
docker-compose exec app php artisan emails:retry-failed --reset --limit=100
```

## Configuration

All configuration is managed via environment variables in `.env`:

```env
# Migration Settings
MIGRATION_BATCH_SIZE=10        # Emails per batch
MIGRATION_MAX_ATTEMPTS=3       # Max retry attempts per email
MIGRATION_RETRY_DELAY=60       # Delay between retries (seconds)
MIGRATION_WORKERS=1            # Concurrent workers (future feature)
MIGRATION_MEMORY_LIMIT=512M    # Memory limit per process
MIGRATION_CHUNK_TIMEOUT=300    # Timeout per chunk (seconds)

# S3/MinIO Settings
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
S3_ENDPOINT=http://minio:9000
S3_BUCKET=email-migration
S3_REGION=us-east-1
```

## Database Schema

### emails
- Email content and metadata
- Migration tracking fields (is_migrated, attempts, errors)
- S3 paths for body and attachments

### files
- File metadata and local paths
- S3 migration tracking

### migration_progress
- Batch-level progress tracking
- Resume capability support
- Statistics and error logging

## Project Structure

```
project/
├── app/
│   ├── Console/Commands/          # Artisan commands
│   ├── Exceptions/                # Custom exceptions
│   ├── Models/                    # Eloquent models
│   ├── Repositories/              # Data access layer
│   ├── Services/                  # Business logic
│   └── ValueObjects/              # Immutable DTOs
├── config/
│   └── migration.php              # Migration-specific config
├── database/
│   ├── factories/                 # Test data factories
│   ├── migrations/                # Schema definitions
│   └── seeders/                   # Database seeders
├── docker/
│   ├── nginx/                     # Nginx configuration
│   ├── php/                       # PHP Dockerfile
│   └── postgresql/                # PostgreSQL init scripts
├── storage/
│   ├── app/files/                 # Local file storage
│   └── minio/                     # MinIO data volume
└── tests/
    ├── Unit/                      # Unit tests
    └── Feature/                   # Feature tests
```

## Testing

```bash
# Run all tests
docker-compose exec app php artisan test

# Run specific test suite
docker-compose exec app php artisan test --testsuite=Unit

# Run with coverage
docker-compose exec app php artisan test --coverage
```

## Performance Optimizations

1. **Database Indexes**
   - Composite index on `(is_migrated, migration_attempts, id)` for efficient batch queries
   - Index on migration status for progress monitoring

2. **Memory Management**
   - Automatic garbage collection when memory exceeds 100MB
   - Database connection recycling to prevent connection leaks

3. **Batch Processing**
   - Configurable batch sizes to balance throughput and memory
   - Progress saved after each batch for resume capability

4. **S3 Optimization**
   - Server-side encryption (AES256)
   - Organized storage paths (`emails/bucket/id.html`, `attachments/bucket/filename`)

## Error Handling

- **Transaction Safety**: Each email migration wrapped in database transaction
- **Retry Logic**: Failed emails automatically retried up to max attempts
- **Error Logging**: Detailed error messages stored in database
- **Lock Mechanism**: Prevents duplicate migration processes

## MinIO Console

Access the MinIO web console at `http://localhost:9001`:
- Username: `minioadmin`
- Password: `minioadmin`

## Troubleshooting

### Migration stuck or not starting
Check for stale lock file:
```bash
docker-compose exec app rm -f storage/app/migration.lock
```

### Memory issues
Reduce batch size in `.env`:
```env
MIGRATION_BATCH_SIZE=5
```

### Connection errors to MinIO
Verify MinIO is running:
```bash
docker-compose ps
docker-compose logs minio
```

### Database connection issues
Check PostgreSQL status:
```bash
docker-compose logs db
```

## API Reference

### Commands

| Command | Description |
|---------|-------------|
| `emails:migrate-to-s3` | Main migration command |
| `emails:migration-status` | Display migration progress |
| `emails:retry-failed` | Reset and retry failed emails |

### Services

- **S3Service**: Handles S3/MinIO operations
- **EmailMigrationService**: Orchestrates migration process
- **EmailRepository**: Email data access
- **FileRepository**: File data access

## Queue-Based Asynchronous Migration

The migration system now uses Laravel's queue system for asynchronous, concurrent processing.

### Quick Start - Complete Migration

```bash
# Run the complete migration process with interactive prompts
./run-migration.sh
```

### Step-by-Step Manual Process

#### 1. Dispatch Jobs to Queue
```bash
docker-compose exec app php artisan emails:migrate-to-s3
```
This loads all email IDs and dispatches individual jobs to the queue.

#### 2. Start Queue Workers
```bash
# Start 10 concurrent workers (default)
./queue-workers.sh start 10

# Or start custom number of workers
./queue-workers.sh start 20
```

#### 3. Monitor Progress
```bash
# Real-time monitoring
./queue-workers.sh monitor

# Or one-time status check
docker-compose exec app php artisan emails:queue-status
```

### Queue Management Commands

```bash
# Worker Management
./queue-workers.sh start [n]     # Start n workers (default: 10)
./queue-workers.sh stop          # Stop all workers
./queue-workers.sh status        # Show worker processes
./queue-workers.sh restart [n]   # Restart with n workers

# Queue Monitoring
./queue-workers.sh monitor       # Real-time queue monitoring
docker-compose exec app php artisan emails:queue-status         # One-time status
docker-compose exec app php artisan emails:queue-status --watch # Continuous monitoring

# Failed Job Management
./queue-workers.sh retry-failed  # Retry all failed jobs
./queue-workers.sh clear-failed  # Clear failed jobs
docker-compose exec app php artisan queue:failed  # List failed jobs
```

### Performance Tuning

#### Worker Concurrency
- **Development**: 5-10 workers
- **Production**: 20-50 workers (depends on server resources)

#### Memory Limits
Each worker is limited to 256MB. Adjust in `queue-workers.sh` if needed.

#### Job Timeout
Default timeout is 300 seconds (5 minutes) per job. Adjust for larger files.

### Monitoring Output Example

```
═══════════════════════════════════════════════════════════
                EMAIL MIGRATION QUEUE STATUS
═══════════════════════════════════════════════════════════

Batch ID: abc-123-def-456
Status: ⚙️  Processing

┌─────────────────────┬────────┬────────────┐
│ Category            │ Count  │ Percentage │
├─────────────────────┼────────┼────────────┤
│ Total Emails        │ 100,000│ 100%       │
│ Jobs Dispatched     │ 100,000│ 100%       │
│ Successfully Processed│ 45,230│ 45.23%     │
│ Failed              │ 120    │ 0.12%      │
│ Remaining           │ 54,650 │ 54.65%     │
└─────────────────────┴────────┴────────────┘

Queue Status:
┌─────────────────────┬────────┐
│ Queue Metric        │ Value  │
├─────────────────────┼────────┤
│ Jobs in Queue       │ 54,530 │
│ Jobs Being Processed│ 10     │
│ Failed Jobs         │ 120    │
└─────────────────────┴────────┘

Performance Metrics:
┌─────────────────────┬────────────┐
│ Metric              │ Value      │
├─────────────────────┼────────────┤
│ Completion          │ 45.35%     │
│ Success Rate        │ 99.74%     │
│ Processing Rate     │ 125.5/min  │
│ Time Elapsed        │ 06:02:15   │
│ Est. Time Remaining │ 7.3 hours  │
└─────────────────────┴────────────┘

▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░ 45,350/100,000
```

### Troubleshooting

#### Workers Not Processing Jobs
```bash
# Check worker status
./queue-workers.sh status

# Restart workers
./queue-workers.sh restart 10

# Check Laravel logs
docker-compose exec app tail -f storage/logs/laravel.log
```

#### High Memory Usage
```bash
# Stop workers
./queue-workers.sh stop

# Start fewer workers with lower memory limit
docker-compose exec app php artisan queue:work \
    --queue=email-migration \
    --memory=128 \
    --max-jobs=500
```

#### Failed Jobs
```bash
# View failed jobs
docker-compose exec app php artisan queue:failed

# Retry specific job
docker-compose exec app php artisan queue:retry [job-id]

# Retry all failed jobs
./queue-workers.sh retry-failed
```

## Future Enhancements

- Real-time progress webhooks
- Cloud provider-specific S3 optimization
- Data validation and integrity checks
- Migration rollback capabilities

## License

MIT License
