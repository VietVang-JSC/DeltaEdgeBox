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
        Schema::create('combo_product', function (Blueprint $table) {
           $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('combo_product_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('is_required');
            $table->integer('quantity');
            $table->unsignedBigInteger('admin_id');
            $table->timestamps();

            // Foreign keys (tuỳ chọn)
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
            $table->foreign('combo_product_id')->references('id')->on('combo_products')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_product');
    }
};
