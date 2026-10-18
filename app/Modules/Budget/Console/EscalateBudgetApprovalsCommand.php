<?php

namespace App\Modules\Budget\Console;

use App\Modules\Budget\Models\BudgetApprovalRequest;
use App\Modules\Budget\Notifications\BudgetApprovalRequestedNotification;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Chase budget approvals nobody has acted on.
 *
 * The failure this catches is the ordinary one: an approver is on leave, an increase sits for nine
 * days, and the first anybody hears about it is a supplier asking why the order was never placed.
 * A queue with no escalation is a queue that quietly grows.
 *
 * What it does is deliberately mild — it re-notifies the same approvers and stamps `escalated_at`.
 * It does not reassign, and it does not approve. Auto-approving an allocation because nobody looked
 * at it would defeat the entire point of having asked.
 *
 * Only flows that named a window are chased. A tenant that left `escalate_after_hours` empty has
 * said it will manage its own queue, and second-guessing that would send everybody a reminder they
 * did not ask for.
 */
class EscalateBudgetApprovalsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'budgets:escalate-approvals {--company= : One company id, instead of every tenant}';

    protected $description = 'Re-notify approvers about budget decisions past their flow\'s escalation window.';

    public function handle(BudgetApprovalService $approvals, TenantContext $tenants): int
    {
        $chased = 0;

        $run = function () use ($approvals, &$chased) {
            $pending = BudgetApprovalRequest::pending()
                ->whereNotNull('flow_id')
                ->with(['flow.steps', 'budget'])
                ->get()
                ->filter(function (BudgetApprovalRequest $r) {
                    $window = $r->flow?->escalate_after_hours;

                    if (! $window) {
                        return false;
                    }

                    // Measured from the last chase, not from submission — otherwise every run
                    // re-notifies everything that has ever gone overdue.
                    $since = $r->escalated_at ?? $r->created_at;

                    return $since && $since->diffInHours(now()) >= $window;
                });

            foreach ($pending as $request) {
                // The same rule that decides who may act decides who is chased, so the reminder
                // never reaches somebody who can do nothing about it.
                $recipients = $approvals->approversFor($request);

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new BudgetApprovalRequestedNotification($request));
                }

                $request->update(['escalated_at' => now()]);
                $chased++;

                $this->line("  chased {$request->subject_type} · {$request->subject_label}");
            }
        };

        if ($company = $this->option('company')) {
            $tenants->run((int) $company, $run);
        } else {
            $this->eachTenant($tenants, $run);
        }

        $this->info("{$chased} approval(s) chased.");

        return self::SUCCESS;
    }
}
