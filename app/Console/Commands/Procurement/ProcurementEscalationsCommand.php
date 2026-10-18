<?php

namespace App\Console\Commands\Procurement;

use App\Modules\Procurement\Services\ProcurementAlertService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Hourly watchdog: scans for overdue approvals, delivery delays, budget overruns
 * and open policy violations, raising escalations + in-app notifications.
 *
 * Runs once PER COMPANY via TenantContext so each tenant's scan (and the escalations,
 * notifications and alert-dedupe caches it creates) is correctly isolated. Running it
 * untenanted would scan every company's rows together, stamp new escalations with a
 * NULL company_id (invisible to every tenant under RLS), and share alert-dedupe cache
 * keys across tenants.
 */
class ProcurementEscalationsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procurement:escalations {--approval-age=48 : Hours before a pending approval is overdue}';

    protected $description = 'Scan procurement for overdue approvals, delivery delays, budget overruns and policy violations';

    public function handle(ProcurementAlertService $alerts, TenantContext $tenant): int
    {
        $age = (int) $this->option('approval-age');
        $totals = ['overdue_approvals' => 0, 'delivery_delays' => 0, 'budget_overruns' => 0, 'policy_violations' => 0];

        $this->eachTenant($tenant, function () use ($alerts, $age, &$totals) {
            $res = $alerts->run($age);
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) ($res[$k] ?? 0);
            }
        });

        $this->info(sprintf(
            'Escalations (all companies): %d overdue approvals, %d delivery delays, %d budget overruns, %d open policy violations.',
            $totals['overdue_approvals'], $totals['delivery_delays'], $totals['budget_overruns'], $totals['policy_violations']
        ));

        return self::SUCCESS;
    }
}
