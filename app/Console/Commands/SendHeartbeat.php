<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SendHeartbeat extends Command
{
    protected $signature = 'heartbeat:send';
    protected $description = 'Send heartbeat to cloud server every 30 seconds';

    public function handle(): void
    {
        $storeId = config('app.store_id', env('STORE_ID'));
        $apiKey = config('app.api_key', env('API_KEY'));
        $cloudUrl = config('app.cloud_api_url', env('CLOUD_API_URL'));

        if (!$apiKey || !$cloudUrl) {
            $this->error('API Key or Cloud URL not configured');
            return;
        }

        try {
            // Get sync status
            $pendingCount = DB::table('sync_metadata')
                ->where('store_id', $storeId)
                ->value('pending_records_count') ?? 0;

            $lastSyncAt = DB::table('sync_metadata')
                ->where('store_id', $storeId)
                ->value('last_sync_timestamp');

            // Send heartbeat
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'X-Store-ID' => $storeId,
            ])->timeout(5)->post("{$cloudUrl}/api/cloud/heartbeat", [
                'store_id' => $storeId,
                'timestamp' => now()->toISOString(),
                'status' => 'online',
                'pending_sync_count' => (int) $pendingCount,
                'last_sync_at' => $lastSyncAt,
                'version' => '1.0.0',
            ]);

            if ($response->successful()) {
                $this->info('✓ Heartbeat sent successfully');

                // Update local metadata
                DB::table('sync_metadata')->updateOrInsert(
                    ['store_id' => $storeId],
                    [
                        'sync_status' => 'idle',
                        'last_error' => null,
                        'updated_at' => now(),
                    ]
                );
            } else {
                $this->warn("⚠ Heartbeat failed: HTTP {$response->status()}");
                Log::warning('Heartbeat failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            $this->error("✗ Heartbeat error: {$e->getMessage()}");
            Log::error('Heartbeat exception', ['error' => $e->getMessage()]);

            // Mark as offline in local metadata
            DB::table('sync_metadata')->updateOrInsert(
                ['store_id' => $storeId],
                [
                    'sync_status' => 'error',
                    'last_error' => substr($e->getMessage(), 0, 255),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
