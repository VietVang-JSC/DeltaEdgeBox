<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_status')) {
            Schema::create('payment_status', function (Blueprint $table) {
                $table->id();
                $table->integer('value');
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('store_payment_settings')) {
            Schema::create('store_payment_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->enum('type', ['status', 'method']);
                $table->unsignedBigInteger('ref_id');
                $table->boolean('is_show')->default(true);
                $table->integer('sort_rank')->default(0);
                $table->timestamps();

                $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
                $table->unique(['store_id', 'type', 'ref_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_settings');
        Schema::dropIfExists('payment_status');
    }
};