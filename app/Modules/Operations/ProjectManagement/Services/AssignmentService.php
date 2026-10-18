<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Notifications\TaskAssignedNotification;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Who is on what, and whether that is physically possible (EPIC-030).
 *
 * The useful part is not the assignment — it is `workload()`, which adds up what a person has been
 * committed to across overlapping tasks. A plan that has three crews all led by the same foreman in
 * the same week is a plan that will slip, and the only moment anyone can cheaply notice is when the
 * third assignment is made.
 */
class AssignmentService
{
    public function __construct(private readonly CollaborationService $collaboration)
    {
    }

    public function assign(Task $task, ProjectMember $member, array $attributes = []): TaskAssignment
    {
        if ($member->project_id !== $task->project_id) {
            throw new InvalidArgumentException('That person is not a member of this project.');
        }

        if (! $member->is_active) {
            throw new InvalidArgumentException(
                ($member->display_name ?: 'That person') . ' is no longer active on this project.'
            );
        }

        $allocation = (int) ($attributes['allocation_pct'] ?? 100);

        if ($allocation < 1 || $allocation > 100) {
            throw new InvalidArgumentException('An allocation is a percentage of someone\'s time, between 1 and 100.');
        }

        $this->assertNotOnLeave($task, $member);
        $this->assertQualified($member);

        // Only a genuinely new assignment is worth an email. Re-saving somebody's allocation or
        // making them the lead is a change to work they already knew they had.
        $isNew = ! TaskAssignment::where('task_id', $task->id)->where('member_id', $member->id)->exists();

        $assignment = DB::transaction(function () use ($task, $member, $attributes, $allocation) {
            $existing = TaskAssignment::where('task_id', $task->id)->where('member_id', $member->id)->first();

            if ($existing) {
                $existing->forceFill([
                    'role'           => $attributes['role'] ?? $existing->role,
                    'allocation_pct' => $allocation,
                    'is_lead'        => $attributes['is_lead'] ?? $existing->is_lead,
                ])->save();

                $assignment = $existing;
            } else {
                $assignment = TaskAssignment::create([
                    'project_id'     => $task->project_id,
                    'task_id'        => $task->id,
                    'member_id'      => $member->id,
                    'role'           => $attributes['role'] ?? null,
                    'allocation_pct' => $allocation,
                    'is_lead'        => (bool) ($attributes['is_lead'] ?? false),
                    'assigned_at'    => now(),
                    'assigned_by'    => Auth::id(),
                ]);
            }

            // One lead per task: "answerable" stops meaning anything when two people are.
            if ($assignment->is_lead) {
                TaskAssignment::where('task_id', $task->id)
                    ->where('id', '!=', $assignment->id)
                    ->update(['is_lead' => false]);
            }

            // Being given work is a reason to hear about it. Removable like any other watch.
            if ($member->user_id) {
                $this->collaboration->watch($task, (int) $member->user_id, 'assignee');
            }

            return $assignment;
        });

        /*
         * After the write, never inside it. A mail sent from a transaction that later rolls back
         * has still been sent, and the recipient is left holding a notice about work that was
         * never actually given to them.
         */
        if ($isNew) {
            $this->announceAssignment($task, $member);
        }

        return $assignment;
    }

    /**
     * Tell somebody they have been given work.
     *
     * Assignments used to happen in silence: the work appeared on a board the person had no reason
     * to open that morning, and the first they heard of it was being asked why it had not started.
     *
     * Never the person doing the assigning — putting a task on yourself and being emailed about it
     * is noise, and noise is what makes the rest of the channel ignorable.
     */
    private function announceAssignment(Task $task, ProjectMember $member): void
    {
        if (! $member->user_id || (int) $member->user_id === (int) Auth::id()) {
            return;
        }

        $recipient = User::query()->find($member->user_id);

        if (! $recipient) {
            return;
        }

        $actor = Auth::id() ? User::query()->with('person')->find(Auth::id()) : null;

        // Names live on the HR record on this platform; there is no `users.name` column.
        $assignedBy = $actor?->person
            ? trim($actor->person->first_name . ' ' . $actor->person->surname) ?: $actor->email
            : $actor?->email;

        $recipient->notify(new TaskAssignedNotification($task, $assignedBy));
    }

    public function unassign(Task $task, ProjectMember $member): void
    {
        TaskAssignment::where('task_id', $task->id)->where('member_id', $member->id)->delete();
    }

    /**
     * Refuse to book somebody who is on approved leave for the task's dates (`hr.leave`).
     *
     * This is the whole reason the HR integration exists, and until now the check was implemented
     * in `HrEmployeeProvider::isAvailable()` and called by nothing — a capability the setup page
     * advertised and no screen used.
     *
     * Three conditions have to hold before it fires, and each absence is a reason to say nothing
     * rather than to guess:
     *
     *   - the member is linked to an HR employee (a subcontractor's foreman is not);
     *   - the task has dates (unscheduled work overlaps no leave by definition);
     *   - the provider says it actually checked leave — `supportsLeave()` is false on a tenant with
     *     the people module but no leave management, and "I did not look" must never be reported
     *     as "they are free".
     */
    private function assertNotOnLeave(Task $task, ProjectMember $member): void
    {
        $member->loadMissing('externalRef');

        $employeeId = $member->externalRef?->source_id;

        if (! $employeeId || ! $task->planned_start || ! $task->planned_end) {
            return;
        }

        $employees = app(ProviderRegistry::class)->for(EmployeeProvider::class);

        $result = $employees->isAvailable(
            (string) $employeeId,
            $task->planned_start->toDateString(),
            $task->planned_end->toDateString(),
        );

        // `checked` empty means nothing was actually verified — an unknown, not an all-clear.
        if ($result->checked === [] || $result->available) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s is on approved leave over %s – %s. Move the task, or assign somebody else.',
            $member->display_name ?: 'That person',
            $task->planned_start->toFormattedDateString(),
            $task->planned_end->toFormattedDateString(),
        ));
    }

    /**
     * Hours HR recorded for this person across the window.
     *
     * Shown next to booked hours rather than instead of them: the two disagreeing is the useful
     * signal, and collapsing them into one number hides exactly the case worth seeing.
     */
    private function attendedHours(ProjectMember $member, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $member->loadMissing('externalRef');

        $employeeId = $member->externalRef?->source_id;

        if (! $employeeId) {
            return null;
        }

        return app(ProviderRegistry::class)->for(EmployeeProvider::class)
            ->attendedHours((string) $employeeId, $from->toDateString(), $to->toDateString());
    }

    /**
     * Refuse to assign work to somebody whose competencies have lapsed (`lms.competencies`).
     *
     * Only *expired* qualifications block. Having none recorded is not evidence of being
     * unqualified — most people on most tasks have nothing in the register, and treating silence
     * as a failure would make the module unusable on the day it was switched on. An expired
     * certificate is different: somebody recorded it, and it has run out.
     */
    private function assertQualified(ProjectMember $member): void
    {
        $member->loadMissing('externalRef');

        $employeeId = $member->externalRef?->source_id;

        if (! $employeeId) {
            return;
        }

        $held = app(ProviderRegistry::class)->for(EmployeeProvider::class)->qualificationsFor((string) $employeeId);

        $expired = array_values(array_filter($held, fn (array $q) => $q['expired']));

        if ($expired === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s has a lapsed competency (%s). Renew it, or assign somebody else.',
            $member->display_name ?: 'That person',
            implode(', ', array_slice(array_column($expired, 'name'), 0, 3)),
        ));
    }

    /**
     * What one person is committed to across a window, and where that exceeds their day.
     *
     * Allocations are summed across tasks whose dates overlap. Two 60% assignments in the same week
     * is 120% of a person, which is the thing worth surfacing — nobody plans to over-commit, they
     * just do it one task at a time.
     *
     * @return array{days: array<string, array{allocated:int, tasks:list<string>}>, overloaded: list<string>}
     */
    public function workload(ProjectMember $member, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assignments = TaskAssignment::where('member_id', $member->id)
            ->with('task:id,uuid,code,name,planned_start,planned_end')
            ->get()
            ->filter(fn (TaskAssignment $a) => $a->task
                && $a->task->planned_start
                && $a->task->planned_end
                && $a->task->planned_start <= $to
                && $a->task->planned_end >= $from);

        $days = [];

        foreach ($assignments as $assignment) {
            $start = CarbonImmutable::parse($assignment->task->planned_start)->startOfDay()->max($from);
            $end = CarbonImmutable::parse($assignment->task->planned_end)->startOfDay()->min($to);

            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $key = $day->toDateString();

                $days[$key] ??= ['allocated' => 0, 'tasks' => []];
                $days[$key]['allocated'] += $assignment->allocation_pct;
                $days[$key]['tasks'][] = $assignment->task->code;
            }
        }

        ksort($days);

        return [
            'days'       => $days,
            'overloaded' => array_keys(array_filter($days, fn (array $d) => $d['allocated'] > 100)),
        ];
    }

    /**
     * Everyone on a project, with how much of their time is already spoken for.
     *
     * @return list<array<string,mixed>>
     */
    public function team(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hours = $this->hoursByMember($project, $from, $to);

        return ProjectMember::where('project_id', $project->id)
            ->active()
            ->get()
            ->map(function (ProjectMember $member) use ($from, $to, $hours) {
                $workload = $this->workload($member, $from, $to);

                return [
                    'id'         => $member->uuid,
                    'name'       => $member->display_name,
                    'role'       => $member->role_key,
                    'tasks'      => TaskAssignment::where('member_id', $member->id)->count(),
                    // What this person actually booked in the window, and how much of it has been
                    // signed off. Approving somebody's hours used to change nothing anybody could
                    // see on the page they work from: the row carried their commitment and their
                    // attendance, and never the hours themselves, so an approver had no way of
                    // telling a full timesheet from an empty one.
                    'booked'     => round($hours[$member->id]['booked'] ?? 0.0, 2),
                    'approved'   => round($hours[$member->id]['approved'] ?? 0.0, 2),
                    'awaiting'   => round($hours[$member->id]['awaiting'] ?? 0.0, 2),
                    // Hours attendance actually recorded, beside what was booked
                    // (`hr.attendance`). Null when it is not tracked — zero would invent a
                    // discrepancy the system never found.
                    'attended'   => $this->attendedHours($member, $from, $to),
                    'overloaded' => count($workload['overloaded']),
                    // The worst day in the window, which is the one that will actually go wrong.
                    'peak'       => $workload['days'] === [] ? 0 : max(array_column($workload['days'], 'allocated')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Booked, approved and still-waiting hours per person, in one query.
     *
     * Grouped in the database rather than per member: the team panel is drawn on every visit to the
     * delivery page, and three more queries per person is how a twelve-person job becomes slow and
     * a hundred-person one becomes unusable.
     *
     * @return array<int, array{booked: float, approved: float, awaiting: float}>
     */
    private function hoursByMember(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TimeEntry::where('project_id', $project->id)
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('member_id')
            ->groupBy('member_id')
            ->selectRaw('member_id')
            // Rejected hours are excluded from every figure: they are a record of something that
            // did not happen, and counting them as booked would have the page disagree with the
            // invoice.
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'rejected' THEN hours ELSE 0 END), 0) as booked")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END), 0) as approved")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'submitted' THEN hours ELSE 0 END), 0) as awaiting")
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->member_id => [
                'booked'   => (float) $row->booked,
                'approved' => (float) $row->approved,
                'awaiting' => (float) $row->awaiting,
            ]])
            ->all();
    }
}
