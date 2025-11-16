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
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('email_template_id');
            $table->string('receiver_email');
            $table->string('sender_email');
            $table->string('subject');
            $table->longText('body');
            $table->json('file_ids')->nullable();
            $table->string('body_s3_path')->nullable();
            $table->json('file_s3_paths')->nullable();
            $table->boolean('is_migrated')->default(false);
            $table->timestamp('migration_attempted_at')->nullable();
            $table->integer('migration_attempts')->default(0);
            $table->text('migration_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('is_migrated');
            $table->index('migration_attempts');
            $table->index(['is_migrated', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
