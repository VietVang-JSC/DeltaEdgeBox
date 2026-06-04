<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sync_conflicts')) {
            return;
        }

        Schema::table('sync_conflicts', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_conflicts', 'sync_queue_id')) {
                $table->unsignedBigInteger('sync_queue_id')->nullable()->after('store_id');
            }

            if (!Schema::hasColumn('sync_conflicts', 'cloud_conflict_id')) {
                $table->unsignedBigInteger('cloud_conflict_id')->nullable()->after('sync_queue_id');
            }

            if (!Schema::hasColumn('sync_conflicts', 'operation_type')) {
                $table->string('operation_type')->nullable()->after('record_id');
            }

            if (!Schema::hasColumn('sync_conflicts', 'error_message')) {
                $table->text('error_message')->nullable()->after('resolution_status');
            }

            if (!Schema::hasColumn('sync_conflicts', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }

            $table->index(['sync_queue_id'], 'sync_conflicts_queue_id_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sync_conflicts')) {
            return;
        }

        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->dropIndex('sync_conflicts_queue_id_index');

            foreach (['sync_queue_id', 'cloud_conflict_id', 'operation_type', 'error_message', 'updated_at'] as $column) {
                if (Schema::hasColumn('sync_conflicts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
