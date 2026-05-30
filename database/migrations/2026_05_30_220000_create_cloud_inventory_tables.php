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
        // 1. Table: inventories
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->index();
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(10);
            $table->timestamp('last_restocked_at')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index(['store_id', 'quantity']);

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // 2. Table: inventory_histories
        Schema::create('inventory_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('input_id')->index();
            $table->string('input_code');
            $table->date('input_date');
            $table->integer('quantity');
            $table->unsignedBigInteger('admin_id')->index();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // 3. Table: check_inventory
        Schema::create('check_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('inventory_check_code');
            $table->date('inventory_check_date')->default(now());
            $table->integer('total_discrepancies');
            $table->integer('increased_discrepancy_quantity');
            $table->integer('decreased_discrepancy_quantity');
            $table->enum('check_inventory_status', ['draft', 'temporary', 'success'])->default('draft');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_init')->index();
            $table->unsignedBigInteger('user_upd')->nullable()->index();
            $table->unsignedBigInteger('admin_id');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });

        // 4. Table: check_inventory_items
        Schema::create('check_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('check_inventory_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->integer('inventory_number');
            $table->integer('actual_quantity');
            $table->integer('discrepancy_quantity');
            $table->unsignedBigInteger('admin_id');
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('check_inventory_id')->references('id')->on('check_inventory')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_inventory_items');
        Schema::dropIfExists('check_inventory');
        Schema::dropIfExists('inventory_histories');
        Schema::dropIfExists('inventories');
    }
};
