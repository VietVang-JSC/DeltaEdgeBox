<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class CleanupSyncLogs extends Command
{
    protected $signature = 'sync:cleanup {--days-queue=7 : Delete synced queue items older than X days}
                                         {--days-logs=30 : Delete sync logs older than X days}';

    protected $description = 'Cleanup old sync logs and synced queue items';

    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle(): int
    {
        $this->info('Starting sync cleanup...');

        try {
            // Call sync service cleanup
            $this->syncService->cleanupOldRecords();

            $this->info('✓ Cleanup completed successfully');

        } catch (\Exception $e) {
            $this->error("✗ Cleanup failed: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
