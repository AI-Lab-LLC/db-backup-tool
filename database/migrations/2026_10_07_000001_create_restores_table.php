<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore history. Restores run in the queue (RunRestoreJob); each request
     * gets a row here (queued -> running -> success/failed).
     */
    public function up(): void
    {
        Schema::create('restores', function (Blueprint $table) {
            $table->id();
            // Deleting / pruning the source backup must not erase restore history.
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->string('database_name');   // source database of the dump
            $table->string('target_database');
            $table->string('status')->default('queued'); // queued|running|success|failed (validated in app)
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['target_database', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restores');
    }
};
