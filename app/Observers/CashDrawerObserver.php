<?php

namespace App\Observers;

use App\Models\CashDrawer;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class CashDrawerObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function created(CashDrawer $cashDrawer): void
    {
        $this->queueForSync($cashDrawer, 'create');
    }

    public function updated(CashDrawer $cashDrawer): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($cashDrawer, 'update');
        }
    }

    public function deleted(CashDrawer $cashDrawer): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($cashDrawer, 'delete');
        }
    }

    protected function queueForSync(CashDrawer $cashDrawer, string $operation): void
    {
        // Auto-sync disabled — sync only via cloud button or CLI command
        return;

        try {
            $this->syncService->queueForSync(
                table: 'cash_drawers',
                operation: $operation,
                recordId: $cashDrawer->id,
                data: $cashDrawer->toArray(),
                priority: $operation === 'delete' ? 2 : 1
            );
        } catch (\Exception $e) {
            Log::error("CashDrawer {$operation} queue failed", [
                'cash_drawer_id' => $cashDrawer->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
