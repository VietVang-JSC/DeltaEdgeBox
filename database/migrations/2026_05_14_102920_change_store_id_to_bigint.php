<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->change();
        });

        Schema::table('sync_metadata', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->change();
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->change();
        });

        Schema::table('print_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->change();
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_queues', function (Blueprint $table) {
            $table->string('store_id')->change();
        });

        Schema::table('sync_metadata', function (Blueprint $table) {
            $table->string('store_id')->change();
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->string('store_id')->change();
        });

        Schema::table('print_queue', function (Blueprint $table) {
            $table->string('store_id')->change();
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->string('store_id')->change();
        });
    }
};
