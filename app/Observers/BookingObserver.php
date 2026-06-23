<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\SyncService;
use Illuminate\Support\Facades\Log;

class BookingObserver
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function created(Booking $booking): void
    {
        $this->queueForSync($booking, 'create');
    }

    public function updated(Booking $booking): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($booking, 'update');
        }
    }

    public function deleted(Booking $booking): void
    {
        if (config('app.deployment_mode') === 'offline-first') {
            $this->queueForSync($booking, 'delete');
        }
    }

    protected function queueForSync(Booking $booking, string $operation): void
    {
        // Auto-sync disabled — sync only via cloud button or CLI command
        return;

        try {
            $this->syncService->queueForSync(
                table: 'bookings',
                operation: $operation,
                recordId: $booking->id,
                data: $booking->toArray(),
                priority: $operation === 'delete' ? 2 : 1
            );
        } catch (\Exception $e) {
            Log::error("Booking {$operation} queue failed", [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
