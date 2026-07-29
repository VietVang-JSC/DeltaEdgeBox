<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PaymentDetailCanonicalizer
{
    public function canonicalize(Payment $payment, Collection $details): Collection
    {
        $groups = $details
            ->filter(static fn ($detail): bool => $detail->deleted_at === null)
            ->sortBy('id')
            ->values()
            ->groupBy(
                static fn ($detail): string => ! empty($detail->product_key)
                    ? 'product_key:'.$detail->product_key
                    : 'detail_id:'.$detail->id
            );

        return $groups->map(function (Collection $candidates) use ($payment) {
            if ($candidates->count() === 1) {
                return $candidates->first();
            }

            $canonical = $candidates->sort(function ($left, $right): int {
                $leftUpdated = $left->updated_at ? Carbon::parse($left->updated_at)->getTimestamp() : 0;
                $rightUpdated = $right->updated_at ? Carbon::parse($right->updated_at)->getTimestamp() : 0;
                if ($leftUpdated !== $rightUpdated) {
                    return $rightUpdated <=> $leftUpdated;
                }

                return (int) $right->id <=> (int) $left->id;
            })->first();

            Log::warning('PAYMENT_RECEIPT_DUPLICATE_DETAILS_CANONICALIZED', [
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'store_id' => $payment->store_id,
                'product_key' => $canonical->product_key,
                'candidate_ids' => $candidates->pluck('id')->map(
                    static fn ($id): int => (int) $id
                )->values()->all(),
                'canonical_id' => (int) $canonical->id,
                'source' => 'edge',
            ]);

            return $canonical;
        })->values();
    }
}
