<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Notifications\ApprovalRequestedNotification;
use App\Modules\Operations\ProjectManagement\Services\ApprovalService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Chase approvals nobody has acted on (EPIC-090).
 *
 * The failure this catches is the ordinary one: an approver is on leave, a request sits for nine
 * days, and the first anyone hears about it is a subcontractor asking why they have not been paid.
 * A queue with no escalation is a queue that quietly grows.
 *
 * What it does is deliberately mild — it re-notifies the same approvers and stamps `escalated_at`.
 * It does not reassign, and it does not approve. Auto-approving something because nobody looked at
 * it would defeat the entire point of having asked.
 *
 * Only flows that named a window are chased. A tenant that left `escalate_after_hours` empty has
 * said they will manage their own queue, and second-guessing that with a default would send
 * everybody a reminder they did not ask for.
 */
class EscalateApprovalsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:escalate-approvals {--company= : One company id, instead of every tenant}';

    protected $description = 'Re-notify approvers about requests that have passed their flow\'s escalation window.';

    public function handle(ApprovalService $approvals, TenantContext $tenants): int
    {
        $chased = 0;

        /*
         * The tenant list comes from TenantContext (active + trial), not from a hand-rolled query.
         * The one this replaced selected `companies.deleted_at`, a column that does not exist, so
         * the command threw before doing any work at all — on every run, for every tenant.
         */
        $this->eachTenant($tenants, function (int $companyId) use ($approvals, &$chased) {
                $pending = ApprovalRequest::pending()
                    ->whereNotNull('flow_id')
                    ->with(['flow.steps', 'project'])
                    ->get()
                    ->filter(function (ApprovalRequest $r) {
                        $window = $r->flow?->escalate_after_hours;

                        if (! $window) {
                            return false;
                        }

                        // Measured from the last chase, not from submission — otherwise every
                        // hourly run re-notifies everything that has ever gone overdue.
                        $since = $r->escalated_at ?? $r->created_at;

                        return $since && $since->diffInHours(now()) >= $window;
                    });

                foreach ($pending as $request) {
                    $recipients = $this->recipients($approvals, $request);

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new ApprovalRequestedNotification($request));
                    }

                    $request->update(['escalated_at' => now()]);
                    $chased++;

                    $this->line("  chased {$request->subject_type} · {$request->subject_label}");
                }
            });

        $this->info("{$chased} approval(s) chased.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function recipients(ApprovalService $approvals, ApprovalRequest $request): \Illuminate\Support\Collection
    {
        $userClass = config('auth.providers.users.model');
        $step = $request->currentStep();

        // The same authorisation rule that decides who may act decides who is chased — so the
        // reminder never goes to somebody who cannot do anything about it.
        return $userClass::query()->limit(500)->get()
            ->filter(fn ($user) => $approvals->canAct($request, $step, $user))
            ->values();
    }
}
