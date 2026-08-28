<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_source_scope_associations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('association_key')->unique();
            $table->ulid('source_installation_id')->index();
            $table->string('stream')->nullable()->index();
            $table->string('scope_type');
            $table->string('scope_id');
            $table->string('role')->nullable();
            $table->string('state');
            $table->json('metadata');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['source_installation_id', 'stream', 'state'], 'aleph_scope_source_stream_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_source_scope_associations');
    }
};
