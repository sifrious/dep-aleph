<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_normalization_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('ingestion_attempt_id')->nullable()->index();
            $table->string('normalizer')->index();
            $table->unsignedInteger('normalizer_version');
            $table->string('candidate_schema');
            $table->unsignedInteger('candidate_schema_version');
            $table->char('input_hash', 64)->index();
            $table->string('source_reference');
            $table->string('status')->index();
            $table->unsignedInteger('candidate_count')->default(0);
            $table->boolean('cached')->default(false);
            $table->string('error_code')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at');
            $table->unsignedInteger('duration_ms')->default(0);

            $table->index(['input_hash', 'normalizer', 'normalizer_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_normalization_attempts');
    }
};
