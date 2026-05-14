<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore
                            {date? : Date to restore from (YYYY-MM-DD format). If not provided, uses latest intraday backup}
                            {--type=intraday : Backup type to restore from: daily or intraday}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Restore SQLite database from Backblaze B2 backup';

    public function handle(): int
    {
        $storeId = config('app.store_id');
        $type = $this->option('type');
        $date = $this->argument('date');

        if (!$storeId) {
            $this->error('STORE_ID not configured in .env');
            return 1;
        }

        // Determine which backup to restore
        if ($type === 'intraday' || !$date) {
            // Restore from latest intraday backup
            $filename = 'latest.sqlite';
            $this->info("Will restore from latest intraday backup");
        } else {
            // Restore from specific daily backup
            $filename = $date . '.sqlite';
            $this->info("Will restore from daily backup: {$filename}");
        }

        $path = "backups/{$storeId}/{$type}/{$filename}";

        // Check if backup exists
        if (!Storage::disk('b2')->exists($path)) {
            $this->error("Backup file not found: {$path}");
            $this->info("Available backups:");
            $this->listAvailableBackups($storeId, $type);
            return 1;
        }

        // Confirm before overwriting
        if (!$this->option('force')) {
            if (!$this->confirm("This will OVERWRITE the current database. Continue?", false)) {
                $this->info("Restore cancelled.");
                return 0;
            }
        }

        try {
            $this->info("Downloading backup from Backblaze B2...");
            $backupContent = Storage::disk('b2')->get($path);
            $backupSize = strlen($backupContent);

            $this->info("✓ Downloaded backup (Size: " . number_format($backupSize / 1024, 2) . " KB)");

            // Create backup of current database before restoring
            $dbPath = database_path('database.sqlite');
            if (File::exists($dbPath)) {
                $backupCurrentPath = database_path('database.sqlite.backup.' . now()->format('Y-m-d_H-i-s'));
                File::copy($dbPath, $backupCurrentPath);
                $this->info("✓ Current database backed up to: {$backupCurrentPath}");
            }

            // Restore the backup
            File::put($dbPath, $backupContent);

            $this->info("✓ Database restored successfully!");
            $this->info("  Source: {$path}");
            $this->info("  Destination: {$dbPath}");

            Log::info('Database restored from backup', [
                'store_id' => $storeId,
                'source' => $path,
                'size_bytes' => $backupSize,
            ]);

            $this->warn("\n⚠ IMPORTANT: Please restart the application to apply changes.");

            return 0;

        } catch (\Exception $e) {
            $this->error("Restore failed: " . $e->getMessage());

            Log::error('Database restore failed', [
                'store_id' => $storeId,
                'source' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * List available backups for the store
     */
    protected function listAvailableBackups(string $storeId, string $type): void
    {
        $directory = "backups/{$storeId}/{$type}";

        if (!Storage::disk('b2')->exists($directory)) {
            $this->info("  No backups found in: {$directory}");
            return;
        }

        $files = Storage::disk('b2')->files($directory);

        if (empty($files)) {
            $this->info("  No backups found.");
            return;
        }

        // Sort by name (newest first)
        rsort($files);

        foreach ($files as $file) {
            $basename = basename($file);
            $this->info("  - {$basename}");
        }
    }
}
