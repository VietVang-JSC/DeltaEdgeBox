<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\SyncMetadata;
use App\Models\SyncConflict;
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
        $this->apiKey = config('app.api_key', env('API_KEY'));
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
        SyncQueue::create([
            'store_id' => $this->storeId,
            'table_name' => $table,
            'operation' => $operation,
            'record_id' => $recordId,
            'payload' => json_encode($data),
            'status' => 'pending',
            'priority' => $priority,
            'retry_count' => 0,
            'max_retries' => 10,
        ]);

        // Update metadata
        SyncMetadata::updateOrCreate(
            ['store_id' => $this->storeId],
            ['pending_records_count' => DB::raw('pending_records_count + 1')]
        );
    }

    /**
     * Process pending sync queue
     * Called by worker every 10 seconds
     */
    public function processQueue(int $batchSize = 50): array
    {
        // Check internet connectivity first
        if (!$this->isOnline()) {
            Log::info('No internet connection, skipping sync');
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        // Get pending items, ordered by priority and age
        $items = SyncQueue::where('status', 'pending')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->get();

        if ($items->isEmpty()) {
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        Log::info("Processing {$items->count()} sync items");

        $results = ['success' => 0, 'failed' => 0, 'skipped' => false, 'errors' => []];

        foreach ($items as $item) {
            try {
                if ($this->processSyncItem($item)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
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
    protected function processSyncItem(SyncQueue $item): bool
    {
        $startTime = microtime(true);

        try {
            // Mark as syncing
            $item->update(['status' => 'syncing']);

            // Build API endpoint
            $endpoint = "/api/cloud/sync/{$item->table_name}";
            $url = rtrim($this->cloudApiUrl, '/') . $endpoint;

            // Send to cloud
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'X-Store-ID' => $this->storeId,
            ])->timeout(30)->post($url, [
                'operation' => $item->operation,
                'record_id' => $item->record_id,
                'data' => json_decode($item->payload, true),
                'timestamp' => now()->toISOString(),
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
                ]);

                // Log success
                $this->logSync($item, 'success', null, $duration);

                // Update metadata
                SyncMetadata::where('store_id', $this->storeId)
                    ->decrement('pending_records_count');

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
                'cloud_data' => $conflict['cloud_data'] ?? null,
                'resolution_strategy' => $conflict['resolution_strategy'] ?? null,
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
     * Check internet connectivity
     */
    public function isOnline(): bool
    {
        try {
            $healthResponse = Http::timeout(5)->get($this->cloudApiUrl . '/api/health');

            if ($healthResponse->successful()) {
                return true;
            }

            $statusUrl = rtrim($this->cloudApiUrl, '/') . "/api/cloud/sync-status";
            $statusResponse = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'X-Store-API-Key' => $this->apiKey,
                'X-Store-ID' => $this->storeId,
            ])->timeout(5)->get($statusUrl);

            return $statusResponse->successful();
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

        return [
            'store_id' => $this->storeId,
            'is_online' => $this->isOnline(),
            'sync_status' => $metadata?->sync_status ?? 'idle',
            'pending_count' => $metadata?->pending_records_count ?? 0,
            'unresolved_conflicts_count' => SyncConflict::where('store_id', $this->storeId)
                ->where('resolution_status', 'unresolved')
                ->count(),
            'last_sync_at' => $metadata?->last_sync_timestamp,
            'last_error' => $metadata?->last_error,
        ];
    }
}
