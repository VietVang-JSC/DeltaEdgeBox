<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function created(Order $order): void
    {
        $this->queueForSync($order, 'create');
    }

    public function updated(Order $order): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($order, 'update');
        }
    }

    public function deleted(Order $order): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($order, 'delete', 2); // Urgent
        }
    }

    protected function queueForSync(Order $order, string $operation, int $priority = 1): void
    {
        try {
            $data = $order->toArray();
            
            // Format dates to 'Y-m-d H:i:s' to prevent ISO-8601 errors on Cloud BE
            $dateAttributes = ['created_at', 'updated_at', 'deleted_at'];
            foreach ($dateAttributes as $attr) {
                if (!empty($order->$attr) && $order->$attr instanceof \DateTimeInterface) {
                    $data[$attr] = $order->$attr->format('Y-m-d H:i:s');
                }
            }

            $this->syncService->queueForSync(
                table: 'orders',
                operation: $operation,
                recordId: $order->id,
                data: $data,
                priority: $priority
            );

            Log::debug("Order {$operation} queued for sync", [
                'order_id' => $order->id,
                'operation' => $operation
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to queue order for sync", [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
