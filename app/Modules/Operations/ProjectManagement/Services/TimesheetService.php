<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\TimeSink;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Booking and approving hours (EPIC-031).
 *
 * The line that matters runs through approval. Logged hours are somebody's claim about their own
 * day; approved hours are a supervisor standing behind it. Only the second reaches the TimeSink,
 * only the second is costed, and derivation treats them differently on purpose (PM-ADR-010) —
 * otherwise an unreviewed timesheet moves the cost report.
 */
class TimesheetService
{
    /** A day has 24 hours in it; anything more across one person's entries is an error worth naming. */
    public const MAX_HOURS_PER_DAY = 24.0;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly InternalEmitter $emitter,
        private readonly AuditLogger $audit,
        private readonly ApprovalService $approvals,
    ) {
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function log(Project $project, ProjectMember $member, array $attributes): TimeEntry
    {
        $hours = (float) ($attributes['hours'] ?? 0);
        $workedOn = CarbonImmutable::parse($attributes['worked_on'] ?? now())->startOfDay();

        if ($hours <= 0) {
            throw new InvalidArgumentException('Time booked has to be more than zero hours.');
        }

        if ($hours > self::MAX_HOURS_PER_DAY) {
            throw new InvalidArgumentException('A day only has ' . (int) self::MAX_HOURS_PER_DAY . ' hours in it.');
        }

        if ($workedOn->isFuture()) {
            // Booking tomorrow's hours is either a typo or a habit worth breaking; either way the
            // ledger should not carry work that has not happened.
            throw new InvalidArgumentException('Hours cannot be booked for a day that has not happened yet.');
        }

        $task = $this->resolveTask($project, $attributes['task_id'] ?? null);

        // The rate, from payroll, when nobody typed one (`hr.labour_rates`). Only as a default —
        // a rate supplied on the form is somebody's deliberate choice for this booking and must
        // never be overwritten by a package figure.
        if (($attributes['rate_per_hour'] ?? null) === null) {
            $attributes['rate_per_hour'] = $this->rateFor($member);
        }

        $alreadyBooked = (float) TimeEntry::where('member_id', $member->id)
            ->whereDate('worked_on', $workedOn)
            ->whereIn('status', ['draft', 'submitted', 'approved'])
            ->sum('hours');

        if ($alreadyBooked + $hours > self::MAX_HOURS_PER_DAY) {
            throw new InvalidArgumentException(sprintf(
                'That would put %s on %s hours for %s, and a day only has %d.',
                $member->display_name ?: 'this person',
                $alreadyBooked + $hours,
                $workedOn->toFormattedDateString(),
                (int) self::MAX_HOURS_PER_DAY,
            ));
        }

        return TimeEntry::create([
            'project_id'    => $project->id,
            'task_id'       => $task?->id,
            'member_id'     => $member->id,
            'worked_on'     => $workedOn,
            'hours'         => round($hours, 2),
            'note'          => $attributes['note'] ?? null,
            'status'        => 'draft',
            'rate_per_hour' => $attributes['rate_per_hour'] ?? null,
            'currency'      => $attributes['currency'] ?? $project->currency,
            'created_by'    => Auth::id(),
        ]);
    }

    /**
     * Submit hours for approval.
     *
     * This used to set a status and stop, which meant "submitted" was a state nobody was told
     * about and nobody was accountable for clearing. It now goes to the approval engine, which
     * routes it by the tenant's own rules and notifies whoever the rules name (EPIC-090).
     *
     * A tenant with no expense/timesheet flow designed still gets routing — to whoever may run the
     * project — because the alternative would be switching the engine on and silently
     * auto-approving every timesheet in the system.
     */
    public function submit(TimeEntry $entry): TimeEntry
    {
        if ($entry->status !== 'draft' && $entry->status !== 'rejected') {
            throw new InvalidArgumentException('Only a draft or a rejected entry can be submitted.');
        }

        $entry->forceFill(['status' => 'submitted', 'rejection_reason' => null])->save();

        $entry->loadMissing('project', 'member');

        // A project can be set to not require sign-off — an internal job where the same routing
        // sends every entry to a manager who approves it on sight. Approving here rather than
        // raising a request nobody will read is the difference between a control and a ritual; the
        // hours still land as approved, so costing and the TimeSink behave identically.
        if (! ($entry->project?->timesheet_approval_required ?? true)) {
            return $this->applyApproval($entry);
        }

        $request = $this->approvals->submit('timesheet', $entry, $entry->project, [
            'label'    => trim(sprintf(
                '%s · %s · %sh',
                $entry->member?->display_name ?? '—',
                $entry->worked_on?->toDateString() ?? '',
                rtrim(rtrim(number_format((float) $entry->hours, 2), '0'), '.'),
            )),
            'amount'   => $entry->rate_per_hour !== null
                ? round((float) $entry->hours * (float) $entry->rate_per_hour, 2)
                : null,
            'currency' => $entry->currency,
        ]);

        // Below the tenant's threshold: approved on submission, which is what a threshold means.
        if ($request === null) {
            return $this->applyApproval($entry);
        }

        return $entry;
    }

    /**
     * Approve hours: cost them, post them to whichever sink is installed, and record them as
     * evidence — all in one transaction, so an approval cannot half-happen.
     */
    public function approve(TimeEntry $entry): TimeEntry
    {
        if ($entry->status !== 'submitted') {
            throw new InvalidArgumentException('Only submitted hours can be approved.');
        }

        if ((int) $entry->created_by === (int) Auth::id() && ! $this->mayApproveOwn()) {
            // Separation of duties: approving your own hours is not approval.
            throw new InvalidArgumentException('Hours have to be approved by someone other than the person who booked them.');
        }

        return $this->applyApproval($entry);
    }

    /**
     * The costing, the posting and the evidence — without the separation-of-duties check.
     *
     * Called directly in two places where the check has already been made or does not apply: by
     * `approve()` above, and when the entry never entered a queue because it fell below the
     * tenant's threshold. Re-running the check in the second case would refuse to auto-approve the
     * very thing the threshold exists to auto-approve.
     */
    public function applyApproval(TimeEntry $entry): TimeEntry
    {
        if ($entry->status === 'approved') {
            return $entry;
        }

        return DB::transaction(function () use ($entry) {
            $cost = $entry->rate_per_hour !== null
                ? round((float) $entry->hours * (float) $entry->rate_per_hour, 2)
                : null;

            $entry->forceFill([
                'status'      => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'cost_amount' => $cost,
            ])->save();

            $reference = $this->providers->for(TimeSink::class)->post([
                'uuid'       => $entry->uuid,
                'project'    => $entry->project->uuid,
                'task'       => $entry->task?->uuid,
                'member'     => $entry->member->uuid,
                'worked_on'  => $entry->worked_on->toDateString(),
                'hours'      => (float) $entry->hours,
                'cost'       => $cost,
                'currency'   => $entry->currency,
            ]);

            $entry->forceFill(['sink_reference' => $reference])->save();

            // Approved hours are verified evidence — the strongest source for actual_hours.
            $this->emitter->emit($entry->project, 'time.approved', [
                'subject_type' => $entry->task_id ? 'task' : 'project',
                'subject_id'   => $entry->task?->uuid ?? $entry->project->uuid,
                'source_ref'   => "time:{$entry->id}",
                'payload'      => [
                    // Both required by the taxonomy: who stood behind these hours, and how many.
                    'approved_by' => (string) (Auth::user()?->email ?? Auth::id() ?? 'system'),
                    'hours'       => (float) $entry->hours,
                    'worked_on'   => $entry->worked_on->toDateString(),
                    'member'      => $entry->member->display_name ?? '',
                ],
            ]);

            return $entry;
        });
    }

    public function reject(TimeEntry $entry, string $reason): TimeEntry
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A rejection needs a reason, so the person can fix it.');
        }

        if ($entry->status !== 'submitted') {
            throw new InvalidArgumentException('Only submitted hours can be rejected.');
        }

        $entry->forceFill(['status' => 'rejected', 'rejection_reason' => $reason])->save();

        return $entry;
    }

    /**
     * Withdraw an approval.
     *
     * The hours stay; what changes is that they are no longer signed off. The evidence already in
     * the ledger is not deleted — a correction is a new event (PM-ADR-002) — so a reversal is
     * recorded rather than erased.
     */
    public function withdrawApproval(TimeEntry $entry, string $reason): TimeEntry
    {
        if ($entry->status !== 'approved') {
            throw new InvalidArgumentException('Those hours are not approved.');
        }

        return DB::transaction(function () use ($entry, $reason) {
            $entry->forceFill([
                'status'           => 'submitted',
                'approved_by'      => null,
                'approved_at'      => null,
                'cost_amount'      => null,
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->recordAction($entry, 'approval_withdrawn', [
                'reason' => $reason,
                'hours'  => (float) $entry->hours,
            ]);

            return $entry;
        });
    }

    /**
     * Hours and cost for a project, split by whether anyone has approved them.
     *
     * @return array{approved_hours: float, pending_hours: float, cost: float, currency: string|null}
     */
    public function summary(Project $project): array
    {
        $rows = TimeEntry::where('project_id', $project->id)
            ->selectRaw('status, sum(hours) as hours, sum(cost_amount) as cost')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'approved_hours' => (float) ($rows['approved']->hours ?? 0),
            // Draft and submitted together: from a cost point of view neither has been agreed.
            'pending_hours'  => (float) ($rows['draft']->hours ?? 0) + (float) ($rows['submitted']->hours ?? 0),
            'cost'           => (float) ($rows['approved']->cost ?? 0),
            'currency'       => $project->currency,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveTask(Project $project, mixed $taskUuid): ?Task
    {
        if (empty($taskUuid)) {
            return null;
        }

        return Task::where('project_id', $project->id)->where('uuid', $taskUuid)->firstOrFail();
    }

    /**
     * A platform or company administrator may approve their own hours, because on a small site there
     * is often nobody else. Everyone else may not.
     */
    /**
     * What this person's hour costs, from HR.
     *
     * Null when there is no authoritative figure, which leaves the entry uncosted rather than
     * costed at a guess — an uncosted hour is visibly missing from the labour report, where a
     * guessed one is silently wrong in it.
     */
    private function rateFor(ProjectMember $member): ?float
    {
        $member->loadMissing('externalRef');

        $employeeId = $member->externalRef?->source_id;

        if (! $employeeId) {
            return null;
        }

        return $this->providers->for(EmployeeProvider::class)->hourlyRateFor((string) $employeeId);
    }

    private function mayApproveOwn(): bool
    {
        $user = Auth::user();

        return $user
            && ($user->is_platform_admin
                || ($user->is_master_admin ?? false)
                || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])));
    }
}
