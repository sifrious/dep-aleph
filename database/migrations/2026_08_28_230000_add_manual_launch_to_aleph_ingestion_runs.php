<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->string('trigger')->default('system')->after('capability');
            $table->string('requested_by')->nullable()->after('trigger');
            $table->string('authorization_decision')->nullable()->after('requested_by');
            $table->timestampTz('requested_at')->nullable()->after('parameters');
            $table->timestampTz('started_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('aleph_ingestion_runs')->whereNull('started_at')->update(['started_at' => now()]);

        Schema::table('aleph_ingestion_runs', function (Blueprint $table): void {
            $table->timestampTz('started_at')->nullable(false)->change();
            $table->dropColumn(['trigger', 'requested_by', 'authorization_decision', 'requested_at']);
        });
    }
};
