<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_repository_watches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('legacy_reference')->nullable()->unique();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('source_reference');
            $table->string('repository_reference');
            $table->string('mode');
            $table->json('filters')->nullable();
            $table->unsignedInteger('cadence_seconds');
            $table->json('checkpoint')->nullable();
            $table->string('head_reference')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('backfill_completed_at')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('backoff_until')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source_installation_id', 'repository_reference']);
            $table->index(['enabled', 'next_sync_at']);
        });

        Schema::create('aleph_repository_watch_triggers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('repository_watch_id')->constrained('aleph_repository_watches')->cascadeOnDelete();
            $table->string('trigger_key');
            $table->string('run_id')->nullable();
            $table->timestampTz('observed_at');
            $table->unique(['repository_watch_id', 'trigger_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_repository_watch_triggers');
        Schema::dropIfExists('aleph_repository_watches');
    }
};
