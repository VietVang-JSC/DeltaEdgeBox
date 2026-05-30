<?php

namespace App\Console\Commands;

use App\Services\MasterDataSyncService;
use Illuminate\Console\Command;

class SyncMasterDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'edge:sync-master';

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
        $this->info("Starting background master data sync...");
        $result = $service->syncMasterData();
        if ($result['success']) {
            $this->info("Master sync completed successfully: " . $result['message']);
        } else {
            $this->error("Master sync failed: " . ($result['message'] ?? 'Unknown error'));
        }
    }
}
