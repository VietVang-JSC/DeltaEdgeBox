<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Table;

class TableReleaseService
{
    public function releaseForPayment(Payment $payment, int $storeId): int
    {
        if (! $payment->table_id) {
            return 0;
        }

        return Table::query()
            ->whereKey($payment->table_id)
            ->where('store_id', $storeId)
            ->where('payment_id', $payment->id)
            ->update([
                'status' => 1,
                'user_id' => null,
                'listitem' => null,
                'userordered' => null,
                'qr_token' => null,
                'lock_time' => null,
                'payment_id' => null,
                'number_of_people' => 0,
                'can_order' => 1,
                'is_order_enabled' => 1,
                'pin' => null,
            ]);
    }
}
