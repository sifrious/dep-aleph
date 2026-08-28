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
        Schema::table('aleph_ingestion_attempts', function (Blueprint $table): void {
            $table->string('queue')->nullable()->after('number');
            $table->unsignedSmallInteger('priority')->nullable()->after('queue');
            $table->json('tags')->nullable()->after('priority');
            $table->json('dispatch_policy')->nullable()->after('tags');
            $table->string('queue_job_id')->nullable()->after('dispatch_policy');
            $table->string('worker_id')->nullable()->after('queue_job_id');
            $table->string('error_class')->nullable()->after('failure');
            $table->text('error_message')->nullable()->after('error_class');
            $table->timestampTz('queued_at')->nullable()->after('error_message');
            $table->timestampTz('started_at')->nullable()->change();
            $table->timestampTz('heartbeat_at')->nullable()->after('started_at');
            $table->index(['status', 'heartbeat_at'], 'aleph_attempt_heartbeat');
            $table->index(['queue', 'priority'], 'aleph_attempt_queue');
        });
    }

    public function down(): void
    {
        DB::table('aleph_ingestion_attempts')->whereNull('started_at')->update(['started_at' => now()]);

        Schema::table('aleph_ingestion_attempts', function (Blueprint $table): void {
            $table->dropIndex('aleph_attempt_heartbeat');
            $table->dropIndex('aleph_attempt_queue');
            $table->timestampTz('started_at')->nullable(false)->change();
            $table->dropColumn([
                'queue',
                'priority',
                'tags',
                'dispatch_policy',
                'queue_job_id',
                'worker_id',
                'error_class',
                'error_message',
                'queued_at',
                'heartbeat_at',
            ]);
        });
    }
};
