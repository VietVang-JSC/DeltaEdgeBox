<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $page = max((int) $request->input('page', 1), 1);
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

        $total = (int) ($counts[$type] ?? 0);

        if ($type === 'conflicts') {
            $items = DB::table('sync_conflicts')
                ->where('resolution_status', 'unresolved')
                ->orderByDesc('created_at')
                ->forPage($page, $limit)
                ->get([
                    'id',
                    'table_name',
                    'record_id',
                    'resolution_strategy',
                    'resolution_status',
                    'local_data',
                    'cloud_data',
                    'sync_queue_id',
                    'created_at',
                ]);
        } elseif ($type === 'logs') {
            $total = DB::table('sync_logs')->count();
            $items = DB::table('sync_logs')
                ->orderByDesc('synced_at')
                ->forPage($page, $limit)
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
            $query = DB::table('sync_queues')
                ->where('status', $type);

            if ($type === 'pending') {
                $query->orderByRaw($this->syncService->syncDependencyOrderSql())
                    ->orderBy('priority', 'desc')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc');
            } elseif ($type === 'retrying') {
                $query->orderByRaw('next_retry_at IS NULL ASC')
                    ->orderBy('next_retry_at', 'asc')
                    ->orderByRaw($this->syncService->syncDependencyOrderSql())
                    ->orderBy('priority', 'desc')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc');
            } else {
                $query->orderByDesc('created_at');
            }

            $items = $query->forPage($page, $limit)
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
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                'has_prev' => $page > 1,
                'has_next' => $page * $limit < $total,
            ],
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

    /**
     * Danh sách bảng được phép resolve conflict.
     */
    private function allowedConflictTables(): array
    {
        return [
            'products',
            'categories',
            'table',
            'payment_methods',
            'payments',
            'payment_details',
            'users',
        ];
    }

    /**
     * Whitelist các field được phép update khi apply cloud_data (keep_cloud).
     * Tránh ghi đè id, store_id, created_at...
     */
    private function allowedFieldsByTable(): array
    {
        return [
            'products' => ['category_id', 'code', 'name', 'description', 'image', 'price', 'sale_price', 'quantity', 'unit', 'status', 'is_combo', 'admin_id', 'updated_at'],
            'categories' => ['name', 'description', 'image', 'sort_order', 'status', 'parent_id', 'admin_id', 'updated_at'],
            'table' => ['tablename', 'listitem', 'image', 'status', 'user_id', 'admin_id', 'is_show', 'sort_rank', 'userordered', 'booking_code', 'lock_time', 'qr_token', 'payment_id', 'number_of_people', 'can_order', 'qr_code', 'is_order_enabled', 'pin', 'qr_code_token', 'updated_at'],
            'payment_methods' => ['name', 'status', 'admin_id', 'updated_at'],
            'payments' => ['table_id', 'customer_id', 'paid_date', 'total', 'discount', 'tax', 'final_total', 'payment_method', 'note', 'status', 'user_id', 'updated_at'],
            'payment_details' => ['payment_id', 'product_id', 'quantity', 'price', 'total', 'note', 'updated_at'],
            'users' => ['name', 'username', 'email', 'phone', 'role', 'status', 'updated_at'],
        ];
    }

    /**
     * Lọc cloud_data chỉ giữ các field an toàn theo bảng, loại bỏ id/store_id/created_at.
     */
    private function filterSafeCloudData(string $tableName, array $cloudData): array
    {
        $blockedFields = ['id', 'store_id', 'created_at'];
        $data = array_diff_key($cloudData, array_flip($blockedFields));

        $allowedFieldsByTable = $this->allowedFieldsByTable();
        if (!isset($allowedFieldsByTable[$tableName])) {
            return [];
        }

        return array_intersect_key($data, array_flip($allowedFieldsByTable[$tableName]));
    }

    /**
     * [EDGE ACTION] Reset một sync_queue item về trạng thái pending để retry ngay.
     * Chỉ áp dụng cho item có status = 'failed' hoặc 'retrying'.
     */
    public function retryQueueItem(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = (int) $request->input('id');
        Log::info('[SyncAction] retryQueueItem: received request', ['queue_id' => $id]);

        if ($id <= 0) {
            Log::warning('[SyncAction] retryQueueItem: invalid id', ['id' => $id]);
            return response()->json(['success' => false, 'message' => 'Invalid queue item ID'], 422);
        }

        try {
            $item = DB::table('sync_queues')->where('id', $id)->first();

            if (!$item) {
                Log::warning('[SyncAction] retryQueueItem: item not found', ['id' => $id]);
                return response()->json(['success' => false, 'message' => 'Queue item not found'], 404);
            }

            if (!in_array($item->status, ['failed', 'retrying'], true)) {
                Log::warning('[SyncAction] retryQueueItem: item status cannot be retried', [
                    'id' => $id,
                    'status' => $item->status,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Item status '{$item->status}' cannot be retried. Only 'failed' or 'retrying' items can be retried.",
                ], 422);
            }

            $updated = DB::table('sync_queues')->where('id', $id)->update([
                'status' => 'pending',
                'retry_count' => 0,
                'priority' => 2, // reset về pending và đẩy lên ưu tiên cao để xử lý ngay
                'last_error' => null,
                'next_retry_at' => null,
                'updated_at' => now(),
            ]);

            Log::info('[SyncAction] retryQueueItem: item reset to pending', [
                'id' => $id,
                'table_name' => $item->table_name,
                'operation' => $item->operation,
                'prev_status' => $item->status,
                'rows_updated' => $updated,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Queue item #{$id} has been reset to pending for retry.",
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] retryQueueItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Server error: ' . $th->getMessage()], 500);
        }
    }

    /**
     * [EDGE ACTION] Push a pending sync_queue item to urgent priority.
     */
    public function prioritizeQueueItem(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = (int) $request->input('id');
        Log::info('[SyncAction] prioritizeQueueItem: received request', ['queue_id' => $id]);

        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid queue item ID'], 422);
        }

        try {
            $item = DB::table('sync_queues')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Queue item not found'], 404);
            }

            if ($item->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => "Only pending items can be prioritized. Current status: '{$item->status}'.",
                ], 422);
            }

            $updated = DB::table('sync_queues')->where('id', $id)->update([
                'priority' => 2,
                'updated_at' => now(),
            ]);

            Log::info('[SyncAction] prioritizeQueueItem: item prioritized', [
                'id' => $id,
                'table_name' => $item->table_name,
                'operation' => $item->operation,
                'rows_updated' => $updated,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Queue item #{$id} has been prioritized.",
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] prioritizeQueueItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Server error: ' . $th->getMessage()], 500);
        }
    }

    /**
     * [EDGE ACTION] Đánh dấu một sync_queue item có status 'failed' là 'dismissed'.
     * Hành động này không xoá bản ghi, chỉ đổi trạng thái để dừng retry.
     */
    public function dismissFailedItem(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = (int) $request->input('id');
        Log::info('[SyncAction] dismissFailedItem: received request', ['queue_id' => $id]);

        if ($id <= 0) {
            Log::warning('[SyncAction] dismissFailedItem: invalid id', ['id' => $id]);
            return response()->json(['success' => false, 'message' => 'Invalid queue item ID'], 422);
        }

        try {
            $item = DB::table('sync_queues')->where('id', $id)->first();

            if (!$item) {
                Log::warning('[SyncAction] dismissFailedItem: item not found', ['id' => $id]);
                return response()->json(['success' => false, 'message' => 'Queue item not found'], 404);
            }

            if ($item->status !== 'failed') {
                Log::warning('[SyncAction] dismissFailedItem: item is not failed', [
                    'id' => $id,
                    'status' => $item->status,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Only 'failed' items can be dismissed. Current status: '{$item->status}'.",
                ], 422);
            }

            $updated = DB::table('sync_queues')->where('id', $id)->update([
                'status' => 'dismissed',
                'updated_at' => now(),
            ]);

            Log::info('[SyncAction] dismissFailedItem: item dismissed', [
                'id' => $id,
                'table_name' => $item->table_name,
                'operation' => $item->operation,
                'last_error' => $item->last_error,
                'rows_updated' => $updated,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Queue item #{$id} has been dismissed.",
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] dismissFailedItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Server error: ' . $th->getMessage()], 500);
        }
    }

    /**
     * [EDGE ACTION] Resolve một sync_conflict item.
     * resolution:
     *   'keep_local'  → reset sync_queue về pending (ưu tiên cao) để sync worker đẩy lại local_data lên Cloud
     *   'keep_cloud'  → apply cloud_data (lọc field an toàn) vào local DB
     *   'skip'        → bỏ qua, đánh dấu resolved/skipped
     */
    public function resolveConflict(Request $request): \Illuminate\Http\JsonResponse
    {
        $id = (int) $request->input('id');
        $resolution = $request->input('resolution'); // 'keep_local' | 'keep_cloud' | 'skip'

        Log::info('[SyncAction] resolveConflict: received request', [
            'conflict_id' => $id,
            'resolution' => $resolution,
        ]);

        $allowedResolutions = ['keep_local', 'keep_cloud', 'skip'];
        if ($id <= 0 || !in_array($resolution, $allowedResolutions, true)) {
            Log::warning('[SyncAction] resolveConflict: invalid input', [
                'id' => $id,
                'resolution' => $resolution,
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid conflict ID or resolution strategy'], 422);
        }

        try {
            $conflict = DB::table('sync_conflicts')->where('id', $id)->first();

            if (!$conflict) {
                Log::warning('[SyncAction] resolveConflict: conflict not found', ['id' => $id]);
                return response()->json(['success' => false, 'message' => 'Conflict not found'], 404);
            }

            if ($conflict->resolution_status !== 'unresolved') {
                Log::warning('[SyncAction] resolveConflict: already resolved', [
                    'id' => $id,
                    'resolution_status' => $conflict->resolution_status,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Conflict #{$id} is already '{$conflict->resolution_status}'.",
                ], 422);
            }

            // Security: kiểm tra bảng nằm trong allowlist
            if (!in_array($conflict->table_name, $this->allowedConflictTables(), true)) {
                Log::error('[SyncAction] resolveConflict: table not in allowlist', [
                    'conflict_id' => $id,
                    'table_name' => $conflict->table_name,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Table '{$conflict->table_name}' is not allowed for conflict resolution.",
                ], 403);
            }

            Log::info('[SyncAction] resolveConflict: processing', [
                'conflict_id' => $id,
                'table_name' => $conflict->table_name,
                'record_id' => $conflict->record_id,
                'resolution' => $resolution,
                'sync_queue_id' => $conflict->sync_queue_id,
            ]);

            DB::beginTransaction();
            try {
                $newStatus = 'resolved';
                $strategy = $resolution;

                if ($resolution === 'keep_local') {
                    // Chặn nếu không có sync_queue_id để retry
                    if (empty($conflict->sync_queue_id)) {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_local: sync_queue_id missing', [
                            'conflict_id' => $id,
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot keep local: sync queue item is missing for conflict #{$id}. Use 'skip' instead.",
                        ], 422);
                    }

                    // Reset sync_queue item về pending với ưu tiên cao
                    $queueItem = DB::table('sync_queues')
                        ->where('id', $conflict->sync_queue_id)
                        ->first(['id', 'status']);

                    if (!$queueItem) {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_local: sync queue item not found', [
                            'conflict_id' => $id,
                            'sync_queue_id' => $conflict->sync_queue_id,
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot keep local: sync queue item #{$conflict->sync_queue_id} was not found.",
                        ], 404);
                    }

                    if ($queueItem->status === 'pending') {
                        Log::info('[SyncAction] resolveConflict keep_local: queue item already pending', [
                            'conflict_id' => $id,
                            'sync_queue_id' => $conflict->sync_queue_id,
                        ]);
                    } elseif (in_array($queueItem->status, ['failed', 'retrying', 'dismissed'], true)) {
                        $resetCount = DB::table('sync_queues')
                            ->where('id', $conflict->sync_queue_id)
                            ->where('status', $queueItem->status)
                            ->update([
                                'status' => 'pending',
                                'retry_count' => 0,
                                'priority' => 2, // urgent
                                'last_error' => null,
                                'next_retry_at' => null,
                                'updated_at' => now(),
                            ]);

                        if ($resetCount === 0) {
                            DB::rollBack();
                            Log::warning('[SyncAction] resolveConflict keep_local: resetCount is 0', [
                                'conflict_id' => $id,
                                'sync_queue_id' => $conflict->sync_queue_id,
                                'previous_status' => $queueItem->status,
                            ]);
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot keep local: sync queue item was changed by another process. Please reload and try again.',
                            ], 409);
                        }

                        Log::info('[SyncAction] resolveConflict keep_local: queue item reset to pending', [
                            'sync_queue_id' => $conflict->sync_queue_id,
                            'previous_status' => $queueItem->status,
                            'rows_updated' => $resetCount,
                        ]);
                    } else {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_local: queue status is not retryable', [
                            'conflict_id' => $id,
                            'sync_queue_id' => $conflict->sync_queue_id,
                            'status' => $queueItem->status,
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot keep local: sync queue item status '{$queueItem->status}' is not retryable.",
                        ], 422);
                    }
                } elseif ($resolution === 'keep_cloud') {
                    // Parse cloud_data
                    if (is_string($conflict->cloud_data)) {
                        $cloudData = json_decode($conflict->cloud_data, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            DB::rollBack();
                            Log::warning('[SyncAction] resolveConflict keep_cloud: json decode failed', [
                                'conflict_id' => $id,
                            ]);
                            return response()->json([
                                'success' => false,
                                'message' => 'Invalid cloud_data JSON.',
                            ], 422);
                        }
                    } else {
                        $cloudData = (array) $conflict->cloud_data;
                    }

                    if (empty($cloudData) || empty($conflict->table_name) || empty($conflict->record_id)) {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_cloud: insufficient data', [
                            'conflict_id' => $id,
                            'has_cloud_data' => !empty($cloudData),
                            'table_name' => $conflict->table_name,
                            'record_id' => $conflict->record_id,
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot apply cloud data: cloud_data, table_name, or record_id is empty.",
                        ], 422);
                    }

                    // Lọc field an toàn (whitelist + blocklist)
                    $safeData = $this->filterSafeCloudData($conflict->table_name, $cloudData);

                    if (empty($safeData)) {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_cloud: no safe fields to apply after filtering', [
                            'conflict_id' => $id,
                            'table_name' => $conflict->table_name,
                            'original_fields' => array_keys($cloudData),
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "No safe cloud fields to apply after security filtering.",
                        ], 422);
                    }

                    // Check if local record exists
                    $exists = DB::table($conflict->table_name)
                        ->where('id', $conflict->record_id)
                        ->exists();

                    if (!$exists) {
                        DB::rollBack();
                        Log::warning('[SyncAction] resolveConflict keep_cloud: local record not found', [
                            'conflict_id' => $id,
                            'table_name' => $conflict->table_name,
                            'record_id' => $conflict->record_id,
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Local record not found.',
                        ], 404);
                    }

                    $affected = DB::table($conflict->table_name)
                        ->where('id', $conflict->record_id)
                        ->update($safeData);

                    Log::info('[SyncAction] resolveConflict keep_cloud: local record updated with safe cloud data', [
                        'table_name' => $conflict->table_name,
                        'record_id' => $conflict->record_id,
                        'applied_fields' => array_keys($safeData),
                        'filtered_fields' => array_diff(array_keys($cloudData), array_keys($safeData)),
                        'rows_updated' => $affected,
                    ]);
                } else {
                    // skip
                    $newStatus = 'skipped';
                }

                // Chống race condition: conditional update WHERE resolution_status = 'unresolved'
                $affected = DB::table('sync_conflicts')
                    ->where('id', $id)
                    ->where('resolution_status', 'unresolved')
                    ->update([
                        'resolution_status' => $newStatus,
                        'resolution_strategy' => $strategy,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    DB::rollBack();
                    Log::warning('[SyncAction] resolveConflict: race condition – conflict already resolved by another request', [
                        'conflict_id' => $id,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "Conflict #{$id} was already resolved by another request.",
                    ], 409); // 409 Conflict
                }

                DB::commit();

                Log::info('[SyncAction] resolveConflict: completed successfully', [
                    'conflict_id' => $id,
                    'resolution' => $resolution,
                    'new_status' => $newStatus,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Conflict #{$id} resolved as '{$strategy}'.",
                    'new_status' => $newStatus,
                ]);
            } catch (\Throwable $inner) {
                DB::rollBack();
                Log::error('[SyncAction] resolveConflict: DB error, rolling back', [
                    'conflict_id' => $id,
                    'resolution' => $resolution,
                    'error' => $inner->getMessage(),
                ]);
                throw $inner;
            }
        } catch (\Throwable $th) {
            Log::error('[SyncAction] resolveConflict: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Server error: ' . $th->getMessage()], 500);
        }
    }
}
