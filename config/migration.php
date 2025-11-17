<?php
// TODO later optimizistions for enebling fine tunnig - chears to Zdravko ;) 
return [
    'batch_size' => env('MIGRATION_BATCH_SIZE', 10),
    'max_attempts' => env('MIGRATION_MAX_ATTEMPTS', 3),
    'retry_delay' => env('MIGRATION_RETRY_DELAY', 60),
    's3' => [
        'bucket' => env('S3_BUCKET', 'email-migration'),
        'region' => env('S3_REGION', 'us-east-1'),
        'email_prefix' => 'emails/',
        'attachment_prefix' => 'attachments/',
    ],
    'concurrent_workers' => env('MIGRATION_WORKERS', 1),
    'memory_limit' => env('MIGRATION_MEMORY_LIMIT', '512M'),
    'chunk_timeout' => env('MIGRATION_CHUNK_TIMEOUT', 300),
];
