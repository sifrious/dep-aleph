<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_ingestion_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('capability');
            $table->string('cron_expression');
            $table->string('timezone');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('next_due_at');
            $table->timestampTz('last_dispatched_at')->nullable();
            $table->json('constraints')->nullable();
            $table->string('locked_by')->nullable();
            $table->timestampTz('lock_expires_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source_installation_id', 'capability']);
            $table->index(['enabled', 'next_due_at']);
        });

        Schema::create('aleph_ingestion_schedule_dispatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('schedule_id')->constrained('aleph_ingestion_schedules')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->timestampTz('due_at');
            $table->timestampTz('dispatched_at');
            $table->unique(['schedule_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_ingestion_schedule_dispatches');
        Schema::dropIfExists('aleph_ingestion_schedules');
    }
};
