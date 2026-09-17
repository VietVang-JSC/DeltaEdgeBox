<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Health check endpoint — tries Cloud API first for live config, falls back to local DB.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $storeId = (int) config('app.store_id');
        $resolved = $this->resolveStoreConfig($storeId);

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'deployment_mode' => $resolved['deployment_mode'],
            'edge_routing_active' => $resolved['edge_routing_active'],
            'store_id' => $storeId,
        ]);
    }

    /**
     * Detailed health check with sync status
     */
    public function detailed(): \Illuminate\Http\JsonResponse
    {
        try {
            // Check database connectivity
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Exception $e) {
            $dbStatus = 'disconnected';
        }

        // Get sync status
        $syncStatus = $this->syncService->getSyncStatus();

        // Check disk space
        $diskFree = disk_free_space(base_path());
        $diskTotal = disk_total_space(base_path());
        $diskUsagePercent = $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100, 2) : 0;

        $storeId = (int) config('app.store_id');
        $resolved = $this->resolveStoreConfig($storeId);

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'deployment_mode' => $resolved['deployment_mode'],
            'edge_routing_active' => $resolved['edge_routing_active'],
            'store_id' => $storeId,
            'database' => [
                'status' => $dbStatus,
                'type' => 'SQLite',
                'path' => database_path('database.sqlite'),
            ],
            'sync' => $syncStatus,
            'storage' => [
                'disk_usage_percent' => $diskUsagePercent,
                'free_mb' => round($diskFree / 1024 / 1024, 2),
                'total_mb' => round($diskTotal / 1024 / 1024, 2),
            ],
            'services' => [
                'sync_worker' => 'running',
                'heartbeat' => 'running',
            ],
        ]);
    }

    private function resolveStoreConfig(int $storeId): array
    {
        // 1. Try Cloud API directly (no sync needed) — short timeout, fallback to local.
        $cloudUrl = trim((string) config('app.cloud_api_url', ''));
        if ($cloudUrl !== '') {
            try {
                $resp = Http::timeout(2)->connectTimeout(2)
                    ->get(rtrim($cloudUrl, '/') . '/api/edge-cloud/store-config', [
                        'store_id' => $storeId,
                    ]);
                if ($resp->successful()) {
                    $data = $resp->json();
                    if (isset($data['deployment_mode'])) {
                        // Opportunistically keep local DB in sync
                        try {
                            $local = Store::find($storeId);
                            if ($local && ($local->deployment_mode !== $data['deployment_mode'] || (bool) $local->edge_routing_active !== (bool) ($data['edge_routing_active'] ?? false))) {
                                $local->update([
                                    'deployment_mode' => $data['deployment_mode'],
                                    'edge_routing_active' => (bool) ($data['edge_routing_active'] ?? false),
                                ]);
                            }
                        } catch (\Throwable $e) {
                            // ignore local update errors
                        }
                        return [
                            'deployment_mode' => $data['deployment_mode'],
                            'edge_routing_active' => (bool) ($data['edge_routing_active'] ?? false),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('Health cloud config fetch failed, fallback to local: ' . $e->getMessage());
            }
        }

        // 2. Fallback to local DB (synced via master-sync) or env default
        $store = Store::find($storeId);
        return [
            'deployment_mode' => $store?->deployment_mode ?? config('app.deployment_mode', 'offline-first'),
            'edge_routing_active' => $store ? (bool) $store->edge_routing_active : true,
        ];
    }
}
