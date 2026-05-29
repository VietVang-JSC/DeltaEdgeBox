<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'vat')) {
                $table->integer('vat')->default(0)->nullable();
            }
            if (!Schema::hasColumn('products', 'price_after_tax')) {
                $table->double('price_after_tax')->default(0)->nullable();
            }
            if (!Schema::hasColumn('products', 'min_quantity')) {
                $table->integer('min_quantity')->default(0)->nullable();
            }
            if (!Schema::hasColumn('products', 'product_group_id')) {
                $table->unsignedBigInteger('product_group_id')->nullable();
            }
            if (!Schema::hasColumn('products', 'type_final_product')) {
                $table->integer('type_final_product')->nullable();
            }
            if (!Schema::hasColumn('products', 'type_commodity')) {
                $table->integer('type_commodity')->nullable();
            }
            if (!Schema::hasColumn('products', 'is_extra')) {
                $table->boolean('is_extra')->default(false)->nullable();
            }
            if (!Schema::hasColumn('products', 'check')) {
                $table->string('check')->nullable();
            }
            if (!Schema::hasColumn('products', 'second_product_code')) {
                $table->string('second_product_code')->nullable();
            }
            if (!Schema::hasColumn('products', 'third_product_code')) {
                $table->string('third_product_code')->nullable();
            }
            if (!Schema::hasColumn('products', 'type_id')) {
                $table->unsignedBigInteger('type_id')->nullable();
            }
            if (!Schema::hasColumn('products', 'inventory_required')) {
                $table->boolean('inventory_required')->default(false)->nullable();
            }
            if (!Schema::hasColumn('products', 'number_of_options')) {
                $table->integer('number_of_options')->default(0)->nullable();
            }
            if (!Schema::hasColumn('products', 'is_ingredient')) {
                $table->boolean('is_ingredient')->default(false)->nullable();
            }
            if (!Schema::hasColumn('products', 'print_id')) {
                $table->unsignedBigInteger('print_id')->nullable();
            }
            if (!Schema::hasColumn('products', 'sort_rank')) {
                $table->integer('sort_rank')->default(0)->nullable();
            }
            if (!Schema::hasColumn('products', 'is_show')) {
                $table->boolean('is_show')->default(true)->nullable();
            }
            if (!Schema::hasColumn('products', 'title_vi')) {
                $table->string('title_vi')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = [
                'vat', 'price_after_tax', 'min_quantity', 'product_group_id',
                'type_final_product', 'type_commodity', 'is_extra', 'check',
                'second_product_code', 'third_product_code', 'type_id',
                'inventory_required', 'number_of_options', 'is_ingredient',
                'print_id', 'sort_rank', 'is_show', 'title_vi'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
