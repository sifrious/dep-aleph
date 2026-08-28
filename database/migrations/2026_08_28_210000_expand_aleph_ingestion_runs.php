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
            $table->string('connector_id')->nullable()->after('id')->index();
            $table->ulid('source_installation_id')->nullable()->after('connector_id')->index();
            $table->string('legacy_reference')->nullable()->after('source_installation_id')->unique();
            $table->string('idempotency_key')->nullable()->after('legacy_reference')->unique();
            $table->string('completeness')->default('incomplete')->after('status');
            $table->json('checkpoint')->nullable()->after('parameters');
            $table->json('failure')->nullable()->after('error');
            $table->json('accepted_references')->nullable()->after('failure');
        });
    }

    public function down(): void
    {
        Schema::table('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'connector_id',
                'source_installation_id',
                'legacy_reference',
                'idempotency_key',
                'completeness',
                'checkpoint',
                'failure',
                'accepted_references',
            ]);
        });
    }
};
