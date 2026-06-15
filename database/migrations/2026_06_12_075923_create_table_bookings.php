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
        Schema::create('bookings', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('booking_code');

            $table->integer('user_id');
            $table->integer('admin_id');

            $table->dateTime('time_arrival');

            $table->integer('status');

            $table->integer('customer_id');

            $table->text('table_id')->nullable();

            $table->string('note')->nullable();

            $table->text('total_customer');

            $table->text('item_list')->nullable();

            $table->double('use_time', 8, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
