<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('table') && !Schema::hasTable('tables')) {
            Schema::rename('table', 'tables');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tables') && !Schema::hasTable('table')) {
            Schema::rename('tables', 'table');
        }
    }
};