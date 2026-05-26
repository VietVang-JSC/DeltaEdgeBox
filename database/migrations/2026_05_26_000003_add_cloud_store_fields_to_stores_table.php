<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'service_level_id')) {
                $table->unsignedBigInteger('service_level_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('stores', 'province')) {
                $table->string('province')->nullable()->after('address');
            }

            if (!Schema::hasColumn('stores', 'storename')) {
                $table->string('storename')->nullable()->after('name');
            }

            if (!Schema::hasColumn('stores', 'referer_phone')) {
                $table->string('referer_phone')->nullable()->after('email');
            }

            if (!Schema::hasColumn('stores', 'note')) {
                $table->text('note')->nullable()->after('referer_phone');
            }

            if (!Schema::hasColumn('stores', 'currency')) {
                $table->string('currency')->default('VND')->after('note');
            }

            if (!Schema::hasColumn('stores', 'expiry_date')) {
                $table->timestamp('expiry_date')->nullable()->after('currency');
            }

            if (!Schema::hasColumn('stores', 'industry_id')) {
                $table->unsignedBigInteger('industry_id')->nullable()->after('expiry_date');
            }

            if (!Schema::hasColumn('stores', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('industry_id');
            }

            if (!Schema::hasColumn('stores', 'parent_id')) {
                $table->unsignedInteger('parent_id')->nullable()->after('company_id');
            }

            if (!Schema::hasColumn('stores', 'is_headquarters')) {
                $table->boolean('is_headquarters')->default(false)->after('parent_id');
            }

            if (!Schema::hasColumn('stores', 'line_user_id')) {
                $table->string('line_user_id')->nullable()->after('is_headquarters');
            }

            if (!Schema::hasColumn('stores', 'api_key')) {
                $table->string('api_key')->nullable()->after('line_user_id');
            }

            if (!Schema::hasColumn('stores', 'is_tax_included')) {
                $table->boolean('is_tax_included')->default(false)->after('api_key');
            }

            if (!Schema::hasColumn('stores', 'printer_host')) {
                $table->string('printer_host')->nullable()->after('is_tax_included');
            }

            if (!Schema::hasColumn('stores', 'time_zone')) {
                $table->enum('time_zone', [
                    'Asia/Ho_Chi_Minh',
                    'Asia/Tokyo',
                    'Asia/Manila',
                    'America/New_York',
                ])->default('Asia/Ho_Chi_Minh')->after('printer_host');
            }

            if (!Schema::hasColumn('stores', 'use_node_print_driver')) {
                $table->boolean('use_node_print_driver')->default(true)->after('time_zone');
            }

            if (!Schema::hasColumn('stores', 'type_check_qr')) {
                $table->enum('type_check_qr', ['pin', 'wifi'])->default('pin')->after('use_node_print_driver');
            }

            if (!Schema::hasColumn('stores', 'setting_print_kitchen')) {
                $table->json('setting_print_kitchen')->nullable()->after('type_check_qr');
            }

            if (!Schema::hasColumn('stores', 'current_ip')) {
                $table->string('current_ip')->nullable()->after('setting_print_kitchen');
            }

            if (!Schema::hasColumn('stores', 'service_charge')) {
                $table->integer('service_charge')->nullable()->after('current_ip');
            }

            if (!Schema::hasColumn('stores', 'deployment_mode')) {
                $table->string('deployment_mode', 32)->default('cloud-only')->after('service_charge');
            }

            if (!Schema::hasColumn('stores', 'edge_routing_active')) {
                $table->boolean('edge_routing_active')->default(false)->after('deployment_mode');
            }

            if (!Schema::hasColumn('stores', 'edge_box_url')) {
                $table->string('edge_box_url')->nullable()->after('edge_routing_active');
            }

            if (!Schema::hasColumn('stores', 'edge_box_store_id')) {
                $table->unsignedBigInteger('edge_box_store_id')->nullable()->after('edge_box_url');
            }

            if (!Schema::hasColumn('stores', 'edge_box_api_key')) {
                $table->text('edge_box_api_key')->nullable()->after('edge_box_store_id');
            }

            if (!Schema::hasColumn('stores', 'edge_enabled_at')) {
                $table->timestamp('edge_enabled_at')->nullable()->after('edge_box_api_key');
            }

            if (!Schema::hasColumn('stores', 'edge_config_version')) {
                $table->unsignedInteger('edge_config_version')->default(1)->after('edge_enabled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $columns = [
                'service_level_id',
                'province',
                'storename',
                'referer_phone',
                'note',
                'currency',
                'expiry_date',
                'industry_id',
                'company_id',
                'parent_id',
                'is_headquarters',
                'line_user_id',
                'api_key',
                'is_tax_included',
                'printer_host',
                'time_zone',
                'use_node_print_driver',
                'type_check_qr',
                'setting_print_kitchen',
                'current_ip',
                'service_charge',
                'deployment_mode',
                'edge_routing_active',
                'edge_box_url',
                'edge_box_store_id',
                'edge_box_api_key',
                'edge_enabled_at',
                'edge_config_version',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
