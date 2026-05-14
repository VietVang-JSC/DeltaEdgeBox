<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:worker {--sleep=10 : Seconds to sleep between batches}
                                      {--batch=50 : Number of items to process per batch}
                                      {--daemon : Run in daemon mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process sync queue and send data to cloud';

    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting Sync Worker...');
        $this->info("Sleep interval: {$this->option('sleep')} seconds");
        $this->info("Batch size: {$this->option('batch')} items");
        $this->info('');

        if ($this->option('daemon')) {
            $this->runDaemonMode();
        } else {
            $this->processOnce();
        }
    }

    /**
     * Process queue once and exit
     */
    protected function processOnce(): void
    {
        $startTime = microtime(true);

        $this->info('Processing sync queue...');

        $result = $this->syncService->processQueue($this->option('batch'));

        $duration = round(microtime(true) - $startTime, 2);

        if ($result['skipped']) {
            $this->warn('Sync skipped (no pending items or offline)');
        } else {
            $this->info("✓ Processed: {$result['success']} succeeded, {$result['failed']} failed");
            $this->info("Duration: {$duration}s");
        }

        if (!empty($result['errors'])) {
            $this->error('Errors:');
            foreach ($result['errors'] as $error) {
                $this->error("  - ID {$error['id']}: {$error['error']}");
            }
        }
    }

    /**
     * Run in daemon mode (continuous processing)
     */
    protected function runDaemonMode(): void
    {
        $this->info('Running in daemon mode (Ctrl+C to stop)');
        $this->info('');

        $iteration = 0;

        while (true) {
            $iteration++;
            $startTime = microtime(true);

            try {
                $result = $this->syncService->processQueue($this->option('batch'));

                $duration = round(microtime(true) - $startTime, 2);

                if (!$result['skipped']) {
                    $this->line("[Iteration {$iteration}] ✓ {$result['success']} synced, {$result['failed']} failed ({$duration}s)");
                } else {
                    $this->line("[Iteration {$iteration}] No items to sync");
                }

            } catch (\Exception $e) {
                $this->error("[Iteration {$iteration}] Error: {$e->getMessage()}");
                Log::error('Sync worker error', ['error' => $e->getMessage()]);
            }

            // Sleep before next iteration
            sleep((int) $this->option('sleep'));
        }
    }
}
