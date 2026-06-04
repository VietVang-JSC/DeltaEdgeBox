<?php

namespace App\Observers;
use App\Services\SyncService;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }
    public function created(Customer $customer): void
    {
        $this->queueForSync($customer, 'create');
    }

    public function updated(Customer $customer): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($customer, 'update');
        }
    }

    public function deleted(Customer $customer): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($customer, 'delete');
        }
    }

    protected function queueForSync(Customer $customer, string $operation): void
    {
        try {
            $priority = ($operation === 'delete') ? 2 : 1;

            $this->syncService->queueForSync(
                table: 'customers',
                operation: $operation,
                recordId: $customer->id,
                data: $customer->toArray(),
                priority: $priority
            );

            Log::debug("Customer {$operation} queued for sync", [
                'customer_id' => $customer->id,
                'operation' => $operation
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to queue customer for sync", [
                'customer_id' => $customer->id,
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
        }
    }
}
