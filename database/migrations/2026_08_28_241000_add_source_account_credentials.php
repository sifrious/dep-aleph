<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aleph_connector_installations', function (Blueprint $table): void {
            $table->string('external_account_id')->nullable()->after('label');
            $table->string('funes_source_account_id')->nullable()->after('external_account_id');
            $table->unique(['connector_id', 'external_account_id']);
            $table->unique('funes_source_account_id');
        });

        Schema::create('aleph_connector_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('kind');
            $table->text('material');
            $table->json('scopes')->nullable();
            $table->text('refresh_metadata')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique('source_installation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_connector_credentials');

        Schema::table('aleph_connector_installations', function (Blueprint $table): void {
            $table->dropUnique(['connector_id', 'external_account_id']);
            $table->dropUnique(['funes_source_account_id']);
            $table->dropColumn(['external_account_id', 'funes_source_account_id']);
        });
    }
};
