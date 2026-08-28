<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_slack_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_installation_id')->constrained('aleph_connector_installations')->cascadeOnDelete();
            $table->string('workspace_reference');
            $table->string('account_reference')->nullable();
            $table->string('secret_reference')->nullable();
            $table->json('scopes')->nullable();
            $table->string('state');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->string('legacy_reference')->unique();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique('source_installation_id');
            $table->index(['workspace_reference', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_slack_credentials');
    }
};
