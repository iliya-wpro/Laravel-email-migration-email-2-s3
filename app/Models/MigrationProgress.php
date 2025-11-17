<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MigrationProgress extends Model
{
    use HasFactory;

    protected $table = 'migration_progress';

    protected $fillable = [
        'batch_id',
        'last_processed_email_id',
        'last_dispatched_id',
        'total_emails',
        'jobs_dispatched',
        'processed_emails',
        'failed_emails',
        'status',
        'started_at',
        'completed_at',
        'error_log',
    ];

    protected $casts = [
        'error_log' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_processed_email_id' => 'integer',
        'last_dispatched_id' => 'integer',
        'total_emails' => 'integer',
        'jobs_dispatched' => 'integer',
        'processed_emails' => 'integer',
        'failed_emails' => 'integer',
    ];
}
