<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncStatusController extends Controller
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Get current sync status
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->syncService->getSyncStatus());
    }

    /**
     * Get pending sync queue count
     */
    public function pending(): \Illuminate\Http\JsonResponse
    {
        $pendingCount = DB::table('sync_queues')
            ->where('status', 'pending')
            ->count();

        $retryingCount = DB::table('sync_queues')
            ->where('status', 'retrying')
            ->where('next_retry_at', '<=', now())
            ->count();

        return response()->json([
            'pending' => $pendingCount,
            'retrying' => $retryingCount,
            'total' => $pendingCount + $retryingCount,
        ]);
    }

    /**
     * Get recent sync logs
     */
    public function logs(Request $request): \Illuminate\Http\JsonResponse
    {
        $limit = $request->input('limit', 50);
        $logs = DB::table('sync_logs')
            ->orderBy('synced_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }

    /**
     * Trigger manual sync
     */
    public function trigger(): \Illuminate\Http\JsonResponse
    {
        try {
            $result = $this->syncService->processQueue(50);

            return response()->json([
                'success' => true,
                'message' => 'Sync triggered',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
