<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_source_streams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('stream_key');
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source_installation_id', 'stream_key']);
        });

        Schema::create('aleph_ingestion_checkpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('commit_key', 64)->unique();
            $table->foreignUlid('source_stream_id')->constrained('aleph_source_streams')->cascadeOnDelete();
            $table->string('capability');
            $table->string('partition_key');
            $table->string('format');
            $table->string('serializer_version');
            $table->longText('value');
            $table->string('rule');
            $table->unsignedBigInteger('position')->nullable();
            $table->unsignedBigInteger('version');
            $table->json('accepted_references');
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->foreignUlid('attempt_id')->nullable()->constrained('aleph_ingestion_attempts')->nullOnDelete();
            $table->timestampTz('committed_at');
            $table->unique(
                ['source_stream_id', 'capability', 'partition_key', 'version'],
                'aleph_checkpoint_version',
            );
            $table->index(
                ['source_stream_id', 'capability', 'partition_key', 'committed_at'],
                'aleph_checkpoint_history',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_ingestion_checkpoints');
        Schema::dropIfExists('aleph_source_streams');
    }
};
