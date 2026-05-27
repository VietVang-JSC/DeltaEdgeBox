<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $cloudTable = 'table';
    private string $edgeTable = 'tables';

    public function up(): void
    {
        if (!Schema::hasTable($this->cloudTable) && Schema::hasTable($this->edgeTable)) {
            Schema::rename($this->edgeTable, $this->cloudTable);
        }

        if (!Schema::hasTable($this->cloudTable)) {
            Schema::create($this->cloudTable, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->string('tablename');
                $table->text('listitem')->nullable();
                $table->string('image')->nullable();
                $table->integer('status');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->integer('admin_id');
                $table->tinyInteger('is_show')->default(1);
                $table->integer('sort_rank')->nullable();
                $table->string('userordered')->nullable();
                $table->text('booking_code')->nullable();
                $table->dateTime('lock_time')->nullable();
                $table->string('qr_token')->nullable();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->integer('number_of_people')->default(0);
                $table->boolean('can_order')->default(1);
                $table->string('qr_code')->nullable();
                $table->boolean('is_order_enabled')->default(false);
                $table->string('pin', 6)->nullable();
                $table->string('qr_code_token')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['store_id', 'status']);
            });

            return;
        }

        Schema::table($this->cloudTable, function (Blueprint $table) {
            if (!Schema::hasColumn($this->cloudTable, 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn($this->cloudTable, 'tablename')) {
                $table->string('tablename')->nullable()->after('store_id');
            }
            if (!Schema::hasColumn($this->cloudTable, 'listitem')) {
                $table->longText('listitem')->nullable()->after('tablename');
            }
            if (!Schema::hasColumn($this->cloudTable, 'image')) {
                $table->string('image')->nullable()->after('listitem');
            }
            if (!Schema::hasColumn($this->cloudTable, 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('status')->index();
            }
            if (!Schema::hasColumn($this->cloudTable, 'is_show')) {
                $table->tinyInteger('is_show')->default(1)->after('admin_id');
            }
            if (!Schema::hasColumn($this->cloudTable, 'sort_rank')) {
                $table->integer('sort_rank')->nullable()->after('is_show');
            }
            if (!Schema::hasColumn($this->cloudTable, 'userordered')) {
                $table->string('userordered')->nullable()->after('sort_rank');
            }
            if (!Schema::hasColumn($this->cloudTable, 'booking_code')) {
                $table->text('booking_code')->nullable()->after('userordered');
            }
            if (!Schema::hasColumn($this->cloudTable, 'lock_time')) {
                $table->dateTime('lock_time')->nullable()->after('booking_code');
            }
            if (!Schema::hasColumn($this->cloudTable, 'qr_token')) {
                $table->string('qr_token')->nullable()->after('lock_time');
            }
            if (!Schema::hasColumn($this->cloudTable, 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('qr_token')->index();
            }
            if (!Schema::hasColumn($this->cloudTable, 'number_of_people')) {
                $table->integer('number_of_people')->default(0)->after('payment_id');
            }
            if (!Schema::hasColumn($this->cloudTable, 'can_order')) {
                $table->boolean('can_order')->default(1)->after('number_of_people');
            }
            if (!Schema::hasColumn($this->cloudTable, 'qr_code')) {
                $table->string('qr_code')->nullable()->after('can_order');
            }
            if (!Schema::hasColumn($this->cloudTable, 'is_order_enabled')) {
                $table->boolean('is_order_enabled')->default(false)->after('qr_code');
            }
            if (!Schema::hasColumn($this->cloudTable, 'pin')) {
                $table->string('pin', 6)->nullable()->after('is_order_enabled');
            }
            if (!Schema::hasColumn($this->cloudTable, 'qr_code_token')) {
                $table->string('qr_code_token')->nullable()->after('pin')->index();
            }
            if (!Schema::hasColumn($this->cloudTable, 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (Schema::hasColumn($this->cloudTable, 'name') && Schema::hasColumn($this->cloudTable, 'tablename')) {
            DB::table($this->cloudTable)
                ->whereNull('tablename')
                ->update(['tablename' => DB::raw('name')]);
        }

        foreach (['note', 'capacity', 'code', 'name'] as $column) {
            if (Schema::hasColumn($this->cloudTable, $column)) {
                Schema::table($this->cloudTable, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable($this->cloudTable)) {
            return;
        }

        foreach ([
            'deleted_at',
            'qr_code_token',
            'pin',
            'is_order_enabled',
            'qr_code',
            'can_order',
            'number_of_people',
            'payment_id',
            'qr_token',
            'lock_time',
            'booking_code',
            'userordered',
            'sort_rank',
            'is_show',
            'user_id',
            'image',
            'listitem',
            'tablename',
        ] as $column) {
            if (Schema::hasColumn($this->cloudTable, $column)) {
                Schema::table($this->cloudTable, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if (!Schema::hasTable($this->edgeTable)) {
            Schema::rename($this->cloudTable, $this->edgeTable);
        }
    }
};
