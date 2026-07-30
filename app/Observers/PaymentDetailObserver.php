<?php

namespace App\Observers;

use App\Models\PaymentDetail;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class PaymentDetailObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function created(PaymentDetail $paymentDetail): void
    {
        $this->queueForSync($paymentDetail, 'create');
    }

    public function updated(PaymentDetail $paymentDetail): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($paymentDetail, 'update');
        }
    }

    public function deleted(PaymentDetail $paymentDetail): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($paymentDetail, 'delete');
        }
    }

    protected function queueForSync(PaymentDetail $paymentDetail, string $operation): void
    {
        try {
            $data = $paymentDetail->toArray();
            // Include product_code so cloud can recover product_id if IDs differ
            if (empty($data['product_code'])) {
                $product = $paymentDetail->product;
                $data['product_code'] = $product ? $product->code : null;
            }
            $this->syncService->queueForSync(
                table: 'payment_details',
                operation: $operation,
                recordId: $paymentDetail->id,
                data: $data
            );
        } catch (\Exception $e) {
            Log::error('Failed to queue payment detail for sync', [
                'payment_detail_id' => $paymentDetail->id,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
