<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_linear_webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('source_installation_id');
            $table->string('delivery_id');
            $table->string('event');
            $table->string('payload_hash', 64);
            $table->text('payload');
            $table->json('accepted_references')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['source_installation_id', 'delivery_id'], 'aleph_linear_webhook_installation_delivery_unique');
            $table->foreign('source_installation_id')->references('id')->on('aleph_connector_installations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_linear_webhook_deliveries');
    }
};
