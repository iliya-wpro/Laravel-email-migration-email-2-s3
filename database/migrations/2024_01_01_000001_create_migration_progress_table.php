<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('migration_progress', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->unique();
            $table->unsignedBigInteger('last_processed_email_id')->default(0);
            $table->unsignedBigInteger('total_emails');
            $table->unsignedBigInteger('processed_emails')->default(0);
            $table->unsignedBigInteger('failed_emails')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('error_log')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_processed_email_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_progress');
    }
};
