<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'payment_code')) {
                    $table->string('payment_code')->nullable()->after('id');
                }
                if (!Schema::hasColumn('payments', 'parent_id')) {
                    $table->unsignedBigInteger('parent_id')->nullable()->after('payment_code');
                }
                if (!Schema::hasColumn('payments', 'reason')) {
                    $table->string('reason')->nullable()->after('customer_id');
                }
                if (!Schema::hasColumn('payments', 'items')) {
                    $table->longText('items')->nullable()->after('reason');
                }
                if (!Schema::hasColumn('payments', 'surcharge')) {
                    $table->double('surcharge')->nullable()->after('discount');
                }
                if (!Schema::hasColumn('payments', 'surcharge_reason')) {
                    $table->string('surcharge_reason')->nullable()->after('surcharge');
                }
                if (!Schema::hasColumn('payments', 'surcharge_percent')) {
                    $table->integer('surcharge_percent')->nullable()->after('surcharge_reason');
                }
                if (!Schema::hasColumn('payments', 'service_charge')) {
                    $table->integer('service_charge')->nullable()->after('surcharge_percent');
                }
                if (!Schema::hasColumn('payments', 'service_charge_amount')) {
                    $table->decimal('service_charge_amount', 16, 4)->nullable()->after('service_charge');
                }
                if (!Schema::hasColumn('payments', 'admin_id')) {
                    $table->unsignedBigInteger('admin_id')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('payments', 'financial_id')) {
                    $table->unsignedBigInteger('financial_id')->nullable()->after('admin_id');
                }
                if (!Schema::hasColumn('payments', 'number_of_people')) {
                    $table->integer('number_of_people')->default(0)->after('financial_id');
                }
                if (!Schema::hasColumn('payments', 'type_discount')) {
                    $table->string('type_discount')->default('amount')->after('number_of_people');
                }
                if (!Schema::hasColumn('payments', 'discount_percent')) {
                    $table->integer('discount_percent')->default(0)->after('type_discount');
                }
                if (!Schema::hasColumn('payments', 'payment_transaction_id')) {
                    $table->text('payment_transaction_id')->nullable()->after('discount_percent');
                }
                if (!Schema::hasColumn('payments', 'sub_total_before_discount')) {
                    $table->decimal('sub_total_before_discount', 16, 4)->nullable()->after('payment_transaction_id');
                }
                if (!Schema::hasColumn('payments', 'total_incl_vat_before_discount')) {
                    $table->decimal('total_incl_vat_before_discount', 16, 4)->nullable()->after('sub_total_before_discount');
                }
                if (!Schema::hasColumn('payments', 'is_printed')) {
                    $table->boolean('is_printed')->default(false)->after('status');
                }
                if (!Schema::hasColumn('payments', 'invoice_sent_status')) {
                    $table->boolean('invoice_sent_status')->default(false)->after('is_printed');
                }
                if (!Schema::hasColumn('payments', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        if (Schema::hasTable('payment_details')) {
            Schema::table('payment_details', function (Blueprint $table) {
                if (!Schema::hasColumn('payment_details', 'product_key')) {
                    $table->string('product_key')->nullable()->after('product_id');
                }
                if (!Schema::hasColumn('payment_details', 'product_extra')) {
                    $table->text('product_extra')->nullable()->after('note');
                }
                if (!Schema::hasColumn('payment_details', 'optional_products')) {
                    $table->text('optional_products')->nullable()->after('product_extra');
                }
                if (!Schema::hasColumn('payment_details', 'inventory_histories')) {
                    $table->text('inventory_histories')->nullable()->after('optional_products');
                }
                if (!Schema::hasColumn('payment_details', 'input_code')) {
                    $table->string('input_code')->nullable()->after('inventory_histories');
                }
                if (!Schema::hasColumn('payment_details', 'admin_id')) {
                    $table->unsignedBigInteger('admin_id')->nullable()->after('input_code');
                }
                if (!Schema::hasColumn('payment_details', 'store_id')) {
                    $table->unsignedBigInteger('store_id')->nullable()->index()->after('admin_id');
                }
                if (!Schema::hasColumn('payment_details', 'debt')) {
                    $table->double('debt')->nullable()->after('store_id');
                }
                if (!Schema::hasColumn('payment_details', 'status')) {
                    $table->tinyInteger('status')->nullable()->after('debt');
                }
                if (!Schema::hasColumn('payment_details', 'delete_note')) {
                    $table->string('delete_note')->nullable()->after('status');
                }
                if (!Schema::hasColumn('payment_details', 'detail_discount')) {
                    $table->double('detail_discount')->default(0)->after('delete_note');
                }
                if (!Schema::hasColumn('payment_details', 'served')) {
                    $table->boolean('served')->default(false)->after('detail_discount');
                }
                if (!Schema::hasColumn('payment_details', 'tax_amount')) {
                    $table->double('tax_amount')->default(0)->after('served');
                }
                if (!Schema::hasColumn('payment_details', 'detail_discount_excluding_tax')) {
                    $table->double('detail_discount_excluding_tax')->default(0)->after('tax_amount');
                }
                if (!Schema::hasColumn('payment_details', 'unit_price_excluding_tax')) {
                    $table->double('unit_price_excluding_tax')->nullable()->after('detail_discount_excluding_tax');
                }
                if (!Schema::hasColumn('payment_details', 'discounted_price_excluding_tax')) {
                    $table->double('discounted_price_excluding_tax')->nullable()->after('unit_price_excluding_tax');
                }
                if (!Schema::hasColumn('payment_details', 'printed_quantity')) {
                    $table->integer('printed_quantity')->default(0)->after('discounted_price_excluding_tax');
                }
                if (!Schema::hasColumn('payment_details', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_details')) {
            Schema::table('payment_details', function (Blueprint $table) {
                $this->dropColumnsIfExist('payment_details', $table, [
                    'product_key',
                    'product_extra',
                    'optional_products',
                    'inventory_histories',
                    'input_code',
                    'admin_id',
                    'store_id',
                    'debt',
                    'status',
                    'delete_note',
                    'detail_discount',
                    'served',
                    'tax_amount',
                    'detail_discount_excluding_tax',
                    'unit_price_excluding_tax',
                    'discounted_price_excluding_tax',
                    'printed_quantity',
                    'deleted_at',
                ]);
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $this->dropColumnsIfExist('payments', $table, [
                    'payment_code',
                    'parent_id',
                    'reason',
                    'items',
                    'surcharge',
                    'surcharge_reason',
                    'surcharge_percent',
                    'service_charge',
                    'service_charge_amount',
                    'admin_id',
                    'financial_id',
                    'number_of_people',
                    'type_discount',
                    'discount_percent',
                    'payment_transaction_id',
                    'sub_total_before_discount',
                    'total_incl_vat_before_discount',
                    'is_printed',
                    'invoice_sent_status',
                    'deleted_at',
                ]);
            });
        }
    }

    private function dropColumnsIfExist(string $tableName, Blueprint $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($tableName, $column)) {
                $table->dropColumn($column);
            }
        }
    }
};
