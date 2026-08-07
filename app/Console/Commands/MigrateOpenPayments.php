<?php

namespace App\Console\Commands;

use App\Services\OpenPaymentMigrationService;
use Illuminate\Console\Command;
use Throwable;

class MigrateOpenPayments extends Command
{
    protected $signature = 'edge:migrate-open-payments
                            {--store= : Store ID; defaults to the configured Edge store}
                            {--dry-run : Fetch and count without writing local data or mappings}';

    protected $description = 'Import open Cloud payments into Edge once during Cloud-to-Edge cutover';

    public function handle(OpenPaymentMigrationService $service): int
    {
        try {
            $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
            $result = $service->migrate($storeId, (bool) $this->option('dry-run'));

            $this->info('Open payment migration completed.');
            $this->table(['Metric', 'Value'], [
                ['Store', $result['store_id']],
                ['Cloud open payments', $result['cloud_open_payments']],
                ['Resolvable stale payments', $result['resolvable']],
                ['Resolvable by mapping', $result['resolvable_by_mapping'] ?? 0],
                ['Resolvable by payment code', $result['resolvable_by_payment_code'] ?? 0],
                ['Resolved stale payments', $result['resolved']],
                ['Repaired stale payments', $result['repaired'] ?? 0],
                ['Tables released', $result['released'] ?? 0],
                ['Imported', $result['imported']],
                ['Linked', $result['linked']],
                ['Mapped payments', $result['mapped_payments']],
                ['Mapped details', $result['mapped_payment_details']],
                ['Mapping pending', $result['mapping_pending'] ?? 0],
                ['Conflicts', count($result['conflicts'])],
                ['Dry run', $result['dry_run'] ? 'yes' : 'no'],
            ]);

            foreach ($result['conflicts'] as $conflict) {
                $this->warn(json_encode($conflict, JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
