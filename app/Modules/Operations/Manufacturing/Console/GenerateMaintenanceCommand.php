<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\Maintenance;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Raises the preventive maintenance work orders that have fallen due -- by calendar, by the hours
 * a machine has run, or by a run of breakdowns (MFG-013). Per tenant.
 */
class GenerateMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:generate-maintenance {--company= : Only this company id}';

    protected $description = 'Raise due preventive maintenance work orders for production machines';

    protected function tenantFailureLabel(): string
    {
        return 'Preventive maintenance generation';
    }

    public function handle(TenantContext $tenants, Maintenance $maintenance): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($maintenance) {
            foreach ($maintenance->generateDue($companyId) as $r) {
                $this->line("  company {$companyId}: {$r['work_order']} {$r['rule']} ({$r['trigger']})");
            }
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
