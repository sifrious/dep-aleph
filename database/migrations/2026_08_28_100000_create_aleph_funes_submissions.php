<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_funes_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('attempt_id')->nullable()->index();
            $table->string('idempotency_key')->index();
            $table->char('payload_hash', 64)->index();
            $table->string('status')->index();
            $table->string('accepted_type')->nullable();
            $table->string('accepted_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_funes_submissions');
    }
};
