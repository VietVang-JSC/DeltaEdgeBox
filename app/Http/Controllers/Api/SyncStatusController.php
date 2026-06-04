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
     * Get sync queue details by status for the FE diagnostics modal.
     */
    public function queue(Request $request): \Illuminate\Http\JsonResponse
    {
        $type = $request->input('type', 'auto');
        $limit = min(max((int) $request->input('limit', 25), 1), 100);
        $allowedTypes = ['auto', 'pending', 'retrying', 'failed', 'conflicts', 'logs'];

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'auto';
        }

        $counts = [
            'pending' => DB::table('sync_queues')->where('status', 'pending')->count(),
            'retrying' => DB::table('sync_queues')->where('status', 'retrying')->count(),
            'failed' => DB::table('sync_queues')->where('status', 'failed')->count(),
            'conflicts' => DB::table('sync_conflicts')->where('resolution_status', 'unresolved')->count(),
        ];

        if ($type === 'auto') {
            if ($counts['failed'] > 0) {
                $type = 'failed';
            } elseif ($counts['conflicts'] > 0) {
                $type = 'conflicts';
            } elseif ($counts['pending'] > 0) {
                $type = 'pending';
            } elseif ($counts['retrying'] > 0) {
                $type = 'retrying';
            } else {
                $type = 'logs';
            }
        }

        if ($type === 'conflicts') {
            $items = DB::table('sync_conflicts')
                ->where('resolution_status', 'unresolved')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get([
                    'id',
                    'table_name',
                    'record_id',
                    'resolution_strategy',
                    'resolution_status',
                    'created_at',
                ]);
        } elseif ($type === 'logs') {
            $items = DB::table('sync_logs')
                ->orderByDesc('synced_at')
                ->limit($limit)
                ->get([
                    'id',
                    'table_name',
                    'operation',
                    'record_id',
                    'status',
                    'error_message',
                    'duration_ms',
                    'synced_at',
                    'created_at',
                ]);
        } else {
            $items = DB::table('sync_queues')
                ->where('status', $type)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get([
                    'id',
                    'table_name',
                    'operation',
                    'record_id',
                    'status',
                    'priority',
                    'retry_count',
                    'max_retries',
                    'last_error',
                    'next_retry_at',
                    'synced_at',
                    'response_code',
                    'created_at',
                    'updated_at',
                ]);
        }

        return response()->json([
            'type' => $type,
            'counts' => $counts,
            'items' => $items,
        ]);
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
