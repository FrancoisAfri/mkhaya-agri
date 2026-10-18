<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Services\LeaveApprovalService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Escalate leave requests that have sat unactioned past the configured SLA.
 *
 * LeaveApprovalService::checkEscalations() already implemented the behaviour —
 * it simply had nothing calling it, so nothing was ever escalated.
 */
class EscalateLeaveRequestsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:escalate
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Escalate leave requests left unapproved beyond the configured escalation window.';

    public function handle(TenantContext $tenant, LeaveApprovalService $approvals): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany($approvals));
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany($approvals));
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:escalate', []);
        $this->info("Dispatched leave escalation to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(LeaveApprovalService $approvals): void
    {
        $config = LeaveConfiguration::first();

        $days = (int) ($config->manager_escalation_days
            ?? $config->number_of_days_until_escalation
            ?? 0);

        if ($days <= 0) {
            $this->line('Escalation window not configured — nothing to do.');
            return;
        }

        $approvals->checkEscalations($days);
        $this->info("Escalation sweep complete (window: {$days} day(s)).");
    }
}
