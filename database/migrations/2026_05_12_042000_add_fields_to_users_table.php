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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
            $table->tinyInteger('role')->default(2)->after('password'); // 1: admin, 2: staff
            $table->boolean('status')->default(true)->after('role');
            $table->string('phone')->nullable()->after('email');

            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropColumn(['store_id', 'role', 'status', 'phone']);
        });
    }
};
