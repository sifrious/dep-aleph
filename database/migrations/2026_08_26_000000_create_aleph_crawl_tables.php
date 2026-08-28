<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source_reference');
            $table->string('capability');
            $table->string('status');
            $table->json('parameters');
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['source_reference', 'capability', 'status']);
        });

        Schema::create('aleph_frontier_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('aleph_frontier_candidates')->nullOnDelete();
            $table->text('canonical_url');
            $table->char('canonical_hash', 64);
            $table->text('requested_url');
            $table->string('host');
            $table->unsignedInteger('depth');
            $table->string('origin');
            $table->string('state');
            $table->string('skip_reason')->nullable();
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->string('failure')->nullable();
            $table->text('failure_message')->nullable();
            $table->ulid('observation_id')->nullable();
            $table->string('observation_disposition')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('extractor')->nullable();
            $table->string('extraction_version')->nullable();
            $table->string('extraction_status')->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('ingested_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['run_id', 'canonical_hash']);
            $table->index(['run_id', 'state', 'depth', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_frontier_candidates');
        Schema::dropIfExists('aleph_ingestion_runs');
    }
};
