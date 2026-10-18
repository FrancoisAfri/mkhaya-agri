<?php

namespace App\Modules\Procurement\Governance\Console;

use App\Support\Console\RunsPerTenant;
use App\Modules\Procurement\Governance\Services\WorkloadService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Writes the daily officer-workload snapshot and raises capacity alerts (§4.1–§4.3).
 *
 *   php artisan procgov:workload                 # snapshot + detect, every tenant
 *   php artisan procgov:workload --company=7
 *   php artisan procgov:workload --no-detect     # snapshot only
 *
 * The dashboard itself is computed live and does not depend on this command, so a missed run costs
 * a day of history rather than a broken screen. What it does provide is the trend — "was this
 * officer overloaded in March, when that tender slipped" — which a live query can no longer answer
 * once the work has completed.
 */
class ComputeWorkloadCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procgov:workload
                            {--company= : Restrict to one company id}
                            {--no-detect : Write snapshots without raising capacity alerts}';

    protected $description = 'Snapshot procurement officer workload and raise capacity alerts';

    public function handle(TenantContext $tenants, WorkloadService $workload): int
    {
        $detect = ! $this->option('no-detect');

        $officers = 0;
        $alerts = 0;

        $run = function (int $companyId) use ($workload, $detect, &$officers, &$alerts): void {
            $written = $workload->snapshot($companyId);
            $raised = $detect ? $workload->detect($companyId) : 0;

            $officers += $written;
            $alerts += $raised;

            if ($written > 0) {
                $this->components->twoColumnDetail(
                    "Company {$companyId}",
                    "{$written} officer(s), {$raised} alert(s)"
                );
            }
        };

        $this->eachTenant($tenants, $run);

        $this->components->info("Workload snapshots written for {$officers} officer(s); {$alerts} capacity alert(s) raised.");

        $this->reportTenantFailures('Workload snapshotting');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
