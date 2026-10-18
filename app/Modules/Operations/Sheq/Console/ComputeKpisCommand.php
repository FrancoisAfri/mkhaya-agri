<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\KpiService;
use App\Modules\Operations\Sheq\Services\SiteRiskScoreService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the KPI read projection (SHEQ-ARC-4, SHEQ-DASH-2).
 *
 *   php artisan sheq:kpis:compute                       # current month, every tenant
 *   php artisan sheq:kpis:compute --company=7 --months=3
 *
 * This command is the only thing that should call `KpiService::computePeriod()`. Dashboards read
 * the snapshot it writes. Without it the projection is never built and every dashboard reports "not
 * computed yet" forever — which is exactly the state the module was in until this audit.
 *
 * Recomputes a trailing window rather than just the current month: incidents get reclassified, days
 * lost accrue after the fact, and exposure hours are usually captured after month end. A projection
 * that only ever wrote the current month would freeze last month's LTIFR at whatever was known on
 * the 1st.
 *
 * Snapshots are written per site as well as company-wide, because a site manager's dashboard is
 * scoped to their site (SHEQ-DASH-1) and summing the company figure would not give them their own.
 */
class ComputeKpisCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:kpis:compute
                            {--company= : Restrict to one company id}
                            {--months=3 : How many months back to recompute, including this one}
                            {--sites : Also compute a snapshot per site}';

    protected $description = 'Compute SHEQ KPI snapshots (LTIFR, TRIR, leading indicators) and '
        .'the site safety risk scores';

    public function handle(TenantContext $tenants, KpiService $kpis,
        SiteRiskScoreService $siteRisk): int
    {
        $months = max(1, (int) $this->option('months'));
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $withSites = (bool) $this->option('sites');

        $written = 0;

        $compute = function (int $companyId) use ($kpis, $siteRisk, $months, $withSites,
            &$written): void {
            $periods = [];
            $cursor = Carbon::today()->startOfMonth();

            for ($i = 0; $i < $months; $i++) {
                $periods[] = [(int) $cursor->year, (int) $cursor->month];
                $cursor->subMonth();
            }

            // Null site first: that is the company-wide figure the executive dashboard reads.
            $sites = [null];

            if ($withSites) {
                $sites = array_merge($sites, DB::table('sheq_sites')
                    ->where('company_id', $companyId)->where('is_active', true)
                    ->whereNull('deleted_at')->pluck('id')->map('intval')->all());
            }

            foreach ($periods as [$year, $month]) {
                foreach ($sites as $siteId) {
                    $kpis->computePeriod($companyId, $year, $month, $siteId);
                    $written++;
                }

                /*
                 * The site safety risk score (§16), on the same cron rather than its own.
                 *
                 * It reads the same registers the KPI snapshot does and is read beside it on the
                 * command centre, so a second schedule entry would only create the possibility
                 * of the two being computed at different times and disagreeing on screen about
                 * what month it is.
                 *
                 * Always every active site, regardless of `--sites`: that flag controls whether
                 * the KPI snapshot is broken down per site, and a risk score per site is the only
                 * thing this score is — a company-wide one would be an average across sites with
                 * nothing in common, which is the figure §16 exists to replace.
                 */
                $siteRisk->computeAll($companyId, sprintf('%04d-%02d', $year, $month));
            }

            $this->components->twoColumnDetail(
                "Company {$companyId}",
                sprintf('%d period(s) × %d scope(s)', count($periods), count($sites))
            );
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $compute($only));
        } else {
            $this->eachTenant($tenants, function ($companyId) use ($compute) {
                if ($companyId !== null) {
                    $compute((int) $companyId);
                }
            });
        }

        $this->components->info("SHEQ KPI projection updated: {$written} snapshot(s).");

        return self::SUCCESS;
    }
}
