<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_connector_health_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('check');
            $table->string('status');
            $table->text('message');
            $table->json('metrics')->nullable();
            $table->json('remediation')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampTz('expires_at');
            $table->index(['source_installation_id', 'check', 'checked_at'], 'aleph_health_check_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_connector_health_checks');
    }
};
