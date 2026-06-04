<?php

namespace App\Observers;

use App\Models\Table;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class TableObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Handle the Table "created" event.
     */
    public function created(Table $table): void
    {
        $this->queueForSync($table, 'create');
    }

    /**
     * Handle the Table "updated" event.
     */
    public function updated(Table $table): void
    {
        $this->queueForSync($table, 'update');
    }

    /**
     * Handle the Table "deleted" event.
     */
    public function deleted(Table $table): void
    {
        $this->queueForSync($table, 'delete', 2);
    }

    /**
     * Handle the Table "restored" event.
     */
    public function restored(Table $table): void
    {
        //
    }

    /**
     * Handle the Table "force deleted" event.
     */
    public function forceDeleted(Table $table): void
    {
        //
    }

    protected function queueForSync(Table $table, string $operation, int $priority = 1): void
    {
        try {
            $this->syncService->queueForSync(
                table: 'table',
                operation: $operation,
                recordId: $table->id,
                data: $table->toArray(),
                priority: $priority
            );

            Log::debug("Table {$operation} queued for sync", [
                'table_id' => $table->id,
                'operation' => $operation,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to queue table for sync', [
                'table_id' => $table->id,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
