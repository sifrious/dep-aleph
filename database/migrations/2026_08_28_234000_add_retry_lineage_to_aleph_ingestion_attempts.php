<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aleph_ingestion_attempts', function (Blueprint $table): void {
            $table->foreignUlid('retry_of_id')->nullable()->after('run_id')->constrained('aleph_ingestion_attempts')->nullOnDelete();
            $table->string('retry_reason')->nullable()->after('retry_of_id');
            $table->string('partition_key')->nullable()->after('retry_reason');
            $table->boolean('retryable')->nullable()->after('partition_key');
            $table->timestampTz('backoff_until')->nullable()->after('retryable');
        });

        Schema::table('aleph_ingestion_failures', function (Blueprint $table): void {
            $table->timestampTz('resolved_at')->nullable()->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('aleph_ingestion_failures', function (Blueprint $table): void {
            $table->dropColumn('resolved_at');
        });

        Schema::table('aleph_ingestion_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retry_of_id');
            $table->dropColumn(['retry_reason', 'partition_key', 'retryable', 'backoff_until']);
        });
    }
};
