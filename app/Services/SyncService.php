<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\SyncMetadata;
use App\Models\SyncConflict;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncService
{
    protected $cloudApiUrl;
    protected $apiKey;
    protected $storeId;

    public function __construct()
    {
        $this->cloudApiUrl = config('app.cloud_api_url', env('CLOUD_API_URL'));
        $this->apiKey = config('app.api_key', env('STORE_API_KEY'));
        $this->storeId = config('app.store_id', env('STORE_ID'));
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

        SyncQueue::create([
            'store_id' => $storeId,
            'table_name' => $table,
            'operation' => $operation,
            'record_id' => $recordId,
            'payload' => json_encode($data),
            'status' => 'pending',
            'priority' => $priority,
            'retry_count' => 0,
            'max_retries' => 10,
        ]);

        $this->incrementPendingCount($storeId);
    }

    /**
     * Process pending sync queue
     * Called by worker every 10 seconds
     */
    public function processQueue(int $batchSize = 50): array
    {
        $this->recoverStaleSyncingItems();

        // Check internet connectivity first
        if (!$this->isOnline()) {
            Log::info('No internet connection, skipping sync');
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        // Get pending items, ordered by priority and age
        $items = SyncQueue::where(function ($query) {
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
                    'error' => $e->getMessage()
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
            $endpoint = "/api/cloud/sync";
            $url = rtrim($this->cloudApiUrl, '/') . $endpoint;

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
                        'response_code' => $response->status(),
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
                    throw new \Exception('Cloud sync rejected item: ' . $response->body());
                }

                // Success: mark as synced
                $item->update([
                    'status' => 'synced',
                    'synced_at' => now(),
                    'response_code' => $response->status(),
                    'last_error' => null,
                    'next_retry_at' => null,
                ]);

                // Log success
                $this->logSync($item, 'success', null, $duration);

                $this->decrementPendingCount($item->store_id);

                Log::debug("Synced: {$item->table_name} #{$item->record_id}");

                return true;

            } else {
                // HTTP error: retry with backoff
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }

        } catch (\Exception $e) {
            // Network/timeout error: retry with backoff
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
        if (!in_array($item->table_name, ['payment_details', 'table', 'tables'], true)) {
            return false;
        }

        $payload = json_decode($item->payload, true) ?: [];
        $paymentId = $payload['payment_id'] ?? null;

        if (!$paymentId) {
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
        if (!empty($responseBody['conflicts'])) {
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
                'store_id' => $this->storeId,
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
        string $errorMessage
    ): void {
        $item->increment('retry_count');

        // Calculate next retry time (exponential backoff)
        // 1st retry: 10s, 2nd: 20s, 3rd: 40s, max: 1 hour
        $backoffSeconds = min(pow(2, $item->retry_count) * 10, 3600);
        $nextRetryAt = now()->addSeconds($backoffSeconds);

        if ($item->retry_count >= $item->max_retries) {
            // Max retries exceeded: mark as failed
            $item->update([
                'status' => 'failed',
                'last_error' => substr($errorMessage, 0, 500),
                'next_retry_at' => null,
            ]);

            $this->logSync($item, 'failed', $errorMessage, 0);

            Log::error("Sync FAILED after {$item->max_retries} retries", [
                'table' => $item->table_name,
                'record_id' => $item->record_id,
                'error' => $errorMessage,
            ]);

        } else {
            // Schedule retry
            $item->update([
                'status' => 'retrying',
                'last_error' => substr($errorMessage, 0, 500),
                'next_retry_at' => $nextRetryAt,
            ]);

            $this->logSync($item, 'retry', $errorMessage, 0);

            Log::warning("Sync retry scheduled", [
                'table' => $item->table_name,
                'record_id' => $item->record_id,
                'retry_count' => $item->retry_count,
                'next_retry_at' => $nextRetryAt,
                'error' => $errorMessage,
            ]);
        }
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
        return SyncQueue::where('status', 'syncing')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->update([
                'status' => 'retrying',
                'last_error' => 'Recovered stale syncing item after worker interruption',
                'next_retry_at' => now(),
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

        if (!$hasNewerMutation) {
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

        Log::info("Cleanup completed", [
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
            $statusUrl = rtrim($this->cloudApiUrl, '/') . "/api/health";
            $statusResponse = Http::withHeaders([
                'X-Edge-Api-Key' => $this->apiKey,
                'X-Store-ID' => $this->storeId,
            ])->connectTimeout(2)->timeout(3)->head($statusUrl);

            return $statusResponse->status() < 500;
        } catch (\Exception $e) {
            return false;
        }
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
        try{  
                $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'X-Store-ID'    => $this->storeId,
            ])->timeout(5)->get(rtrim($this->cloudApiUrl, '/') . '/api/cloud/sync-status');

            if ($response->successful()) {
                $cloudStatus = $response->json();
            }
        } catch (\Exception) {}
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
    public function processBulkUpload(\Illuminate\Support\Collection $items): array
    {
        $ids = $items->pluck('id')->toArray();
        SyncQueue::whereIn('id', $ids)->update(['status' => 'syncing']);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'X-Store-ID' => $this->storeId,
        ])->timeout(30)->post(rtrim($this->cloudApiUrl, '/') . '/api/EdgeBox/bulk-upload', [
            'store_id' => $this->storeId,
            'records'  => $items->map(fn($item) => [
                'queue_id'   => $item->id,
                'table_name' => $item->table_name,
                'operation'  => $item->operation,
                'record_id'  => $item->record_id,
                'data'       => json_decode($item->payload, true),
                'timestamp'  => now()->toISOString(),
            ])->toArray(),
        ]);

        if ($response->successful()) {
            SyncQueue::whereIn('id', $ids)->update([
                'status'    => 'synced',
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
            $url = rtrim($this->cloudApiUrl, '/') . '/api/admin/input/addInvoiceInput';
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
                    if ($cloudInvoiceId > 0 && !empty($inputCode)) {
                        DB::table('inventory_histories')
                            ->where('input_code', $inputCode)
                            ->where('store_id', $item->store_id)
                            ->update([
                                'input_id' => $cloudInvoiceId,
                                'input_code' => $cloudInvoiceCode
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
                    throw new \Exception('Cloud rejected offline check-in sync: ' . ($responseBody['message'] ?? $response->body()));
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
            Log::error("Failed to sync offline check-in: " . $e->getMessage());

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
            $url = rtrim($this->cloudApiUrl, '/') . '/api/admin/output/create_export_invoice';
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
                    if ($cloudInvoiceId > 0 && !empty($outputCode)) {
                        DB::table('inventory_histories')
                            ->where('input_code', $outputCode)
                            ->where('store_id', $item->store_id)
                            ->update([
                                'input_id' => $cloudInvoiceId,
                                'input_code' => $cloudInvoiceCode
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
                    throw new \Exception('Cloud rejected offline checkout sync: ' . ($responseBody['message'] ?? $response->body()));
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
            Log::channel('edge')->error("Failed to sync offline checkout: " . $e->getMessage());

            return false;
        }
    }
}
