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
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('code')->index();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->char('contact_number', 20)->nullable();
            $table->string('company_name')->nullable();
            $table->string('company_tax')->nullable();
            $table->string('address')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_init')->nullable()->index();
            $table->unsignedBigInteger('user_upd')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
