<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Accounting\AccountingSync;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Every hour: for each organisation whose books are Xero, QuickBooks or Sage, push the farm's
 * invoices, bills and journals not yet there and pull back the payments recorded there. Failed
 * pushes are retried with back-off by agri:integrations-retry; nothing is sent twice.
 */
class SyncAccounting extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:accounting-sync {--company= : Only this organisation}';

    protected $description = 'Sync Mkhaya Agri farm documents and journals with an external book of record (Xero, QuickBooks, Sage)';

    public function handle(TenantContext $tenants, AccountingSync $sync): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($sync) {
            try {
                $r = $sync->run($companyId);
                $this->line("Company {$companyId}: ".($r['note'] ?? "{$r['queued']} queued, {$r['pushed']} pushed, {$r['failed']} failed, {$r['payments']} payment(s) pulled."));
            } catch (\Throwable $e) {
                $this->error("Company {$companyId}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
