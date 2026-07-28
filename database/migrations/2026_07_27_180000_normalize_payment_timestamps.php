<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        foreach (['created_at', 'updated_at', 'paid_date'] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                $this->normalizeColumn($column);
            }
        }
    }

    public function down(): void
    {
        // Timestamp normalization is intentionally irreversible.
    }

    private function normalizeColumn(string $column): void
    {
        $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');

        DB::table('payments')
            ->select(['id', $column])
            ->whereNotNull($column)
            ->where($column, 'like', '%T%')
            ->orderBy('id')
            ->chunkById(500, function ($payments) use ($column, $timezone) {
                foreach ($payments as $payment) {
                    $value = $payment->{$column};
                    if (!is_string($value) || !$this->hasExplicitTimezone($value)) {
                        continue;
                    }

                    $normalized = CarbonImmutable::parse($value)
                        ->setTimezone($timezone)
                        ->format('Y-m-d H:i:s');

                    DB::table('payments')
                        ->where('id', $payment->id)
                        ->update([$column => $normalized]);
                }
            }, 'id');
    }

    private function hasExplicitTimezone(string $value): bool
    {
        return preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;
    }
};