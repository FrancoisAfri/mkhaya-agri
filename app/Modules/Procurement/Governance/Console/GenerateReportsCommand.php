<?php

namespace App\Modules\Procurement\Governance\Console;

use App\Support\Console\RunsPerTenant;
use App\Modules\Procurement\Governance\Services\ReportingService;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates the scheduled §3 reports — quarterly and annual — as frozen runs.
 *
 *   php artisan procgov:reports                       # any definition due today
 *   php artisan procgov:reports --period=quarter      # force the quarter that just closed
 *   php artisan procgov:reports --period=annual --company=7
 *
 * §3 asks for reports to be produced automatically rather than compiled by hand. A definition
 * carries the filter selection; this command runs it and stores the result, so the figures a board
 * pack quotes are the figures as at the reporting date and stay that way even as the underlying
 * transactions are later corrected.
 *
 * The default run generates the period that has just *ended*, not the one in progress. A quarterly
 * report generated mid-quarter is a partial figure with an authoritative-looking title, which is a
 * reliable way to have the wrong number quoted in a meeting.
 */
class GenerateReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procgov:reports
                            {--company= : Restrict to one company id}
                            {--period= : quarter|annual — force a period instead of running due definitions}';

    protected $description = 'Generate scheduled procurement reports (spend, supplier, performance, compliance)';

    public function handle(TenantContext $tenants, ReportingService $reporting, SettingsService $settings): int
    {
        $period = $this->option('period');
        $generated = 0;

        $run = function (int $companyId) use ($reporting, $settings, $period, &$generated): void {
            // Counted per company as well as in total: the shared accumulator alone made each
            // tenant's line report the running total, so the last company appeared to have
            // generated every report in the run.
            $forCompany = 0;
            $today = Carbon::today();

            // The period that has just closed.
            $label = $settings->financialYearLabel($companyId, $today->copy()->subDay());
            [$fyStart, $fyEnd] = $settings->financialYearRange($companyId, $label);

            if ($period === 'annual') {
                $ranges = [['label' => $label, 'from' => $fyStart, 'to' => $fyEnd]];
            } else {
                $quarters = $reporting->quarters($companyId, $label);
                $closed = collect($quarters)->filter(fn ($q) => $q['to']->lt($today))->last();
                $ranges = $closed ? [$closed] : [];
            }

            if ($ranges === []) {
                return;
            }

            $definitions = DB::table('procgov_report_definitions')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_scheduled', true)
                ->get();

            // A tenant who has configured nothing still gets the standard set, because §3's value
            // is in the reports existing, not in someone having remembered to define them.
            $targets = $definitions->isNotEmpty()
                ? $definitions->map(fn ($d) => [
                    'type' => $d->report_type,
                    'filters' => json_decode((string) $d->filters, true) ?: [],
                    'id' => (int) $d->id,
                ])
                : collect([
                    ['type' => 'SPEND', 'filters' => [], 'id' => null],
                    ['type' => 'SUPPLIER', 'filters' => [], 'id' => null],
                    ['type' => 'PERFORMANCE', 'filters' => [], 'id' => null],
                    ['type' => 'COMPLIANCE', 'filters' => [], 'id' => null],
                ]);

            foreach ($ranges as $range) {
                foreach ($targets as $target) {
                    $already = DB::table('procgov_report_runs')
                        ->where('company_id', $companyId)
                        ->where('report_type', $target['type'])
                        ->where('period_label', $range['label'])
                        ->exists();

                    if ($already) {
                        continue;
                    }

                    $reporting->generate(
                        $companyId,
                        $target['type'],
                        $range['from'],
                        $range['to'],
                        $target['filters'],
                        $target['id'],
                        $range['label'],
                    );

                    $generated++;
                    $forCompany++;
                }
            }

            if ($forCompany > 0) {
                $this->components->twoColumnDetail("Company {$companyId}", "{$forCompany} report(s) generated");
            }
        };

        $this->eachTenant($tenants, $run);

        $this->components->info("Procurement reporting: {$generated} report run(s) generated.");

        $this->reportTenantFailures('Report generation');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
