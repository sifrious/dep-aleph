<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_continuation_leases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_stream_id')->constrained('aleph_source_streams')->cascadeOnDelete();
            $table->string('capability');
            $table->string('partition_key');
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->string('owner');
            $table->timestampTz('acquired_at');
            $table->timestampTz('expires_at');
            $table->unique(['source_stream_id', 'capability', 'partition_key'], 'aleph_continuation_lease_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_continuation_leases');
    }
};
