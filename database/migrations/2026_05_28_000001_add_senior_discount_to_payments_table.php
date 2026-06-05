<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'is_senior_discount')) {
                $table->boolean('is_senior_discount')->default(false);
            }
            if (!Schema::hasColumn('payments', 'senior_discount_amount')) {
                $table->decimal('senior_discount_amount', 16, 4)->nullable()->after('is_senior_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_senior_discount', 'senior_discount_amount']);
        });
    }
};
