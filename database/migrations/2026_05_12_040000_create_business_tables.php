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
        // Customers table
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('name');
            $table->string('phone');
            $table->string('total_money')->default('0');
            $table->string('point')->default('0');
            $table->integer('admin_id');
            $table->timestamps();

            $table->index(['store_id', 'phone']);
        });

        // Categories table
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('admin_id');
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
            $table->index(['store_id', 'status']);
        });

        // Products table
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->double('price')->default(0);
            $table->double('sale_price')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('unit')->default(1); // 1: item, 2: kg, 3: liter
            $table->boolean('status')->default(true);
            $table->boolean('is_combo')->default(false);
            $table->integer('admin_id');
            $table->timestamps();

            $table->index(['store_id', 'category_id', 'status']);
        });

        // Tables (bàn ăn) table
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->unique();
            $table->integer('capacity')->default(2);
            $table->tinyInteger('status')->default(0); // 0: empty, 1: occupied, 2: reserved
            $table->text('note')->nullable();
            $table->integer('admin_id');
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        // Payments table
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('table_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->dateTime('paid_date');
            $table->double('total')->default(0);
            $table->double('discount')->default(0);
            $table->double('tax')->default(0);
            $table->double('final_total')->default(0);
            $table->string('payment_method')->default('cash'); // cash, card, transfer
            $table->text('note')->nullable();
            $table->tinyInteger('status')->default(1); // 1: completed, 0: pending, 2: cancelled
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->index(['store_id', 'paid_date', 'status']);
        });

        // Payment details table
        Schema::create('payment_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->integer('quantity');
            $table->double('price')->default(0);
            $table->double('total')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
            $table->index(['payment_id', 'product_id']);
        });

        // Note: Users table already exists from default Laravel migration
        // If you need to modify it, create a separate migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_details');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('tables');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('customers');
        // Note: users table is managed by default Laravel migration
    }
};
