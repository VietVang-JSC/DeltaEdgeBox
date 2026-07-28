<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->string('failure_type')->nullable()->after('last_error');
            $table->string('error_code')->nullable()->after('failure_type');
            $table->boolean('retryable')->nullable()->after('error_code');
            $table->index(['status', 'failure_type'], 'sync_queue_failure_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->dropIndex('sync_queue_failure_type_index');
            $table->dropColumn(['failure_type', 'error_code', 'retryable']);
        });
    }
};
