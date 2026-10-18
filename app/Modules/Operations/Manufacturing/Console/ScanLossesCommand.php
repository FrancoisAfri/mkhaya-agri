<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\LossDetector;
use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Runs the loss detectors and escalates overdue investigations (MFG-012). Per tenant.
 */
class ScanLossesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:scan-losses {--company= : Only this company id}';

    protected $description = 'Detect manufacturing waste and losses, and escalate overdue investigations';

    protected function tenantFailureLabel(): string
    {
        return 'Manufacturing loss scan';
    }

    public function handle(TenantContext $tenants, LossDetector $detector, LossInvestigation $investigation): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($detector, $investigation) {
            $raised = array_sum($detector->scan($companyId));
            $escalated = $investigation->escalateOverdue($companyId);
            $this->line("  company {$companyId}: {$raised} raised, {$escalated} escalated");
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
