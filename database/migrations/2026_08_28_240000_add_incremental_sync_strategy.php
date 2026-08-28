<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aleph_source_streams', function (Blueprint $table): void {
            $table->string('sync_strategy')->default('reconcile')->after('scope_id');
        });

        Schema::create('aleph_incremental_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('change_key', 64)->unique();
            $table->foreignUlid('source_stream_id')->constrained('aleph_source_streams')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->foreignUlid('attempt_id')->nullable()->constrained('aleph_ingestion_attempts')->nullOnDelete();
            $table->string('partition_key');
            $table->string('source_change_id');
            $table->string('kind');
            $table->string('resource_reference');
            $table->char('fingerprint', 64);
            $table->string('observation_reference');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->index(['source_stream_id', 'partition_key', 'recorded_at'], 'aleph_incremental_change_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_incremental_changes');

        Schema::table('aleph_source_streams', function (Blueprint $table): void {
            $table->dropColumn('sync_strategy');
        });
    }
};
