<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_github_webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('delivery_id');
            $table->string('event');
            $table->string('payload_hash');
            $table->text('payload');
            $table->json('accepted_references')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unique(['source_installation_id', 'delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_github_webhook_deliveries');
    }
};
