<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Jobs\GenerateCrmReportJob;
use App\Modules\CRM\Models\CrmScheduledReport;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Dispatches any CRM scheduled reports whose next_run_at is due, then rolls
 * their schedule forward. Run on the scheduler (e.g. every five minutes).
 */
class RunCrmScheduledReports extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:run-scheduled-reports';
    protected $description = 'Dispatch due CRM scheduled reports to their recipients';

    public function handle(): int
    {
        $total = 0;

        $this->eachTenant(app(TenantContext::class), function () use (&$total) {
            $due = CrmScheduledReport::with('createdBy')
                ->where('active', true)
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', now())
                ->get();

            foreach ($due as $report) {
                GenerateCrmReportJob::dispatch($report->report_key, $report->recipientList(), $report->ownerScope());

                $report->forceFill([
                    'last_run_at' => now(),
                    'next_run_at' => $report->computeNextRun(),
                ])->save();

                $this->info("Dispatched: {$report->name} ({$report->report_key})");
            }

            $total += $due->count();
        });

        $this->info("CRM scheduled reports processed: {$total}.");

        return self::SUCCESS;
    }
}
