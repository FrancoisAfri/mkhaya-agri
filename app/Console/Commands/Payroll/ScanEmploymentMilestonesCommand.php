<?php

namespace App\Console\Commands\Payroll;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Services\EmploymentMilestoneService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily pass that finds employees who have reached a milestone somebody has to act on.
 *
 * Runs across both payroll engines and every tenant. Safe to run as often as you like: each notice
 * is claimed against a unique index before it is sent, so a second pass on the same day finds the
 * work already done and stays quiet. That is what makes it sane to re-run by hand after an outage
 * rather than wondering which reminders were lost.
 */
class ScanEmploymentMilestonesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'payroll:milestones
                            {--company= : limit to one Mkhaya tenant}
                            {--client= : limit to one bureau client company (uuid)}
                            {--date= : the date to scan as, defaults to today}
                            {--quiet-notices : find and record milestones without notifying anybody}';

    protected $description = 'Raise employment milestone notices (probation, anniversaries, incentive expiry) on both payroll engines.';

    public function handle(TenantContext $tenants, EmploymentMilestoneService $milestones): int
    {
        $asAt = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();
        $clientUuid = $this->option('client');
        $silent = (bool) $this->option('quiet-notices');

        $this->eachTenant($tenants, function (int $companyId) use ($milestones, $asAt, $clientUuid, $silent) {
            $client = $clientUuid ? BureauCompany::where('uuid', $clientUuid)->first() : null;

            if ($clientUuid && ! $client) {
                return;   // belongs to another tenant, or does not exist
            }

            $raised = $milestones->scan($asAt, $client);

            if ($raised->isEmpty()) {
                return;
            }

            $sent = $silent ? 0 : $milestones->notify($raised);

            $this->line(sprintf(
                '  company %d — %d milestone(s) raised, %d notified',
                $companyId,
                $raised->count(),
                $sent,
            ));
        });

        $this->reportTenantFailures('employment milestones');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
