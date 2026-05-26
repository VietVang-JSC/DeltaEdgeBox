<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->index();
                $table->string('name');
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['store_id', 'value']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
