<?php

namespace App\Console\Commands;

use App\Services\MasterDataSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMasterDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'edge:sync-master {--force : Force full master sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and synchronize master data from Cloud down to Edge Box SQLite';

    /**
     * Execute the console command.
     */
    public function handle(MasterDataSyncService $service): void
    {
        $lock = Cache::lock('edge-box:sqlite-sync-writer', config('edge_box.sqlite_lock_ttl', 600));
        if (!$lock->get()) {
            $this->warn('Master sync skipped: another sync process is using SQLite.');
            return;
        }

        $this->info("Starting background master data sync...");
        try {
            $result = $service->syncMasterData((bool) $this->option('force'));
            if ($result['success']) {
                $this->info("Master sync completed successfully: " . $result['message']);
            } else {
                $this->error("Master sync failed: " . ($result['message'] ?? 'Unknown error'));
            }
        } finally {
            $lock->release();
        }
    }
}
