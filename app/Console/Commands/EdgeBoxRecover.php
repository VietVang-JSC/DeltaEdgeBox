<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EdgeBoxRecover extends Command
{
    protected $signature = 'edgebox:recover {store_id} {--api_key=}';
    protected $description = 'Recover Edge Box from cloud backup';

    public function handle(): int
    {
        $storeId = $this->argument('store_id');
        $apiKey = $this->option('api_key') ?: env('API_KEY');
        $cloudUrl = env('CLOUD_API_URL', 'https://api.deltapos.cloud');

        $this->info("Starting Edge Box recovery for store: {$storeId}");

        // Step 1: Verify store credentials
        $this->info('Step 1: Verifying store credentials...');
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->get("{$cloudUrl}/api/cloud/stores/{$storeId}/verify");

            if (!$response->successful()) {
                $this->error('Store verification failed');
                return 1;
            }

            $storeData = $response->json();

            if ($storeData['deployment_mode'] !== 'offline-first') {
                $this->error('This store is not configured for offline-first mode');
                return 1;
            }

            $this->info('✓ Store verified');

        } catch (\Exception $e) {
            $this->error("Verification failed: {$e->getMessage()}");
            return 1;
        }

        // Step 2: Download latest database snapshot
        $this->info('Step 2: Downloading database snapshot...');
        try {
            $snapshotResponse = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->get("{$cloudUrl}/api/cloud/stores/{$storeId}/snapshot/latest");

            if (!$snapshotResponse->successful()) {
                $this->error('Failed to download snapshot');
                return 1;
            }

            $snapshotUrl = $snapshotResponse->json('download_url');
            $snapshotPath = storage_path('app/recovery/snapshot.sqlite');

            // Create directory if not exists
            if (!file_exists(dirname($snapshotPath))) {
                mkdir(dirname($snapshotPath), 0755, true);
            }

            // Download snapshot
            file_put_contents($snapshotPath, file_get_contents($snapshotUrl));

            $this->info('✓ Snapshot downloaded (' . number_format(filesize($snapshotPath) / 1024 / 1024, 2) . ' MB)');

        } catch (\Exception $e) {
            $this->error("Snapshot download failed: {$e->getMessage()}");
            return 1;
        }

        // Step 3: Restore database
        $this->info('Step 3: Restoring database...');
        $dbPath = database_path('database.sqlite');

        // Backup current database if exists
        if (file_exists($dbPath)) {
            copy($dbPath, $dbPath . '.backup.' . date('Y-m-d_H-i-s'));
            $this->info('  ✓ Current database backed up');
        }

        // Replace with snapshot
        copy($snapshotPath, $dbPath);
        $this->info('✓ Database restored');

        // Step 4: Get pending sync operations since snapshot
        $this->info('Step 4: Fetching pending sync operations...');
        try {
            $snapshotTimestamp = $snapshotResponse->json('timestamp');

            $pendingOps = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->get("{$cloudUrl}/api/cloud/stores/{$storeId}/pending-ops", [
                'since' => $snapshotTimestamp,
            ])->json('operations', []);

            $this->info('✓ Found ' . count($pendingOps) . ' pending operations');

        } catch (\Exception $e) {
            $this->warn("Could not fetch pending operations: {$e->getMessage()}");
            $pendingOps = [];
        }

        // Step 5: Replay pending operations
        if (!empty($pendingOps)) {
            $this->info('Step 5: Replaying pending operations...');

            $replayed = 0;
            $failed = 0;

            foreach ($pendingOps as $op) {
                try {
                    $this->replayOperation($op);
                    $replayed++;

                    if ($replayed % 100 === 0) {
                        $this->info("  Replayed {$replayed} / " . count($pendingOps));
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to replay operation: {$e->getMessage()}");
                    $failed++;
                }
            }

            $this->info("✓ Replayed {$replayed} operations ({$failed} failed)");
        }

        // Step 6: Update metadata
        $this->info('Step 6: Updating metadata...');
        DB::table('sync_metadata')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'last_sync_timestamp' => now(),
                'sync_status' => 'idle',
                'pending_records_count' => 0,
                'updated_at' => now(),
            ]
        );
        $this->info('✓ Metadata updated');

        // Step 7: Cleanup
        $this->info('Step 7: Cleaning up...');
        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
        }
        $this->info('✓ Cleanup completed');

        $this->info('');
        $this->info('═══════════════════════════════════════');
        $this->info('  Edge Box Recovery Complete! ✅');
        $this->info('═══════════════════════════════════════');
        $this->info('');
        $this->info("Store ID: {$storeId}");
        $this->info("Operations replayed: " . count($pendingOps));
        $this->info('');
        $this->info('The Edge Box is now ready for use.');
        $this->info('Sync worker will automatically sync new data to cloud.');

        return 0;
    }

    protected function replayOperation(array $operation): void
    {
        // Insert/update/delete based on operation type
        switch ($operation['type']) {
            case 'create':
                DB::table($operation['table'])->insert($operation['data']);
                break;

            case 'update':
                DB::table($operation['table'])
                    ->where('id', $operation['record_id'])
                    ->update($operation['data']);
                break;

            case 'delete':
                DB::table($operation['table'])
                    ->where('id', $operation['record_id'])
                    ->delete();
                break;
        }
    }
}
