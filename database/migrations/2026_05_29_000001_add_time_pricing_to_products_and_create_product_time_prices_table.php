<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasColumn('products', 'is_restricted_time')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_restricted_time')->default(false)->after('is_combo');
            });
        }

        if (!Schema::hasTable('product_time_prices')) {
            Schema::create('product_time_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('store_id');
                $table->time('start_time');
                $table->time('end_time');
                $table->decimal('price', 15, 2);
                $table->decimal('price_after_tax', 15, 2)->nullable();
                $table->integer('priority')->default(0);
                $table->unsignedTinyInteger('days_of_week_mask')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_time_prices');
        if (Schema::hasColumn('products', 'is_restricted_time')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('is_restricted_time');
            });
        }
    }
};
