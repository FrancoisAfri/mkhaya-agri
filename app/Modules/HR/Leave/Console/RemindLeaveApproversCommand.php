<?php

namespace App\Modules\HR\Leave\Console;

use App\Core\User\Models\User;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Notifications\LeavePendingApprovalReminderNotification;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\Carbon;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Remind approvers about leave requests still sitting in their queue, before the
 * escalation sweep takes the decision out of their hands.
 */
class RemindLeaveApproversCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:remind-approvers
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Remind managers about leave requests awaiting their approval.';

    public function handle(TenantContext $tenant): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany());
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany());
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:remind-approvers', []);
        $this->info("Dispatched approver reminders to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(): void
    {
        $config = LeaveConfiguration::first();
        $days   = (int) ($config->number_of_days_to_remind_manager ?? 0);

        if ($days <= 0) {
            $this->line('Manager reminder window not configured — nothing to do.');
            return;
        }

        $threshold = Carbon::now()->subDays($days);
        $sent = 0;

        LeaveRequest::pending()
            ->whereNotNull('current_approver_id')
            ->where('submitted_at', '<=', $threshold)
            ->with('employee')
            ->each(function (LeaveRequest $request) use (&$sent) {
                $approver = User::find($request->current_approver_id);
                if (! $approver) {
                    return;
                }

                $waiting = $request->submitted_at
                    ? (int) $request->submitted_at->diffInDays(Carbon::now())
                    : 0;

                $approver->notify(new LeavePendingApprovalReminderNotification($request, $waiting));
                $sent++;
            });

        $this->info("Sent {$sent} approver reminder(s).");
    }
}
