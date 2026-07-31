<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('open_payment_mapping_outbox')) {
            return;
        }

        Schema::create('open_payment_mapping_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('source_table', 64);
            $table->string('local_id');
            $table->unsignedBigInteger('cloud_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'source_table', 'local_id'],
                'open_payment_mapping_outbox_local_unique'
            );
            $table->index(
                ['store_id', 'status', 'id'],
                'open_payment_mapping_outbox_pending_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_payment_mapping_outbox');
    }
};
