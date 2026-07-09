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
            $data = $table->toArray();
            
            // Format dates to 'Y-m-d H:i:s' to prevent ISO-8601 errors on Cloud BE
            $dateAttributes = ['created_at', 'updated_at', 'deleted_at', 'lock_time'];
            foreach ($dateAttributes as $attr) {
                if (!empty($table->$attr) && $table->$attr instanceof \DateTimeInterface) {
                    $data[$attr] = $table->$attr->format('Y-m-d H:i:s');
                }
            }

            $this->syncService->queueForSync(
                table: 'table',
                operation: $operation,
                recordId: $table->id,
                data: $data,
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
