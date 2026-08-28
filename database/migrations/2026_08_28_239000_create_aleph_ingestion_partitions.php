<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_ingestion_partitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->string('key');
            $table->unsignedInteger('position');
            $table->string('status');
            $table->json('checkpoint')->nullable();
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('accepted')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->text('failure')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('updated_at');
            $table->unique(['run_id', 'key']);
            $table->unique(['run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_ingestion_partitions');
    }
};
