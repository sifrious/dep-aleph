<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_ingestion_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status');
            $table->json('checkpoint')->nullable();
            $table->json('stats')->nullable();
            $table->json('failure')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unique(['run_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_ingestion_attempts');
    }
};
