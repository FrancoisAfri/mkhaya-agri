<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Ai\SafetyAnalytics;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Nightly analytics: trends, hotspots, recurrence, forecasts, contractor scores.
 *
 * Scheduled rather than computed on page load. These queries scan a year of incidents across every
 * tenant, and a dashboard that ran them on every open would be slow for everybody to spare a
 * nightly job — and would also violate SHEQ-ARC-4, which keeps writes off GET paths.
 */
class RunAnalyticsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:analytics:run {--company= : Restrict to one company id}';

    protected $description = 'Compute SHEQ safety analytics and contractor performance scores';

    public function handle(TenantContext $tenants, SafetyAnalytics $analytics,
        ContractorPerformanceService $contractors): int
    {
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $totals = ['insights' => 0, 'contractors' => 0];

        $run = function (int $companyId) use ($analytics, $contractors, &$totals): void {
            $found = $analytics->runAll($companyId);
            $count = array_sum(array_map('count', $found));
            $scored = $contractors->computeAll($companyId);

            $totals['insights'] += $count;
            $totals['contractors'] += $scored;

            if ($count > 0 || $scored > 0) {
                $this->components->twoColumnDetail("Company {$companyId}",
                    "{$count} insight(s), {$scored} contractor(s) scored");
            }
        };

        $only !== null
            ? $tenants->run($only, fn () => $run($only))
            : $this->eachTenant($tenants, fn ($id) => $id === null ? null : $run((int) $id));

        $this->components->info(sprintf('%d insight(s), %d contractor score(s).',
            $totals['insights'], $totals['contractors']));

        return self::SUCCESS;
    }
}
