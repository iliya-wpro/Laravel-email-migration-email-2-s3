-- Additional performance indexes for migration
-- These will be created after migrations run

-- Index for efficient email migration queries
-- CREATE INDEX IF NOT EXISTS idx_emails_migration_composite ON emails(is_migrated, migration_attempts, id);

-- Index for file migration tracking
-- CREATE INDEX IF NOT EXISTS idx_files_migration ON files(is_migrated, id);

-- Index for progress monitoring
-- CREATE INDEX IF NOT EXISTS idx_migration_progress_status ON migration_progress(status, created_at);
