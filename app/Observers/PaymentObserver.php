<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class PaymentObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        $this->queueForSync($payment, 'create');
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        // Only queue if deployment mode is offline-first
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($payment, 'update');
        }
    }

    /**
     * Handle the Payment "deleted" event.
     */
    public function deleted(Payment $payment): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($payment, 'delete');
        }
    }

    /**
     * Queue payment for sync
     */
    protected function queueForSync(Payment $payment, string $operation): void
    {
        try {
            $priority = ($operation === 'delete') ? 2 : 1; // Urgent for deletions

            $data = $payment->toArray();
            
            // Format dates to 'Y-m-d H:i:s' to prevent ISO-8601 errors on Cloud BE
            $dateAttributes = ['created_at', 'updated_at', 'deleted_at', 'paid_date'];
            foreach ($dateAttributes as $attr) {
                if (!empty($payment->$attr) && $payment->$attr instanceof \DateTimeInterface) {
                    $data[$attr] = $payment->$attr->format('Y-m-d H:i:s');
                }
            }

            $this->syncService->queueForSync(
                table: 'payments',
                operation: $operation,
                recordId: $payment->id,
                data: $data,
                priority: $priority
            );

            Log::debug("Payment {$operation} queued for sync", [
                'payment_id' => $payment->id,
                'operation' => $operation
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to queue payment for sync", [
                'payment_id' => $payment->id,
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
        }
    }
}
