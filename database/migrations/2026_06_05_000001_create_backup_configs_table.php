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
        Schema::create('backup_configs', function (Blueprint $table) {
            $table->id();
            $table->string('database_name');
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->unsignedInteger('retention_days')->default(7);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique('database_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_configs');
    }
};
