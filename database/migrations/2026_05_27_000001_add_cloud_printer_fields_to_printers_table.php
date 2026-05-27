<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('printers')) {
            return;
        }

        Schema::table('printers', function (Blueprint $table) {
            if (!Schema::hasColumn('printers', 'active')) {
                $table->boolean('active')->default(true);
            }

            if (!Schema::hasColumn('printers', 'default')) {
                $table->boolean('default')->nullable();
            }

            if (!Schema::hasColumn('printers', 'paper_size')) {
                $table->enum('paper_size', ['80', '58'])->default('58');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('printers')) {
            return;
        }

        Schema::table('printers', function (Blueprint $table) {
            foreach (['active', 'default', 'paper_size'] as $column) {
                if (Schema::hasColumn('printers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
