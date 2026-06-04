<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    /**
     * Trigger a database backup
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function trigger(Request $request)
    {
        try {
            $storeId = env('STORE_ID');
            $type = $request->input('type', 'intraday'); // 'daily' or 'intraday'
            $maxBackups = (int) $request->input('max', 30);

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            // Validate type
            if (!in_array($type, ['daily', 'intraday'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid backup type. Use "daily" or "intraday"',
                ], 400);
            }

            // Verify database file exists
            $dbPath = database_path('database.sqlite');
            if (!File::exists($dbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Database file not found',
                ], 500);
            }

            // Check if B2 is configured
            if (!config('filesystems.disks.b2') || !env('B2_BUCKET')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backblaze B2 not configured. Please set B2_KEY_ID, B2_APPLICATION_KEY, and B2_BUCKET in .env',
                    'hint' => 'See BACKUP_SYSTEM.md for setup instructions',
                ], 500);
            }

            // Generate filename based on type
            if ($type === 'intraday') {
                $filename = 'latest.sqlite';
            } else {
                $filename = now()->format('Y-m-d') . '.sqlite';
            }

            $path = "backups/{$storeId}/{$type}/{$filename}";

            // Upload to Backblaze B2
            $fileContent = File::get($dbPath);
            $fileSize = strlen($fileContent);

            Storage::disk('b2')->put($path, $fileContent);

            // Cleanup old daily backups if needed
            if ($type === 'daily') {
                $this->cleanupOldBackups($storeId, $maxBackups);
            }

            Log::info('Database backup completed via API', [
                'store_id' => $storeId,
                'type' => $type,
                'path' => $path,
                'size_bytes' => $fileSize,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Backup completed successfully',
                'data' => [
                    'type' => $type,
                    'path' => $path,
                    'size_kb' => round($fileSize / 1024, 2),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Database backup failed via API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List available backups
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        try {
            $storeId = env('STORE_ID');
            $type = $request->input('type', 'all'); // 'daily', 'intraday', or 'all'

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            $backups = [];

            if ($type === 'all' || $type === 'daily') {
                $dailyBackups = $this->getBackupsList($storeId, 'daily');
                $backups['daily'] = $dailyBackups;
            }

            if ($type === 'all' || $type === 'intraday') {
                $intradayBackups = $this->getBackupsList($storeId, 'intraday');
                $backups['intraday'] = $intradayBackups;
            }

            return response()->json([
                'success' => true,
                'data' => $backups,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list backups', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to list backups: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore database from backup
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore(Request $request)
    {
        try {
            $storeId = env('STORE_ID');
            $type = $request->input('type', 'intraday');
            $date = $request->input('date'); // Optional for daily backups
            $force = $request->boolean('force', false);

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            // Determine which backup to restore
            if ($type === 'intraday' || !$date) {
                $filename = 'latest.sqlite';
            } else {
                $filename = $date . '.sqlite';
            }

            $path = "backups/{$storeId}/{$type}/{$filename}";

            // Check if backup exists
            if (!Storage::disk('b2')->exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => "Backup file not found: {$path}",
                    'available_backups' => $this->getBackupsList($storeId, $type),
                ], 404);
            }

            // Download backup
            $backupContent = Storage::disk('b2')->get($path);
            $backupSize = strlen($backupContent);

            // Create backup of current database before restoring
            $dbPath = database_path('database.sqlite');
            if (File::exists($dbPath)) {
                $backupCurrentPath = database_path('database.sqlite.backup.' . now()->format('Y-m-d_H-i-s'));
                File::copy($dbPath, $backupCurrentPath);
            }

            // Restore the backup
            File::put($dbPath, $backupContent);

            Log::info('Database restored from backup via API', [
                'store_id' => $storeId,
                'source' => $path,
                'size_bytes' => $backupSize,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Database restored successfully',
                'data' => [
                    'source' => $path,
                    'size_kb' => round($backupSize / 1024, 2),
                    'timestamp' => now()->toIso8601String(),
                    'warning' => 'Please restart the application to apply changes',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Database restore failed via API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Restore failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get backup status and statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status()
    {
        try {
            $storeId = env('STORE_ID');

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            // Check if B2 is configured
            $b2Configured = config('filesystems.disks.b2') !== null && env('B2_BUCKET') !== null;

            $dailyBackups = [];
            $intradayBackups = [];

            // Only try to get backups if B2 is configured
            if ($b2Configured) {
                try {
                    $dailyBackups = $this->getBackupsList($storeId, 'daily');
                    $intradayBackups = $this->getBackupsList($storeId, 'intraday');
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch backup list', ['error' => $e->getMessage()]);
                }
            }

            $dbPath = database_path('database.sqlite');
            $currentDbSize = File::exists($dbPath) ? File::size($dbPath) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'store_id' => $storeId,
                    'current_database' => [
                        'size_kb' => round($currentDbSize / 1024, 2),
                        'path' => $dbPath,
                    ],
                    'backups' => [
                        'daily' => [
                            'count' => count($dailyBackups),
                            'latest' => !empty($dailyBackups) ? $dailyBackups[0] : null,
                            'total_size_kb' => array_sum(array_column($dailyBackups, 'size_kb')),
                        ],
                        'intraday' => [
                            'count' => count($intradayBackups),
                            'latest' => !empty($intradayBackups) ? $intradayBackups[0] : null,
                            'total_size_kb' => array_sum(array_column($intradayBackups, 'size_kb')),
                        ],
                    ],
                    'b2_configured' => $b2Configured,
                    'b2_setup_required' => !$b2Configured,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get backup status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get backup status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of backups for a store and type
     *
     * @param string $storeId
     * @param string $type
     * @return array
     */
    protected function getBackupsList(string $storeId, string $type): array
    {
        $directory = "backups/{$storeId}/{$type}";

        if (!Storage::disk('b2')->exists($directory)) {
            return [];
        }

        $files = Storage::disk('b2')->files($directory);

        if (empty($files)) {
            return [];
        }

        // Sort by name (newest first)
        rsort($files);

        $backups = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $metadata = Storage::disk('b2')->lastModified($file);

            $backups[] = [
                'filename' => $basename,
                'path' => $file,
                'date' => date('Y-m-d', $metadata),
                'timestamp' => date('Y-m-d H:i:s', $metadata),
                'size_kb' => round(Storage::disk('b2')->size($file) / 1024, 2),
            ];
        }

        return $backups;
    }

    /**
     * Remove old daily backups, keeping only the most recent $maxBackups
     *
     * @param string $storeId
     * @param int $maxBackups
     * @return void
     */
    protected function cleanupOldBackups(string $storeId, int $maxBackups): void
    {
        $directory = "backups/{$storeId}/daily";
        $files = Storage::disk('b2')->files($directory);

        if (empty($files)) {
            return;
        }

        // Sort files by name (date-based, so alphabetical = chronological)
        sort($files);

        // If we have more than maxBackups, delete oldest ones
        if (count($files) > $maxBackups) {
            $filesToDelete = array_slice($files, 0, count($files) - $maxBackups);

            foreach ($filesToDelete as $file) {
                Storage::disk('b2')->delete($file);

                Log::info('Old backup deleted during API cleanup', [
                    'store_id' => $storeId,
                    'file' => $file,
                ]);
            }
        }
    }
}
