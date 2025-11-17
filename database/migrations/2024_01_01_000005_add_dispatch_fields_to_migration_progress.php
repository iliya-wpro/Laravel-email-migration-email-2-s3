<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('last_dispatched_id')->default(0)->after('last_processed_email_id');
            $table->unsignedBigInteger('jobs_dispatched')->default(0)->after('total_emails');
        });
    }

    public function down(): void
    {
        Schema::table('migration_progress', function (Blueprint $table) {
            $table->dropColumn(['last_dispatched_id', 'jobs_dispatched']);
        });
    }
};
