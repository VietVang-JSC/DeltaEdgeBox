<?php

namespace App\Console\Commands;

use App\Services\PrintWorkerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PrintWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'print:worker {--sleep=2 : Seconds to sleep between batches}
                                      {--batch=10 : Number of jobs to process per batch}
                                      {--daemon : Run in daemon mode (continuous)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process print queue and send jobs to DeltaPosWeb_Printer service';

    protected $printWorkerService;

    public function __construct(PrintWorkerService $printWorkerService)
    {
        parent::__construct();
        $this->printWorkerService = $printWorkerService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('🖨️  Starting Print Worker...');
        $this->info("   Sleep interval: {$this->option('sleep')} seconds");
        $this->info("   Batch size: {$this->option('batch')} jobs");
        $this->info("   Printer service: " . env('PRINTER_SERVICE_URL', 'http://localhost:3001'));
        $this->line('');

        // Check if printer service is available
        if (!$this->printWorkerService->isPrinterServiceAvailable()) {
            $this->warn('⚠️  Warning: DeltaPosWeb_Printer service is not available!');
            $this->warn('   Make sure the service is running on the configured URL.');
            $this->line('');
        }

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

        $this->info('Processing print queue...');

        try {
            $results = $this->printWorkerService->processQueue($this->option('batch'));

            $elapsed = round(microtime(true) - $startTime, 2);

            $this->line('');
            $this->info("✅ Processing complete!");
            $this->info("   Processed: {$results['processed']}");
            $this->info("   Success: {$results['success']}");
            $this->info("   Failed: {$results['failed']}");
            $this->info("   Time: {$elapsed}s");

            if (!empty($results['errors'])) {
                $this->line('');
                $this->warn('Errors:');
                foreach ($results['errors'] as $error) {
                    $this->error("   - Job #{$error['job_id']}: {$error['error']}");
                }
            }

        } catch (\Exception $e) {
            $this->error("Failed to process queue: {$e->getMessage()}");
            Log::error('Print worker failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Run in daemon mode (continuous processing)
     */
    protected function runDaemonMode(): void
    {
        $this->info('Running in daemon mode (press Ctrl+C to stop)...');
        $this->line('');

        $iteration = 0;
        $totalProcessed = 0;
        $totalSuccess = 0;
        $totalFailed = 0;

        while (true) {
            $iteration++;
            $this->info("[Iteration #{$iteration}] Checking for pending jobs...");

            try {
                $results = $this->printWorkerService->processQueue($this->option('batch'));

                $totalProcessed += $results['processed'];
                $totalSuccess += $results['success'];
                $totalFailed += $results['failed'];

                if ($results['processed'] > 0) {
                    $this->info("   ✓ Processed: {$results['processed']}, Success: {$results['success']}, Failed: {$results['failed']}");
                } else {
                    $this->comment('   No pending jobs');
                }

                // Show statistics every 10 iterations
                if ($iteration % 10 === 0) {
                    $this->line('');
                    $this->info('━━━ Statistics ━━━');
                    $this->info("Total processed: {$totalProcessed}");
                    $this->info("Total success: {$totalSuccess}");
                    $this->info("Total failed: {$totalFailed}");

                    $stats = $this->printWorkerService->getQueueStats();
                    $this->info("Current queue: {$stats['pending']} pending, {$stats['printing']} printing");
                    $this->info("Printer service: " . ($stats['printer_service_available'] ? '✅ Available' : '❌ Unavailable'));
                    $this->line('');
                }

            } catch (\Exception $e) {
                $this->error("Error in iteration #{$iteration}: {$e->getMessage()}");
                Log::error('Print worker daemon error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                ]);
            }

            // Sleep before next iteration
            sleep($this->option('sleep'));
        }
    }
}
