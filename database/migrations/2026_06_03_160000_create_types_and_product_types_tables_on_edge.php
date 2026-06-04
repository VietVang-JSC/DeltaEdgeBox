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
        if (!Schema::hasTable('types')) {
            Schema::create('types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('product_type_name');
                $table->unsignedBigInteger('admin_id');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_type')) {
            Schema::create('product_type', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('product_type_id');
                $table->string('product_type_attribute');
                $table->text('product_type_attribute_value');
                $table->unsignedBigInteger('admin_id');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_type');
        Schema::dropIfExists('types');
    }
};
