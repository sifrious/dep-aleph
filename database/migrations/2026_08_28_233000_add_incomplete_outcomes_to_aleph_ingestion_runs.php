<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->json('remaining_work')->nullable()->after('checkpoint');
            $table->unsignedInteger('warning_count')->default(0)->after('stats');
            $table->unsignedInteger('error_count')->default(0)->after('warning_count');
        });

        Schema::create('aleph_ingestion_failures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('aleph_ingestion_runs')->cascadeOnDelete();
            $table->foreignUlid('attempt_id')->nullable()->constrained('aleph_ingestion_attempts')->nullOnDelete();
            $table->string('partition_key')->nullable();
            $table->string('origin');
            $table->string('category');
            $table->text('message');
            $table->json('details')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['run_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_ingestion_failures');

        Schema::table('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->dropColumn(['remaining_work', 'warning_count', 'error_count']);
        });
    }
};
