<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cash_drawers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('start_user_id');
            $table->unsignedBigInteger('end_user_id')->nullable();
            $table->double('start_amount');
            $table->double('end_amount')->nullable();
            $table->double('owner_withdraw_amount')->nullable();
            $table->string('currency_code', 3)->default('VND');
            $table->string('status', 10)->default('open');
            $table->text('note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_drawers');
    }
};
