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
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->string('resolution_strategy', 50)->default('manual')->change();
            $table->string('resolution_status', 50)->default('unresolved')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->enum('resolution_strategy', [
                'last-write-wins',
                'cloud-wins',
                'manual',
            ])->default('manual')->change();

            $table->enum('resolution_status', [
                'unresolved',
                'resolved',
            ])->default('unresolved')->change();
        });
    }
};
