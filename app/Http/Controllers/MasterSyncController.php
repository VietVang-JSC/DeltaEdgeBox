<?php

namespace App\Http\Controllers;
use App\Services\MasterDataSyncService;
use App\Services\OpenPaymentMigrationService;

use Illuminate\Http\Request;
use App\Models\SyncMetadata;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\DB;

class MasterSyncController extends Controller
{
    protected MasterDataSyncService $masterSyncService;

    public function __construct(MasterDataSyncService $masterSyncService)
    {
        $this->masterSyncService = $masterSyncService;
    }

    public function sync(Request $request)
    {
        $result = $this->masterSyncService->syncMasterData($request->boolean('force', false));

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }

    public function migrateOpenPayments(Request $request, OpenPaymentMigrationService $service)
    {
        try {
            $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
            $result = $service->migrate($storeId, $request->boolean('dry_run', false));

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function lastMasterSync()
    {
        $storeId = (string) config('edge_box.store_id');
        $metadata = SyncMetadata::where('store_id', $storeId)->first();
        $summarize = static function ($counts): array {
            $summary = [
                'pending' => (int) ($counts['pending'] ?? 0),
                'retrying' => (int) ($counts['retrying'] ?? 0),
                'syncing' => (int) ($counts['syncing'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
            ];
            $summary['unsynced'] = array_sum($summary);

            return $summary;
        };
        $currentStoreQueue = SyncQueue::where('store_id', $storeId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $allQueue = SyncQueue::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $queue = $summarize($currentStoreQueue);

        return response()->json([
            'success' => true,
            'timestamp' => $metadata?->last_sync_timestamp?->toIso8601String(),
            'sync_status' => $metadata?->sync_status,
            'last_error' => $metadata?->last_error,
            'pending_count' => $queue['pending'],
            'retrying_count' => $queue['retrying'],
            'syncing_count' => $queue['syncing'],
            'failed_count' => $queue['failed'],
            'unsynced_count' => $queue['unsynced'],
            'queue' => array_merge(['store_id' => $storeId], $queue),
            'queue_all_stores' => $summarize($allQueue),
        ]);
    }
}
