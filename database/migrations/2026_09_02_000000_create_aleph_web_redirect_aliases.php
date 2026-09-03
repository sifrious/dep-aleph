<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_web_redirect_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('source_reference');
            $table->text('requested_url');
            $table->char('requested_hash', 64);
            $table->text('final_url');
            $table->char('final_hash', 64);
            $table->ulid('observation_id');
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique(['source_reference', 'requested_hash']);
            $table->index(['source_reference', 'final_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_web_redirect_aliases');
    }
};
