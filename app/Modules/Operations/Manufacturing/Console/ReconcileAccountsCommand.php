<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\ManufacturingAccounting;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/** Reconciles the manufacturing cost subledger with the general ledger (MFG-021). Per tenant. */
class ReconcileAccountsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:reconcile-accounts {--company= : Only this company id}';

    protected $description = 'Reconcile manufacturing WIP and stock movements with the general ledger, and queue exceptions';

    protected function tenantFailureLabel(): string
    {
        return 'Manufacturing accounting reconciliation';
    }

    public function handle(TenantContext $tenants, ManufacturingAccounting $accounting): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($accounting) {
            $r = $accounting->reconcile($companyId);
            $this->line("  company {$companyId}: WIP difference {$r['wip']['difference']}, {$r['open_exceptions']} open exception(s)");
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
