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
        Schema::create('sync_conflicts', function (Blueprint $table) {
        $table->id();

        $table->unsignedBigInteger('store_id');
        $table->string('table_name');
        $table->unsignedBigInteger('record_id');

        $table->json('local_data');
        $table->json('cloud_data');

        $table->enum('resolution_strategy', [
            'last-write-wins',
            'cloud-wins',
            'manual',
        ])->default('manual');

        $table->enum('resolution_status', [
            'unresolved',
            'resolved',
        ])->default('unresolved');

        $table->unsignedBigInteger('resolved_by')->nullable();
        $table->timestamp('resolved_at')->nullable();

        $table->timestamp('created_at')->useCurrent();

        $table->index(['store_id', 'resolution_status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
