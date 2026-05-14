<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // sync_queues table
        Schema::create('sync_queues', function (Blueprint $table) {
            $table->id();
            $table->string('store_id')->index();
            $table->string('table_name'); // payments, orders, customers, etc.
            $table->string('operation'); // create, update, delete
            $table->unsignedBigInteger('record_id');
            $table->text('payload'); // JSON encoded data
            $table->string('status')->default('pending')->index(); // pending, syncing, synced, failed, retrying
            $table->integer('priority')->default(1); // 0=low, 1=normal, 2=urgent
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(10);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->integer('response_code')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['store_id', 'status']);
        });

        // sync_metadata table
        Schema::create('sync_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('store_id')->unique();
            $table->timestamp('last_sync_timestamp')->nullable();
            $table->string('sync_status')->default('idle'); // idle, syncing, error
            $table->integer('pending_records_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // sync_logs table
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('store_id')->index();
            $table->string('table_name');
            $table->string('operation');
            $table->unsignedBigInteger('record_id');
            $table->string('status'); // success, failed, retry
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            // Index for querying logs by date
            $table->index(['store_id', 'synced_at']);
        });

        // print_queue table
        Schema::create('print_queue', function (Blueprint $table) {
            $table->id();
            $table->string('store_id')->index();
            $table->unsignedBigInteger('printer_id');
            $table->string('job_type'); // receipt, kitchen, barcode
            $table->text('content'); // JSON encoded print content
            $table->string('status')->default('pending')->index(); // pending, printing, completed, failed, retrying
            $table->integer('priority')->default(1); // 0=low, 1=normal, 2=urgent
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(5);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['printer_id', 'status']);
        });

        // printers table
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('store_id');
            $table->string('name');
            $table->string('printer_type'); // receipt, kitchen, barcode
            $table->string('connection_type'); // network, usb, bluetooth
            $table->string('ip_address')->nullable();
            $table->integer('port')->default(9100);
            $table->string('device_path')->nullable(); // For USB/serial
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_status_check')->nullable();
            $table->string('status')->default('online'); // online, offline, error
            $table->timestamps();

            $table->index('store_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
        Schema::dropIfExists('print_queue');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('sync_metadata');
        Schema::dropIfExists('sync_queues');
    }
};
