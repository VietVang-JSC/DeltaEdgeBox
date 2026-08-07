<?php

namespace App\Console\Commands;

use App\Services\OpenPaymentMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePayments extends Command
{
    protected $signature = 'edge:reconcile-payments
                            {--store= : Store ID; defaults to configured Edge store}
                            {--dry-run : Fetch and report without local writes}
                            {--daemon : Run continuously}
                            {--sleep=30 : Seconds between daemon iterations}
                            {--batch=100 : Reserved reconciliation batch size}';

    protected $description = 'Reconcile Cloud-resolved payments and release their Edge tables';

    private bool $stopping = false;

    public function handle(OpenPaymentMigrationService $service): int
    {
        $this->registerSignals();

        do {
            $exitCode = $this->runOnce($service);
            if (! $this->option('daemon') || $this->stopping) {
                return $exitCode;
            }
            sleep(max(1, (int) $this->option('sleep')));
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        } while (! $this->stopping);

        return self::SUCCESS;
    }

    private function runOnce(OpenPaymentMigrationService $service): int
    {
        $storeId = $this->option('store') !== null
            ? (int) $this->option('store')
            : (int) config('edge_box.store_id');
        $lock = Cache::lock("edge-sync-store-{$storeId}", (int) config('edge_box.sqlite_lock_ttl', 120));

        if (! $lock->get()) {
            $this->warn("Payment reconcile skipped for store {$storeId}: SQLite writer lock is held.");

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        try {
            $result = $service->reconcile($storeId, (bool) $this->option('dry-run'));
            $this->table(['Metric', 'Value'], [
                ['Store', $storeId],
                ['Resolvable', $result['resolvable']],
                ['By mapping', $result['by_mapping']],
                ['By payment code', $result['by_payment_code']],
                ['Resolved', $result['resolved']],
                ['Repaired', $result['repaired']],
                ['Tables released', $result['released']],
                ['Conflicts', count($result['conflicts'])],
                ['Mapping pending', $result['mapping_pending'] ?? 0],
                ['Duration ms', (int) ((microtime(true) - $startedAt) * 1000)],
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('OPEN_PAYMENT_RECONCILE_FAILED', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function registerSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stopping = true);
        pcntl_signal(SIGINT, fn () => $this->stopping = true);
    }
}
