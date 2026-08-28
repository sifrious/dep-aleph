<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_source_stream_status', function (Blueprint $table): void {
            $table->foreignUlid('source_stream_id')->primary()->constrained('aleph_source_streams')->cascadeOnDelete();
            $table->foreignUlid('last_attempt_id')->nullable()->constrained('aleph_ingestion_attempts')->nullOnDelete();
            $table->foreignUlid('last_successful_run_id')->nullable()->constrained('aleph_ingestion_runs')->nullOnDelete();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('accepted_through_at')->nullable();
            $table->timestampTz('next_due_at')->nullable();
            $table->unsignedInteger('expected_interval_seconds')->nullable();
            $table->unsignedInteger('stale_after_seconds')->nullable();
            $table->string('freshness_status')->default('never_synchronized');
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_source_stream_status');
    }
};
