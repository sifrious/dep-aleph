<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_connector_installations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('connector_id')->index();
            $table->string('connector_version');
            $table->string('label');
            $table->boolean('enabled')->default(true);
            $table->text('configuration');
            $table->string('credentials_reference')->nullable();
            $table->string('owner')->nullable()->index();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['connector_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_connector_installations');
    }
};
