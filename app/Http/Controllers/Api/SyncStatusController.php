<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use App\Models\SyncQueue;
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
     * Move the queue out of the active lifecycle after Cloud confirms conflict resolution.
     */
    private function markConflictQueueTerminal(SyncQueue $queue, string $status, string $strategy): void
    {
        $wasOutstanding = in_array($queue->status, ['pending', 'retrying', 'syncing', 'failed', 'dismissed'], true);
        $queue->update([
            'status' => $status,
            'retry_count' => 0,
            'last_error' => null,
            'failure_type' => null,
            'error_code' => null,
            'retryable' => null,
            'next_retry_at' => null,
            'response_code' => 200,
            'synced_at' => now(),
        ]);

        if ($wasOutstanding) {
            DB::table('sync_metadata')
                ->where('store_id', $queue->store_id)
                ->where('pending_records_count', '>', 0)
                ->decrement('pending_records_count');
        }

        Log::info('[SyncAction] conflict queue moved to terminal status', [
            'sync_queue_id' => $queue->id,
            'status' => $status,
            'strategy' => $strategy,
        ]);
    }

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
            'payments' => ['table_id', 'customer_id', 'paid_date', 'total', 'discount', 'tax', 'final_total', 'payment_method', 'note', 'status', 'user_id', 'is_senior_discount', 'senior_discount_amount', 'sub_total_before_discount', 'total_incl_vat_before_discount', 'updated_at'],
            'payment_details' => ['payment_id', 'product_id', 'quantity', 'price', 'total', 'note', 'detail_discount', 'tax_amount', 'detail_discount_excluding_tax', 'unit_price_excluding_tax', 'discounted_price_excluding_tax', 'updated_at'],
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
                'failure_type' => null,
                'error_code' => null,
                'retryable' => null,
                'response_code' => null,
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
                'message' => __('sync.success_queue_reset_retry', ['id' => $id]),
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] retryQueueItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => __('sync.err_server_error')], 500);
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
            return response()->json(['success' => false, 'message' => __('sync.err_invalid_queue_id')], 422);
        }

        try {
            $item = DB::table('sync_queues')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => __('sync.err_queue_not_found')], 404);
            }

            if ($item->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => __('sync.err_only_pending_prioritized'),
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
                'message' => __('sync.success_queue_prioritized', ['id' => $id]),
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] prioritizeQueueItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => __('sync.err_server_error')], 500);
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
            return response()->json(['success' => false, 'message' => __('sync.err_invalid_queue_id')], 422);
        }

        try {
            $item = DB::table('sync_queues')->where('id', $id)->first();

            if (!$item) {
                Log::warning('[SyncAction] dismissFailedItem: item not found', ['id' => $id]);
                return response()->json(['success' => false, 'message' => __('sync.err_queue_not_found')], 404);
            }

            if ($item->status !== 'failed') {
                Log::warning('[SyncAction] dismissFailedItem: item is not failed', [
                    'id' => $id,
                    'status' => $item->status,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => __('sync.err_only_failed_dismissed'),
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
                'message' => __('sync.success_queue_dismissed', ['id' => $id]),
            ]);
        } catch (\Throwable $th) {
            Log::error('[SyncAction] dismissFailedItem: unexpected error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => __('sync.err_server_error')], 500);
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
            return response()->json(['success' => false, 'message' => __('sync.err_invalid_conflict_id')], 422);
        }

        try {
            $conflict = SyncConflict::find($id);

            if (!$conflict) {
                Log::warning('[SyncAction] resolveConflict: conflict not found', ['id' => $id]);
                return response()->json(['success' => false, 'message' => __('sync.err_conflict_not_found')], 404);
            }

            if ($conflict->resolution_status !== 'unresolved') {
                Log::warning('[SyncAction] resolveConflict: already resolved', [
                    'id' => $id,
                    'resolution_status' => $conflict->resolution_status,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => __('sync.err_conflict_already_resolved', ['id' => $id]),
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
                    'message' => __('sync.err_table_not_allowed', ['table' => $conflict->table_name]),
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

                if (empty($conflict->sync_queue_id) || empty($conflict->cloud_conflict_id)) {
                    DB::rollBack();
                    Log::warning('[SyncAction] resolveConflict: queue or cloud conflict link is missing', [
                        'conflict_id' => $id,
                        'sync_queue_id' => $conflict->sync_queue_id,
                        'cloud_conflict_id' => $conflict->cloud_conflict_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => __('sync.err_keep_local_missing'),
                    ], 422);
                }

                $queueItem = SyncQueue::whereKey($conflict->sync_queue_id)
                    ->lockForUpdate()
                    ->first();
                if (! $queueItem) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => __('sync.err_keep_local_not_found', ['id' => $conflict->sync_queue_id]),
                    ], 404);
                }

                if ($resolution === 'keep_cloud') {
                    $cloudData = is_string($conflict->cloud_data)
                        ? json_decode($conflict->cloud_data, true)
                        : (array) $conflict->cloud_data;
                    if (! is_array($cloudData) || empty($cloudData)) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => __('sync.err_invalid_cloud_data'),
                        ], 422);
                    }

                    $safeData = $this->filterSafeCloudData($conflict->table_name, $cloudData);
                    if (empty($safeData)) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => __('sync.err_no_safe_fields'),
                        ], 422);
                    }

                    $affected = DB::table($conflict->table_name)
                        ->where('id', $conflict->record_id)
                        ->update($safeData);
                    if ($affected === 0 && ! DB::table($conflict->table_name)->where('id', $conflict->record_id)->exists()) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => __('sync.err_local_record_not_found'),
                        ], 404);
                    }

                    Log::info('[SyncAction] resolveConflict keep_cloud: local record updated', [
                        'conflict_id' => $id,
                        'table_name' => $conflict->table_name,
                        'record_id' => $conflict->record_id,
                        'applied_fields' => array_keys($safeData),
                    ]);
                } elseif ($resolution === 'skip') {
                    $newStatus = 'skipped';
                }

                $this->syncService->resolveCloudConflict($conflict, $queueItem, $resolution);
                $this->markConflictQueueTerminal(
                    $queueItem,
                    $resolution === 'keep_local' ? 'synced' : 'dismissed',
                    $resolution
                );
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
                        'message' => __('sync.err_conflict_already_resolved', ['id' => $id]),
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
                    'message' => __('sync.success_conflict_resolved', ['id' => $id, 'strategy' => $strategy]),
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
                'conflict_id' => $id,
                'resolution' => $resolution,
                'table_name' => isset($conflict->table_name) ? $conflict->table_name : null,
                'record_id' => isset($conflict->record_id) ? $conflict->record_id : null,
                'sync_queue_id' => isset($conflict->sync_queue_id) ? $conflict->sync_queue_id : null,
                'exception' => get_class($th),
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => __('sync.err_conflict_resolution_failed')], 500);
        }
    }
}
