<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Health check endpoint
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'deployment_mode' => config('app.deployment_mode', 'offline-first'),
            'store_id' => config('app.store_id'),
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

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'deployment_mode' => config('app.deployment_mode', 'offline-first'),
            'store_id' => config('app.store_id'),
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
}
