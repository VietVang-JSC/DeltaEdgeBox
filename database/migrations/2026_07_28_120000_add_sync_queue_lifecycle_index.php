<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->index(
                ['store_id', 'table_name', 'record_id', 'status'],
                'sync_queue_lifecycle_index'
            );
        });
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->index(
                ['store_id', 'table_name', 'record_id', 'resolution_status'],
                'sync_conflict_lifecycle_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->dropIndex('sync_queue_lifecycle_index');
        });
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->dropIndex('sync_conflict_lifecycle_index');
        });
    }
};
