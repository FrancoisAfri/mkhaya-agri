<?php

namespace App\Modules\HR\LMS\Console;

use App\Modules\HR\LMS\Services\LmsComplianceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class RunComplianceChecksCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'lms:compliance-checks {--reminders} {--escalate} {--assign}';
    protected $description = 'Run LMS compliance: track overdue, send reminders, escalate, assign rules';

    public function __construct(private LmsComplianceService $service) { parent::__construct(); }

    public function handle(): int
    {
        // Tenant-safe: per-company so rules are matched to their own company's users, escalation
        // recipients are that company's HR/admin, and assignments/logs get the right company_id.
        $this->eachTenant(app(TenantContext::class), function () {
            $overdue = $this->service->trackOverdueTraining();
            $this->info("Overdue marked: {$overdue}");

            if ($this->option('reminders') || ! $this->option('escalate') && ! $this->option('assign')) {
                $sent = $this->service->sendTrainingReminders();
                $this->info("Reminders sent: {$sent}");
            }

            if ($this->option('escalate') || ! $this->option('reminders') && ! $this->option('assign')) {
                $esc = $this->service->escalateOverdueTraining();
                $this->info("Escalations created: {$esc}");
            }

            if ($this->option('assign')) {
                $counts = $this->service->assignAllActiveRules();
                $total  = array_sum($counts);
                $this->info("Assignments created: {$total} across " . count($counts) . ' rules');
            }
        });

        $this->reportTenantFailures('Compliance checks');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
