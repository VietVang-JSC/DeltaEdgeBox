<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
                            {--type=daily : Backup type: daily or intraday}
                            {--max=7 : Maximum number of daily backups to retain}';

    protected $description = 'Backup SQLite database to Backblaze B2 with dual retention policy';

    public function handle(): int
    {
        $storeId = config('app.store_id');
        $type = $this->option('type'); // 'daily' or 'intraday'
        $maxBackups = (int) $this->option('max');

        if (!$storeId) {
            $this->error('STORE_ID not configured in .env');
            return 1;
        }

        $this->info("Starting {$type} database backup for store: {$storeId}");

        try {
            // Step 1: Verify database file exists
            $dbPath = database_path('database.sqlite');
            if (!File::exists($dbPath)) {
                $this->error("Database file not found: {$dbPath}");
                return 1;
            }

            // Step 2: Check if B2 is configured
            if (!config('filesystems.disks.b2')) {
                $this->error('Backblaze B2 not configured. Please setup B2 credentials in .env');
                return 1;
            }

            // Step 3: Generate filename based on type
            if ($type === 'intraday') {
                // Intraday: Always overwrite 'latest.sqlite'
                $filename = 'latest.sqlite';
                $this->info("Intraday backup: Will overwrite existing latest.sqlite");
            } else {
                // Daily: Use date-based filename
                $filename = now()->format('Y-m-d') . '.sqlite';
                $this->info("Daily backup: Filename = {$filename}");
            }

            $path = "backups/{$storeId}/{$type}/{$filename}";

            // Step 4: Upload to Backblaze B2
            $this->info("Uploading database to Backblaze B2...");
            $fileContent = File::get($dbPath);
            $fileSize = strlen($fileContent);

            Storage::disk('b2')->put($path, $fileContent);

            $this->info("✓ Backup uploaded successfully!");
            $this->info("  Path: {$path}");
            $this->info("  Size: " . number_format($fileSize / 1024, 2) . " KB");

            // Step 5: Cleanup old daily backups (only for daily type)
            if ($type === 'daily') {
                $this->cleanupOldBackups($storeId, $maxBackups);
            }

            // Step 6: Log success
            Log::info('Database backup completed', [
                'store_id' => $storeId,
                'type' => $type,
                'path' => $path,
                'size_bytes' => $fileSize,
            ]);

            $this->info("✓ Backup completed successfully!");
            return 0;

        } catch (\Exception $e) {
            $this->error("Backup failed: " . $e->getMessage());

            Log::error('Database backup failed', [
                'store_id' => $storeId,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * Remove old daily backups, keeping only the most recent $maxBackups
     */
    protected function cleanupOldBackups(string $storeId, int $maxBackups): void
    {
        $this->info("Cleaning up old backups (keeping last {$maxBackups})...");

        $directory = "backups/{$storeId}/daily";
        $files = Storage::disk('b2')->files($directory);

        if (empty($files)) {
            $this->info("No backups to clean up.");
            return;
        }

        // Sort files by name (date-based, so alphabetical = chronological)
        sort($files);

        // If we have more than maxBackups, delete oldest ones
        if (count($files) > $maxBackups) {
            $filesToDelete = array_slice($files, 0, count($files) - $maxBackups);

            foreach ($filesToDelete as $file) {
                Storage::disk('b2')->delete($file);
                $this->info("  Deleted old backup: {$file}");

                Log::info('Old backup deleted', [
                    'store_id' => $storeId,
                    'file' => $file,
                ]);
            }

            $this->info("✓ Cleaned up " . count($filesToDelete) . " old backup(s)");
        } else {
            $this->info("No cleanup needed (current backups: " . count($files) . ")");
        }
    }
}
