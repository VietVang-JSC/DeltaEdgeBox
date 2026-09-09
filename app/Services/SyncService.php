<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SyncConflict;
use App\Models\SyncMetadata;
use App\Models\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncService
{
    private const FAILURE_TRANSIENT = 'transient';

    private const FAILURE_CONFIGURATION = 'configuration';

    private const FAILURE_VALIDATION = 'validation';

    private const FAILURE_MAPPING = 'mapping';

    private const FAILURE_BUSINESS = 'business';

    private const FAILURE_CONFLICT = 'conflict';

    // mutation moi da duoc gop vao failed queue hien tai
    private const FAILED_LIFECYCLE_ABSORBED = 'absorbed';

    // Queue cu duoc cap nhat payload/operation moi nhat, khong tao queue moi
    private const FAILED_LIFECYCLE_PREREQUISITE_REACTIVATED = 'prerequisite_reactivated';

    // Khong co queue failed nao duoc cap nhat, tao queue moi
    private const FAILED_LIFECYCLE_NO_ACTION = 'no_action';

    protected $cloudApiUrl;

    protected $apiKey;

    protected $storeId;

    public function __construct()
    {
        $this->cloudApiUrl = config('app.cloud_api_url', env('CLOUD_API_URL'));
        $this->apiKey = config('app.api_key', env('STORE_API_KEY'));
        $this->storeId = config('app.store_id', env('STORE_ID'));
    }

    public function resolveCloudConflict(SyncConflict $conflict, SyncQueue $queue, string $strategy): array
    {
        if (! $conflict->cloud_conflict_id) {
            throw new \RuntimeException('Cloud conflict ID is missing');
        }

        [$storeId, $apiKey] = $this->resolveSyncCredentials($queue);
        $request = ['strategy' => $strategy];
        if ($strategy === 'keep_local') {
            $request['operation'] = [
                'type' => $queue->operation,
                'table' => $queue->table_name,
                'local_id' => $queue->record_id,
                'data' => json_decode($queue->payload, true) ?: [],
                'timestamp' => now()->toISOString(),
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
            'X-Store-ID' => $storeId,
            'X-Store-API-Key' => $apiKey,
        ])->timeout(30)->post(
            rtrim($this->cloudApiUrl, '/')."/api/cloud/sync-conflicts/{$conflict->cloud_conflict_id}/resolve",
            $request
        );

        $body = $response->json() ?? [];
        if (! $response->successful() || ($body['success'] ?? false) !== true) {
            throw new \RuntimeException($body['message'] ?? "Cloud conflict resolution failed with HTTP {$response->status()}");
        }

        return $body;
    }

    /**
     * Queue record for sync
     */
    public function queueForSync(
        string $table,
        string $operation, // 'create', 'update', 'delete'
        int $recordId,
        array $data,
        int $priority = 1 // 0=low, 1=normal, 2=urgent
    ): void {
        $storeId = $data['store_id'] ?? $this->storeId;
        $payload = json_encode($data);
        $priority = $this->resolveQueuePriority($table, $operation, $priority);

        DB::transaction(function () use ($storeId, $table, $operation, $recordId, $payload, $priority) {
            $current = SyncQueue::where('store_id', $storeId)
                ->where('table_name', $table)
                ->where('record_id', $recordId)
                ->whereIn('status', ['pending', 'retrying', 'syncing'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $current && $this->coalesceIntoUnresolvedConflict(
                $storeId,
                $table,
                $operation,
                $recordId,
                $payload,
                $priority
            )) {
                return;
            }

            if (! $current) {
                $failedLifecycleResult = $this->reactivateFailedLifecycleForMutation(
                    $storeId,
                    $table,
                    $operation,
                    $recordId,
                    $payload,
                    $priority
                );

                if ($failedLifecycleResult === self::FAILED_LIFECYCLE_ABSORBED) {
                    return;
                }
            }

            if ($current
                && $current->operation === 'create'
                && $operation === 'create'
                && ! $this->hasNeverBeenAttempted($current)
                && ! $this->payloadsMatch($current->payload, $payload)
            ) {
                Log::info('Queued changed duplicate create as a follow-up update', [
                    'store_id' => $storeId,
                    'table' => $table,
                    'record_id' => $recordId,
                    'create_queue_id' => $current->id,
                ]);
                $operation = 'update';
                // Re-resolve because queue semantics changed from create to update.
                $priority = $this->resolveQueuePriority($table, $operation, $priority);
            }

            if ($current && $this->coalesceQueuedMutation($current, $operation, $payload, $priority)) {
                return;
            }

            SyncQueue::create([
                'store_id' => $storeId,
                'table_name' => $table,
                'operation' => $operation,
                'record_id' => $recordId,
                'payload' => $payload,
                'status' => 'pending',
                'priority' => $priority,
                'retry_count' => 0,
                'max_retries' => 10,
            ]);

            $this->incrementPendingCount($storeId);
        });
    }

    /**
     * Process pending sync queue
     * Called by worker every 10 seconds
     */
    public function processQueue(int $batchSize = 50): array
    {
        $this->recoverStaleSyncingItems();

        // Check internet connectivity first
        if (! $this->isOnline()) {
            Log::info('No internet connection, skipping sync');

            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        // Get pending items, ordered by priority and age
        $items = SyncQueue::where('store_id', $this->storeId)
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere(function ($retryQuery) {
                        $retryQuery->where('status', 'retrying')
                            ->where(function ($dueQuery) {
                                $dueQuery->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', now());
                            });
                    });
            })
            ->orderByRaw($this->syncDependencyOrderSql())
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($items->isEmpty()) {
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        Log::info("Processing {$items->count()} sync items");

        $results = ['success' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => false, 'errors' => []];

        foreach ($items as $item) {
            try {
                $processed = $this->processSyncItem($item);

                if ($processed === true) {
                    $results['success']++;
                } elseif ($processed === false) {
                    $results['failed']++;
                } else {
                    $results['deferred']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $item->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Process single sync item with retry logic
     */
    protected function processSyncItem(SyncQueue $item): ?bool
    {
        $startTime = microtime(true);

        try {
            if ($this->markSupersededUpdate($item)) {
                return true;
            }

            if ($this->shouldDeferForEarlierLifecycleMutation($item)) {
                $this->deferEarlierLifecycleMutation($item);

                return null;
            }

            $payload = json_decode($item->payload, true) ?: [];
            [$syncStoreId, $syncApiKey] = $this->resolveSyncCredentials($item);

            if ($this->shouldDeferForMissingDependency($item)) {
                $this->deferDependencyBlockedItem($item);

                return null;
            }

            // Mark as syncing
            $item->update(['status' => 'syncing']);

            if ($item->table_name === 'inputs') {
                return $this->syncInputToCloud($item, $syncStoreId, $syncApiKey);
            }

            if ($item->table_name === 'outputs') {
                return $this->syncOutputToCloud($item, $syncStoreId, $syncApiKey);
            }

            // Build API endpoint
            $endpoint = '/api/cloud/sync';
            $url = rtrim($this->cloudApiUrl, '/').$endpoint;

            // Send to cloud
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$syncApiKey}",
                'Content-Type' => 'application/json',
                'X-Store-ID' => $syncStoreId,
                'X-Store-API-Key' => $syncApiKey,
            ])->timeout(30)->post($url, [
                'operations' => [[
                    'type' => $item->operation,
                    'table' => $item->table_name,
                    'local_id' => $item->record_id,
                    'data' => $payload,
                    'timestamp' => now()->toISOString(),
                ]],
            ]);

            $duration = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $responseBody = $response->json() ?? [];

                if ($this->hasSyncConflicts($responseBody)) {
                    $this->storeConflicts($item, $responseBody);

                    $item->update([
                        'status' => 'failed',
                        'last_error' => 'Sync conflict returned by cloud',
                        'failure_type' => self::FAILURE_CONFLICT,
                        'error_code' => 'SYNC_CONFLICT',
                        'retryable' => false,
                        'response_code' => $response->status(),
                        'next_retry_at' => null,
                    ]);

                    $this->logSync($item, 'failed', 'Sync conflict returned by cloud', $duration);

                    Log::warning("Sync conflict: {$item->table_name} #{$item->record_id}", [
                        'sync_queue_id' => $item->id,
                        'table' => $item->table_name,
                        'record_id' => $item->record_id,
                    ]);

                    return false;
                }

                if (array_key_exists('success', $responseBody) && $responseBody['success'] === false) {
                    $this->handleSyncFailure(
                        $item,
                        'Cloud sync rejected item: '.$response->body(),
                        $response->status(),
                        $responseBody
                    );

                    return false;
                }

                // Success: mark as synced
                $item->update([
                    'status' => 'synced',
                    'synced_at' => now(),
                    'response_code' => $response->status(),
                    'last_error' => null,
                    'failure_type' => null,
                    'error_code' => null,
                    'retryable' => null,
                    'next_retry_at' => null,
                ]);

                // Log success
                $this->logSync($item, 'success', null, $duration);

                $this->decrementPendingCount($item->store_id);

                Log::debug("Synced: {$item->table_name} #{$item->record_id}");

                return true;

            } else {
                $responseBody = $response->json() ?? [];
                $this->handleSyncFailure(
                    $item,
                    "HTTP {$response->status()}: {$response->body()}",
                    $response->status(),
                    $responseBody
                );

                return false;
            }

        } catch (\Exception $e) {
            $this->handleSyncFailure($item, $e->getMessage());

            return false;
        }
    }

    public function syncDependencyOrderSql(): string
    {
        return "CASE table_name
            WHEN 'products' THEN 10
            WHEN 'customers' THEN 20
            WHEN 'table' THEN 30
            WHEN 'tables' THEN 30
            WHEN 'orders' THEN 40
            WHEN 'payments' THEN 50
            WHEN 'order_items' THEN 60
            WHEN 'payment_details' THEN 70
            ELSE 100
        END ASC";
    }

    protected function shouldDeferForMissingDependency(SyncQueue $item): bool
    {
        if (! in_array($item->table_name, ['payment_details', 'table', 'tables'], true)) {
            return false;
        }

        $payload = json_decode($item->payload, true) ?: [];
        $paymentId = $payload['payment_id'] ?? null;

        if (! $paymentId) {
            return false;
        }

        return SyncQueue::where('store_id', $item->store_id)
            ->where('table_name', 'payments')
            ->where('record_id', $paymentId)
            ->whereIn('status', ['pending', 'retrying', 'syncing', 'failed'])
            ->exists();
    }

    protected function deferDependencyBlockedItem(SyncQueue $item): void
    {
        $payload = json_decode($item->payload, true) ?: [];
        $paymentId = $payload['payment_id'] ?? null;
        $nextRetryAt = now()->addSeconds(30);

        $item->update([
            'status' => 'retrying',
            'last_error' => "Waiting for parent payment sync payment_id={$paymentId}",
            'next_retry_at' => $nextRetryAt,
        ]);

        Log::info('Sync item deferred until parent payment is synced', [
            'sync_queue_id' => $item->id,
            'table' => $item->table_name,
            'record_id' => $item->record_id,
            'payment_id' => $paymentId,
            'next_retry_at' => $nextRetryAt,
        ]);
    }

    /**
     * Check whether cloud response contains conflict results.
     */
    protected function hasSyncConflicts(array $responseBody): bool
    {
        if (! empty($responseBody['conflicts'])) {
            return true;
        }

        foreach ($responseBody['results'] ?? [] as $result) {
            if (($result['status'] ?? null) === 'conflict') {
                return true;
            }
        }

        return false;
    }

    /**
     * Store cloud conflicts locally for review instead of retrying forever.
     */
    protected function storeConflicts(SyncQueue $item, array $responseBody): void
    {
        $conflicts = $responseBody['conflicts'] ?? [];

        if (empty($conflicts)) {
            $conflicts = array_values(array_filter($responseBody['results'] ?? [], function ($result) {
                return ($result['status'] ?? null) === 'conflict';
            }));
        }

        foreach ($conflicts as $conflict) {
            SyncConflict::create([
                'store_id' => $item->store_id ?: $this->storeId,
                'sync_queue_id' => $item->id,
                'cloud_conflict_id' => $conflict['conflict_id'] ?? $conflict['id'] ?? null,
                'table_name' => $item->table_name,
                'record_id' => $item->record_id,
                'operation_type' => $item->operation,
                'local_data' => json_decode($item->payload, true) ?: [],
                'cloud_data' => $conflict['cloud_data'] ?? [],
                'resolution_strategy' => $conflict['resolution_strategy'] ?? 'manual',
                'resolution_status' => 'unresolved',
                'error_message' => $conflict['message'] ?? 'Sync conflict returned by cloud',
            ]);
        }
    }

    /**
     * Handle sync failure with exponential backoff
     */
    protected function handleSyncFailure(
        SyncQueue $item,
        string $errorMessage,
        ?int $responseCode = null,
        array $responseBody = []
    ): void {
        $failure = $this->classifySyncFailure($errorMessage, $responseCode, $responseBody);

        if (! $failure['retryable']) {
            $item->update([
                'status' => 'failed',
                'last_error' => substr($errorMessage, 0, 500),
                'failure_type' => $failure['type'],
                'error_code' => $failure['code'],
                'retryable' => false,
                'response_code' => $responseCode,
                'next_retry_at' => null,
            ]);

            $this->logSync($item, 'failed', $errorMessage, 0);
            Log::error('Sync stopped for a non-retryable failure', [
                'sync_queue_id' => $item->id,
                'table' => $item->table_name,
                'record_id' => $item->record_id,
                'failure_type' => $failure['type'],
                'error_code' => $failure['code'],
                'response_code' => $responseCode,
                'error' => $errorMessage,
            ]);

            return;
        }

        $item->increment('retry_count');
        $backoffSeconds = min(pow(2, $item->retry_count) * 10, 3600);
        $nextRetryAt = now()->addSeconds($backoffSeconds);
        $classification = [
            'last_error' => substr($errorMessage, 0, 500),
            'failure_type' => self::FAILURE_TRANSIENT,
            'error_code' => $failure['code'],
            'retryable' => true,
            'response_code' => $responseCode,
        ];

        if ($item->retry_count >= $item->max_retries) {
            $item->update(array_merge($classification, [
                'status' => 'failed',
                'next_retry_at' => null,
            ]));

            $this->logSync($item, 'failed', $errorMessage, 0);
            Log::error("Sync FAILED after {$item->max_retries} retries", [
                'sync_queue_id' => $item->id,
                'table' => $item->table_name,
                'record_id' => $item->record_id,
                'error_code' => $failure['code'],
                'error' => $errorMessage,
            ]);

            return;
        }

        $item->update(array_merge($classification, [
            'status' => 'retrying',
            'next_retry_at' => $nextRetryAt,
        ]));

        $this->logSync($item, 'retry', $errorMessage, 0);
        Log::warning('Sync retry scheduled', [
            'sync_queue_id' => $item->id,
            'table' => $item->table_name,
            'record_id' => $item->record_id,
            'retry_count' => $item->retry_count,
            'next_retry_at' => $nextRetryAt,
            'error_code' => $failure['code'],
            'error' => $errorMessage,
        ]);
    }

    protected function classifySyncFailure(
        string $errorMessage,
        ?int $responseCode,
        array $responseBody
    ): array {
        $failure = $responseBody;
        foreach ($responseBody['results'] ?? [] as $result) {
            if (($result['status'] ?? null) === 'failed') {
                $failure = $result;
                break;
            }
        }

        $type = $failure['error_type'] ?? null;
        $code = $failure['error_code'] ?? null;
        if (is_string($type) && array_key_exists('retryable', $failure)) {
            return [
                'type' => $type,
                'code' => $code ?: 'CLOUD_SYNC_FAILED',
                'retryable' => (bool) $failure['retryable'],
            ];
        }

        $normalizedMessage = strtolower($errorMessage);
        if (str_contains($normalizedMessage, 'missing sync api key') || in_array($responseCode, [401, 403], true)) {
            return ['type' => self::FAILURE_CONFIGURATION, 'code' => 'SYNC_AUTH_CONFIGURATION', 'retryable' => false];
        }

        if (str_contains($normalizedMessage, 'mapping')) {
            return ['type' => self::FAILURE_MAPPING, 'code' => 'SYNC_MAPPING_INVALID', 'retryable' => false];
        }

        if ($responseCode === 422 || str_contains($normalizedMessage, 'validation') || str_contains($normalizedMessage, 'unsupported sync table')) {
            return ['type' => self::FAILURE_VALIDATION, 'code' => 'SYNC_VALIDATION_FAILED', 'retryable' => false];
        }

        if ($responseCode !== null && $responseCode >= 400 && $responseCode < 500 && ! in_array($responseCode, [408, 429], true)) {
            return ['type' => self::FAILURE_BUSINESS, 'code' => 'SYNC_BUSINESS_REJECTED', 'retryable' => false];
        }

        return ['type' => self::FAILURE_TRANSIENT, 'code' => 'SYNC_TRANSIENT_FAILURE', 'retryable' => true];
    }

    protected function incrementPendingCount($storeId): void
    {
        SyncMetadata::firstOrCreate(
            ['store_id' => $storeId],
            [
                'sync_status' => 'idle',
                'pending_records_count' => 0,
            ]
        );

        SyncMetadata::where('store_id', $storeId)->increment('pending_records_count');
    }

    protected function decrementPendingCount($storeId): void
    {
        $metadata = SyncMetadata::firstOrCreate(
            ['store_id' => $storeId],
            [
                'sync_status' => 'idle',
                'pending_records_count' => 0,
            ]
        );

        if ($metadata->pending_records_count > 0) {
            $metadata->decrement('pending_records_count');
        }
    }

    protected function resolveSyncCredentials(SyncQueue $item): array
    {
        $storeId = (string) ($item->store_id ?: $this->storeId);
        $apiKey = Store::whereKey($storeId)->value('api_key');

        if ($apiKey !== null && trim((string) $apiKey) !== '') {
            return [$storeId, (string) $apiKey];
        }

        if ($storeId === (string) $this->storeId && trim((string) $this->apiKey) !== '') {
            return [$storeId, $this->apiKey];
        }

        throw new \RuntimeException("Missing sync API key for store_id={$storeId}");
    }

    protected function recoverStaleSyncingItems(): int
    {
        return SyncQueue::where('store_id', $this->storeId)
            ->where('status', 'syncing')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->update([
                'status' => 'retrying',
                'last_error' => 'Recovered stale syncing item after worker interruption',
                'failure_type' => self::FAILURE_TRANSIENT,
                'error_code' => 'SYNC_WORKER_INTERRUPTED',
                'retryable' => true,
                'next_retry_at' => now(),
            ]);
    }

    // xu ly cac queue dang pending, retrying, syncing
    protected function coalesceQueuedMutation(
        SyncQueue $current,
        string $operation,
        string $payload,
        int $priority
    ): bool {
        if ($current->operation === 'create') {
            if ($operation === 'create') {
                if ($current->status !== 'syncing') {
                    $current->update([
                        'payload' => $payload,
                        'priority' => max($current->priority, $priority),
                    ]);

                    $this->logCoalescedMutation($current, 'create', 'refreshed_duplicate_create');
                }

                return true;
            }

            if ($operation === 'update' && $this->hasNeverBeenAttempted($current)) {
                $current->update([
                    'payload' => $payload,
                    'priority' => max($current->priority, $priority),
                ]);

                $this->logCoalescedMutation($current, 'update', 'folded_update_into_create');

                return true;
            }

            if ($operation === 'delete' && $this->hasNeverBeenAttempted($current)) {
                $current->update([
                    'status' => 'superseded',
                    'synced_at' => now(),
                    'last_error' => 'Cancelled before initial create was synced',
                    'next_retry_at' => null,
                ]);
                $this->decrementPendingCount($current->store_id);

                $this->logCoalescedMutation($current, 'delete', 'cancelled_unsynced_create');

                return true;
            }

            return false;
        }

        if ($current->operation === 'update' && $current->status !== 'syncing') {
            if ($operation === 'update') {
                $current->update([
                    'payload' => $payload,
                    'status' => 'pending',
                    'priority' => max($current->priority, $priority),
                    'retry_count' => 0,
                    'last_error' => null,
                    'next_retry_at' => null,
                ]);

                $this->logCoalescedMutation($current, 'update', 'refreshed_queued_update');

                return true;
            }

            if ($operation === 'delete') {
                $current->update([
                    'operation' => 'delete',
                    'payload' => $payload,
                    'status' => 'pending',
                    // A payment detail delete must not retain a higher update priority.
                    'priority' => $priority,
                    'retry_count' => 0,
                    'last_error' => null,
                    'next_retry_at' => null,
                ]);

                $this->logCoalescedMutation($current, 'delete', 'converted_update_to_delete');

                return true;
            }
        }

        if ($current->operation === 'delete' && $operation === 'delete') {
            if ($current->status !== 'syncing') {
                $current->update([
                    'payload' => $payload,
                    'priority' => $priority,
                ]);

                $this->logCoalescedMutation($current, 'delete', 'refreshed_duplicate_delete');
            }

            return true;
        }

        if ($current->operation === 'delete' && $operation === 'create') {
            Log::warning('Starting a new sync lifecycle after a queued delete', [
                'store_id' => $current->store_id,
                'table' => $current->table_name,
                'record_id' => $current->record_id,
                'delete_queue_id' => $current->id,
            ]);
        }

        return false;
    }

    // ghi log khi cac mutation duoc gop
    protected function logCoalescedMutation(
        SyncQueue $current,
        string $incomingOperation,
        string $action
    ): void {
        Log::info('Coalesced sync queue mutation', [
            'sync_queue_id' => $current->id,
            'store_id' => $current->store_id,
            'table' => $current->table_name,
            'record_id' => $current->record_id,
            'queued_operation' => $current->operation,
            'incoming_operation' => $incomingOperation,
            'action' => $action,
            'status' => $current->status,
        ]);
    }

    // so sanh hai payload JSON de xac dinh xem co thay doi gi khong
    protected function payloadsMatch(string $currentPayload, string $newPayload): bool
    {
        return (json_decode($currentPayload, true) ?: [])
            === (json_decode($newPayload, true) ?: []);
    }

    // xu ly mutation moi khi co conflict chua giai quyet
    protected function coalesceIntoUnresolvedConflict(
        $storeId,
        string $table,
        string $operation,
        int $recordId,
        string $payload,
        int $priority
    ): bool {
        $candidates = SyncConflict::where('store_id', $storeId)
            ->where('table_name', $table)
            ->where('record_id', $recordId)
            ->where('resolution_status', 'unresolved')
            ->get(['id', 'sync_queue_id']);

        if ($candidates->isEmpty()) {
            return false;
        }

        if ($candidates->count() !== 1 || ! $candidates->first()->sync_queue_id) {
            Log::warning('New local mutation is waiting behind unresolved sync conflicts', [
                'store_id' => $storeId,
                'table' => $table,
                'record_id' => $recordId,
                'operation' => $operation,
                'conflict_ids' => $candidates->pluck('id')->all(),
            ]);

            return false;
        }

        $candidate = $candidates->first();
        $failedQueue = SyncQueue::whereKey($candidate->sync_queue_id)
            ->where('status', 'failed')
            ->lockForUpdate()
            ->first();
        $conflict = SyncConflict::whereKey($candidate->id)
            ->where('resolution_status', 'unresolved')
            ->lockForUpdate()
            ->first();

        if (! $failedQueue || ! $conflict || $failedQueue->operation !== $operation) {
            Log::warning('New local mutation cannot be merged into unresolved sync conflict', [
                'store_id' => $storeId,
                'table' => $table,
                'record_id' => $recordId,
                'operation' => $operation,
                'conflict_id' => $candidate->id,
                'failed_queue_id' => $candidate->sync_queue_id,
                'failed_operation' => $failedQueue?->operation,
            ]);

            return false;
        }

        $failedQueue->update([
            'payload' => $payload,
            'priority' => max($failedQueue->priority, $priority),
        ]);
        $conflict->update([
            'local_data' => json_decode($payload, true) ?: [],
            'operation_type' => $operation,
        ]);

        Log::info('Refreshed unresolved sync conflict with latest local payload', [
            'conflict_id' => $conflict->id,
            'sync_queue_id' => $failedQueue->id,
            'store_id' => $storeId,
            'table' => $table,
            'record_id' => $recordId,
            'operation' => $operation,
        ]);

        return true;
    }

    // xu ly cac queue faild khong bi unresolved conflict
    protected function reactivateFailedLifecycleForMutation(
        $storeId,
        string $table,
        string $operation,
        int $recordId,
        string $payload,
        int $priority
    ): string {
        $failedQueues = SyncQueue::where('store_id', $storeId)
            ->where('table_name', $table)
            ->where('record_id', $recordId)
            ->where('status', 'failed')
            ->where('failure_type', self::FAILURE_TRANSIENT)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($failedQueues->isEmpty()) {
            return self::FAILED_LIFECYCLE_NO_ACTION;
        }

        $conflictedQueueIds = SyncConflict::whereIn('sync_queue_id', $failedQueues->pluck('id'))
            ->where('resolution_status', 'unresolved')
            ->pluck('sync_queue_id');
        $reactivatedQueues = $failedQueues->filter(
            fn (SyncQueue $item) => ! $conflictedQueueIds->contains($item->id)
        );

        if ($reactivatedQueues->isEmpty()) {
            return self::FAILED_LIFECYCLE_NO_ACTION;
        }

        $targetQueue = $reactivatedQueues->last();
        $previousOperation = $targetQueue->operation;
        $previousError = $targetQueue->last_error;
        $absorbed = $previousOperation === $operation
            || ($previousOperation === 'update' && $operation === 'delete');

        foreach ($reactivatedQueues as $failedQueue) {
            $updates = [
                'status' => 'pending',
                'retry_count' => 0,
                'last_error' => null,
                'next_retry_at' => null,
                'response_code' => null,
                'failure_type' => null,
                'error_code' => null,
                'retryable' => null,
                'synced_at' => null,
            ];

            if ($absorbed && $failedQueue->is($targetQueue)) {
                $updates['operation'] = $operation;
                $updates['payload'] = $payload;
                $updates['priority'] = $previousOperation === 'update' && $operation === 'delete'
                    ? $priority
                    : max($failedQueue->priority, $priority);
            }

            $failedQueue->update($updates);
        }

        $result = $absorbed
            ? self::FAILED_LIFECYCLE_ABSORBED
            : self::FAILED_LIFECYCLE_PREREQUISITE_REACTIVATED;

        Log::info('Reactivated failed sync lifecycle after new local mutation', [
            'sync_queue_id' => $targetQueue->id,
            'reactivated_queue_ids' => $reactivatedQueues->pluck('id')->all(),
            'store_id' => $storeId,
            'table' => $table,
            'record_id' => $recordId,
            'previous_operation' => $previousOperation,
            'new_operation' => $operation,
            'absorbed_new_mutation' => $absorbed,
            'result' => $result,
            'previous_error' => $previousError,
        ]);

        return $result;
    }

    protected function hasNeverBeenAttempted(SyncQueue $item): bool
    {
        return $item->status === 'pending'
            && $item->retry_count === 0
            && $item->response_code === null;
    }

    protected function resolveQueuePriority(string $table, string $operation, int $requestedPriority): int
    {
        if ($table === 'payment_details' && $operation === 'delete') {
            return 1;
        }

        return $requestedPriority;
    }

    protected function shouldDeferForEarlierLifecycleMutation(SyncQueue $item): bool
    {
        return SyncQueue::where('store_id', $item->store_id)
            ->where('table_name', $item->table_name)
            ->where('record_id', $item->record_id)
            ->where('id', '<', $item->id)
            ->whereIn('status', ['pending', 'retrying', 'syncing', 'failed'])
            ->exists();
    }

    protected function deferEarlierLifecycleMutation(SyncQueue $item): void
    {
        $item->update([
            'status' => 'retrying',
            'last_error' => 'Waiting for an earlier mutation in the same record lifecycle',
            'next_retry_at' => now()->addSeconds(10),
        ]);

        Log::info('Deferred sync mutation behind an earlier lifecycle item', [
            'sync_queue_id' => $item->id,
            'store_id' => $item->store_id,
            'table' => $item->table_name,
            'record_id' => $item->record_id,
            'operation' => $item->operation,
        ]);
    }

    protected function markSupersededUpdate(SyncQueue $item): bool
    {
        if ($item->operation !== 'update') {
            return false;
        }

        $hasNewerMutation = SyncQueue::where('store_id', $item->store_id)
            ->where('table_name', $item->table_name)
            ->where('record_id', $item->record_id)
            ->where('id', '>', $item->id)
            ->whereIn('operation', ['update', 'delete'])
            ->whereIn('status', ['pending', 'retrying', 'syncing'])
            ->exists();

        if (! $hasNewerMutation) {
            return false;
        }

        $item->update([
            'status' => 'superseded',
            'synced_at' => now(),
            'last_error' => 'Superseded by a newer queued mutation',
            'next_retry_at' => null,
        ]);
        $this->decrementPendingCount($item->store_id);

        return true;
    }

    /**
     * Log sync attempt
     */
    protected function logSync(
        SyncQueue $item,
        string $status,
        ?string $errorMessage,
        float $durationMs
    ): void {
        DB::table('sync_logs')->insert([
            'store_id' => $this->storeId,
            'table_name' => $item->table_name,
            'operation' => $item->operation,
            'record_id' => $item->record_id,
            'status' => $status,
            'error_message' => $errorMessage,
            'duration_ms' => round($durationMs),
            'synced_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Cleanup old sync logs and synced queue items
     * Run daily via cron
     */
    public function cleanupOldRecords(): void
    {
        // Delete synced queue items older than 7 days
        $deletedQueue = SyncQueue::where('status', 'synced')
            ->where('synced_at', '<', now()->subDays(7))
            ->delete();

        // Delete sync logs older than 30 days
        $deletedLogs = DB::table('sync_logs')
            ->where('synced_at', '<', now()->subDays(30))
            ->delete();

        Log::info('Cleanup completed', [
            'deleted_queue_items' => $deletedQueue,
            'deleted_logs' => $deletedLogs,
        ]);
    }

    /**
     * Check cloud sync endpoint availability.
     */
    public function isOnline(): bool
    {
        try {
            $statusUrl = rtrim($this->cloudApiUrl, '/').'/api/health';
            $statusResponse = Http::withHeaders([
                'X-Edge-Api-Key' => $this->apiKey,
                'X-Store-ID' => $this->storeId,
            ])->connectTimeout(5)->timeout(8)->head($statusUrl);

            return $statusResponse->status() < 500;
        } catch (\Exception $e) {
            Log::warning('isOnline check failed', [
                'error' => $e->getMessage(),
                'php_ini' => php_ini_loaded_file(),
                'curl_cainfo' => ini_get('curl.cainfo'),
                'openssl_cafile' => ini_get('openssl.cafile'),
                'cainfo_exists' => @file_exists((string) ini_get('curl.cainfo')),
            ]);

            return false;
        }
    }

    public function getStoreId()
    {
        return $this->storeId;
    }

    /**
     * Get sync status
     */
    public function getSyncStatus(): array
    {
        $metadata = SyncMetadata::where('store_id', $this->storeId)->first();
        $queueCounts = SyncQueue::where('store_id', $this->storeId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $cloudStatus = null;
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'X-Store-ID' => $this->storeId,
            ])->timeout(5)->get(rtrim($this->cloudApiUrl, '/').'/api/cloud/sync-status');

            if ($response->successful()) {
                $cloudStatus = $response->json();
            }
        } catch (\Exception) {
        }

        return [
            'store_id' => $this->storeId,
            'is_online' => $this->isOnline(),
            'sync_status' => $metadata?->sync_status ?? 'idle',
            'pending_count' => (int) ($queueCounts['pending'] ?? 0),
            'retrying_count' => (int) ($queueCounts['retrying'] ?? 0),
            'failed_count' => (int) ($queueCounts['failed'] ?? 0),
            'unresolved_conflicts_count' => SyncConflict::where('store_id', $this->storeId)
                ->where('resolution_status', 'unresolved')
                ->count(),
            'last_sync_at' => $metadata?->last_sync_timestamp,
            'last_error' => $metadata?->last_error,
            'cloud_status' => $cloudStatus,
        ];
    }

    // Bulk upload for large datasets (e.g. initial sync or re-sync)
    public function processBulkUpload(Collection $items): array
    {
        $ids = $items->pluck('id')->toArray();
        SyncQueue::whereIn('id', $ids)->update(['status' => 'syncing']);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'X-Store-ID' => $this->storeId,
        ])->timeout(30)->post(rtrim($this->cloudApiUrl, '/').'/api/EdgeBox/bulk-upload', [
            'store_id' => $this->storeId,
            'records' => $items->map(fn ($item) => [
                'queue_id' => $item->id,
                'table_name' => $item->table_name,
                'operation' => $item->operation,
                'record_id' => $item->record_id,
                'data' => json_decode($item->payload, true),
                'timestamp' => now()->toISOString(),
            ])->toArray(),
        ]);

        if ($response->successful()) {
            SyncQueue::whereIn('id', $ids)->update([
                'status' => 'synced',
                'synced_at' => now(),
            ]);
            SyncMetadata::where('store_id', $this->storeId)
                ->decrement('pending_records_count', count($ids));

            return ['success' => count($ids), 'failed' => 0];
        }

        // On failure, reset status to pending for retry
        SyncQueue::whereIn('id', $ids)->update(['status' => 'pending']);

        return ['success' => 0, 'failed' => count($ids)];
    }

    /**
     * Custom sync handler for offline check-in transaction.
     */
    protected function syncInputToCloud(SyncQueue $item, string $syncStoreId, string $syncApiKey): bool
    {
        $startTime = microtime(true);

        try {
            $url = rtrim($this->cloudApiUrl, '/').'/api/admin/input/addInvoiceInput';
            $payload = json_decode($item->payload, true);
            $inputCode = $payload['input_code'] ?? '';

            Log::info("Syncing offline check-in transaction to Cloud BE. Code: {$inputCode}");

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$syncApiKey}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Store-ID' => $syncStoreId,
            ])->timeout(30)->post($url, $payload);

            $duration = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $responseBody = $response->json() ?? [];

                if (isset($responseBody['status']) && $responseBody['status'] === true) {
                    $invoice = $responseBody['invoice'] ?? [];
                    $cloudInvoiceId = $invoice['id'] ?? 0;
                    $cloudInvoiceCode = $invoice['input_code'] ?? '';

                    // Cập nhật record local history với real ID và real code từ Cloud
                    if ($cloudInvoiceId > 0 && ! empty($inputCode)) {
                        DB::table('inventory_histories')
                            ->where('input_code', $inputCode)
                            ->where('store_id', $item->store_id)
                            ->update([
                                'input_id' => $cloudInvoiceId,
                                'input_code' => $cloudInvoiceCode,
                            ]);
                        Log::info("Updated local histories from Code: {$inputCode} to Cloud Code: {$cloudInvoiceCode}, ID: {$cloudInvoiceId}");
                    }

                    $item->update([
                        'status' => 'synced',
                        'synced_at' => now(),
                        'response_code' => $response->status(),
                        'last_error' => null,
                        'next_retry_at' => null,
                    ]);

                    $this->logSync($item, 'success', null, $duration);
                    $this->decrementPendingCount($item->store_id);

                    Log::info("Synced Offline Check-in successfully. Cloud Invoice Code: {$cloudInvoiceCode}");

                    return true;
                } else {
                    throw new \Exception('Cloud rejected offline check-in sync: '.($responseBody['message'] ?? $response->body()));
                }
            } else {
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $item->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'response_code' => 500,
            ]);

            $this->logSync($item, 'failed', $e->getMessage(), $duration);
            Log::error('Failed to sync offline check-in: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Custom sync handler for offline checkout transaction.
     * Directly posts the payload to Cloud BE Native createExportInvoice API.
     */
    protected function syncOutputToCloud(SyncQueue $item, string $syncStoreId, string $syncApiKey): bool
    {
        $startTime = microtime(true);

        try {
            $url = rtrim($this->cloudApiUrl, '/').'/api/admin/output/create_export_invoice';
            $payload = json_decode($item->payload, true);
            $outputCode = $payload['output_code'] ?? '';

            Log::channel('edge')->info("Syncing offline checkout transaction to Cloud BE. Code: {$outputCode}");

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$syncApiKey}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Store-ID' => $syncStoreId,
            ])->timeout(30)->post($url, $payload);

            $duration = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $responseBody = $response->json() ?? [];

                if (isset($responseBody['status']) && $responseBody['status'] === true) {
                    $invoice = $responseBody['invoice'] ?? [];
                    $cloudInvoiceId = $invoice['id'] ?? 0;
                    $cloudInvoiceCode = $invoice['output_code'] ?? '';

                    // Cập nhật record local history với real ID và real code từ Cloud
                    if ($cloudInvoiceId > 0 && ! empty($outputCode)) {
                        DB::table('inventory_histories')
                            ->where('input_code', $outputCode)
                            ->where('store_id', $item->store_id)
                            ->update([
                                'input_id' => $cloudInvoiceId,
                                'input_code' => $cloudInvoiceCode,
                            ]);
                        Log::channel('edge')->info("Updated local histories from Code: {$outputCode} to Cloud Code: {$cloudInvoiceCode}, ID: {$cloudInvoiceId}");
                    }

                    $item->update([
                        'status' => 'synced',
                        'synced_at' => now(),
                        'response_code' => $response->status(),
                        'last_error' => null,
                        'next_retry_at' => null,
                    ]);

                    $this->logSync($item, 'success', null, $duration);
                    $this->decrementPendingCount($item->store_id);

                    Log::channel('edge')->info("Synced Offline Checkout successfully. Cloud Invoice Code: {$cloudInvoiceCode}");

                    return true;
                } else {
                    throw new \Exception('Cloud rejected offline checkout sync: '.($responseBody['message'] ?? $response->body()));
                }
            } else {
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $item->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'response_code' => 500,
            ]);

            $this->logSync($item, 'failed', $e->getMessage(), $duration);
            Log::channel('edge')->error('Failed to sync offline checkout: '.$e->getMessage());

            return false;
        }
    }
}
