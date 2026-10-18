<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use Spatie\Permission\Models\Role;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsComplianceEscalation;
use App\Modules\HR\LMS\Models\LmsComplianceReportSnapshot;
use App\Modules\HR\LMS\Models\LmsComplianceRule;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsTrainingReminder;
use App\Modules\HR\LMS\Notifications\TrainingEscalationNotification;
use App\Modules\HR\LMS\Notifications\TrainingReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LmsComplianceService
{
    // ── Assign mandatory training ──────────────────────────────────────────

    /**
     * Generate compliance assignments for a rule for all matching employees.
     * Safe to call repeatedly — uses updateOrCreate keyed on (rule+user+period).
     */
    public function assignMandatoryTraining(LmsComplianceRule $rule): int
    {
        $users      = $this->resolveUsersForRule($rule);
        $periodStart= $this->currentPeriodStart($rule);
        $periodEnd  = $this->currentPeriodEnd($rule);
        $dueDate    = now()->addDays($rule->due_within_days)->toDateString();
        $created    = 0;

        foreach ($users as $user) {
            // Auto-link to existing enrollment if the user already started the course
            $enrollment = LmsEnrollment::where('user_id', $user->id)
                ->where('course_id', $rule->course_id)
                ->whereIn('status', ['enrolled', 'in_progress', 'completed'])
                ->latest()
                ->first();

            $assignment = LmsComplianceAssignment::updateOrCreate(
                [
                    'rule_id'                 => $rule->id,
                    'user_id'                 => $user->id,
                    'compliance_period_start' => $periodStart,
                ],
                [
                    'course_id'               => $rule->course_id,
                    'enrollment_id'           => $enrollment?->id,
                    'assigned_at'             => now()->toDateString(),
                    'due_date'                => $dueDate,
                    'compliance_period_end'   => $periodEnd,
                    'status'                  => $enrollment?->status === 'completed' ? 'completed' : 'pending',
                    'completed_at'            => $enrollment?->status === 'completed' ? $enrollment->completed_at : null,
                ]
            );

            if ($assignment->wasRecentlyCreated) $created++;
        }

        return $created;
    }

    /** Run assignMandatoryTraining for every active rule. */
    public function assignAllActiveRules(): array
    {
        $counts = [];
        LmsComplianceRule::active()->with('course')->each(function (LmsComplianceRule $rule) use (&$counts) {
            $counts[$rule->id] = $this->assignMandatoryTraining($rule);
        });
        return $counts;
    }

    // ── Track overdue training ─────────────────────────────────────────────

    /**
     * Scan all pending/in_progress assignments, mark overdue ones.
     * Returns count of newly marked overdue.
     */
    public function trackOverdueTraining(): int
    {
        $count = LmsComplianceAssignment::whereIn('status', ['pending', 'in_progress'])
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        // Sync completed enrollments → mark assignment completed
        LmsComplianceAssignment::whereIn('status', ['pending', 'in_progress', 'overdue'])
            ->whereHas('enrollment', fn ($q) => $q->where('status', 'completed'))
            ->each(function (LmsComplianceAssignment $a) {
                $a->update([
                    'status'       => 'completed',
                    'completed_at' => $a->enrollment?->completed_at ?? now(),
                ]);
            });

        return $count;
    }

    // ── Send training reminders ────────────────────────────────────────────

    /**
     * Fire reminder notifications for all assignments whose rule has
     * a reminder scheduled for today's days-until-due value.
     * Returns count of reminders sent.
     */
    /**
     * The reminder schedule as a list of days, whatever shape the column happens to hold.
     *
     * `reminder_days_before` is jsonb cast to array, and jsonb accepts anything — `14` is valid
     * JSON and casts to an int, not a list. The `?? [30, 14, 7, 1]` that used to guard this only
     * caught null, so a rule holding a scalar threw a TypeError out of in_array() and took the
     * whole sweep with it: every company after the one owning that rule silently stopped receiving
     * training reminders, and the run reported nothing wrong.
     *
     * Writes are validated as arrays today, so this defends the rows already out there (a seeder
     * wrote three) and anything reaching the column other than the form — imports, direct SQL.
     *
     * @return list<int>
     */
    private function reminderSchedule(mixed $configured): array
    {
        $schedule = match (true) {
            is_array($configured)   => $configured,
            is_numeric($configured) => [$configured],
            default                 => [],
        };

        $days = array_values(array_map('intval', array_filter($schedule, 'is_numeric')));

        return $days ?: [30, 14, 7, 1];
    }

    public function sendTrainingReminders(): int
    {
        $sent = 0;

        // Upcoming due
        $upcoming = LmsComplianceAssignment::whereIn('status', ['pending', 'in_progress'])
            ->where('due_date', '>=', now()->toDateString())
            ->with(['rule', 'user', 'course'])
            ->get();

        foreach ($upcoming as $assignment) {
            $daysUntil = $assignment->daysUntilDue();
            $schedule  = $this->reminderSchedule($assignment->rule?->reminder_days_before);

            if (!in_array($daysUntil, $schedule)) continue;
            if ($this->reminderAlreadySent($assignment, $daysUntil)) continue;

            $this->fireReminder($assignment, $daysUntil);
            $sent++;
        }

        // Overdue reminders (daily while overdue)
        $overdue = LmsComplianceAssignment::where('status', 'overdue')
            ->with(['rule', 'user', 'course'])
            ->get();

        foreach ($overdue as $assignment) {
            $daysOver = $assignment->daysOverdue();
            if ($this->reminderAlreadySentToday($assignment, 'overdue')) continue;

            $this->fireReminder($assignment, -$daysOver);
            $sent++;
        }

        return $sent;
    }

    // ── Escalate overdue training ──────────────────────────────────────────

    /**
     * For assignments overdue beyond the grace period, create escalations
     * and notify the appropriate manager/HR.
     * Returns count of new escalations created.
     */
    public function escalateOverdueTraining(): int
    {
        $escalated = 0;

        $assignments = LmsComplianceAssignment::where('status', 'overdue')
            ->with(['rule', 'user.person', 'course'])
            ->get();

        foreach ($assignments as $assignment) {
            $daysOver   = $assignment->daysOverdue();
            $grace      = $assignment->rule?->grace_period_days ?? 7;
            $escalateAt = $assignment->rule?->escalate_after_days ?? 3;

            if ($daysOver < ($grace + $escalateAt)) continue;

            // Don't re-escalate if an open escalation already exists for this degree of overdue
            $existing = LmsComplianceEscalation::where('assignment_id', $assignment->id)
                ->where('status', 'open')
                ->exists();

            if ($existing) continue;

            $escalatedTo = $this->resolveEscalationRecipient($assignment);
            $person      = $assignment->user?->person;
            $employeeName= $person ? trim("{$person->first_name} {$person->surname}") : $assignment->user?->email;

            DB::transaction(function () use ($assignment, $escalatedTo, $daysOver, $employeeName) {
                LmsComplianceEscalation::create([
                    'assignment_id'    => $assignment->id,
                    'user_id'          => $assignment->user_id,
                    'escalated_to_id'  => $escalatedTo?->id,
                    'escalated_to_role'=> $assignment->rule?->escalate_to_role ?? 'manager',
                    'days_overdue'     => $daysOver,
                    'status'           => 'open',
                    'escalated_at'     => now(),
                ]);

                if ($escalatedTo) {
                    $escalatedTo->notify(new TrainingEscalationNotification($assignment, $daysOver, $employeeName));
                }
            });

            $escalated++;
        }

        return $escalated;
    }

    // ── Generate compliance report ─────────────────────────────────────────

    public function generateTrainingComplianceReport(
        string $reportType = 'overall',
        ?int $scopeId = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        int $generatedBy = 0
    ): LmsComplianceReportSnapshot {
        $start = $periodStart ?? now()->startOfYear();
        $end   = $periodEnd   ?? now();

        $query = LmsComplianceAssignment::with(['rule', 'user.person', 'course'])
            ->whereBetween('assigned_at', [$start->toDateString(), $end->toDateString()]);

        $scopeName = 'All';

        match ($reportType) {
            'position' => $query->whereHas('user.person', fn ($q) => $q->where('position_id', $scopeId)),
            'department'=> $query->whereHas('user.person', fn ($q) => $q->where('division_level_1', $scopeId)),
            'individual'=> $query->where('user_id', $scopeId),
            default     => null,
        };

        if ($scopeId) {
            $scopeName = match ($reportType) {
                'position'   => HrPosition::find($scopeId)?->name ?? "Position #{$scopeId}",
                'department' => "Department #{$scopeId}",
                'individual' => User::find($scopeId)?->person
                    ? trim(User::find($scopeId)->person->first_name . ' ' . User::find($scopeId)->person->surname)
                    : "User #{$scopeId}",
                default      => 'All',
            };
        }

        $assignments = $query->get();

        $total     = $assignments->count();
        $completed = $assignments->where('status', 'completed')->count();
        $overdue   = $assignments->where('status', 'overdue')->count();
        $pending   = $assignments->whereIn('status', ['pending', 'in_progress'])->count();
        $rate      = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        // Per-rule breakdown
        $byRule = $assignments->groupBy('rule_id')->map(function ($group) {
            $total     = $group->count();
            $completed = $group->where('status', 'completed')->count();
            $first     = $group->first();
            return [
                'rule_name'      => $first->rule?->name,
                'course_title'   => $first->course?->title,
                'total'          => $total,
                'completed'      => $completed,
                'overdue'        => $group->where('status', 'overdue')->count(),
                'compliance_rate'=> $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            ];
        })->values()->all();

        // Overdue employees list
        $overdueList = $assignments->where('status', 'overdue')
            ->map(function ($a) {
                $person = $a->user?->person;
                return [
                    'user_id'      => $a->user_id,
                    'name'         => $person ? trim("{$person->first_name} {$person->surname}") : $a->user?->email,
                    'course_title' => $a->course?->title,
                    'due_date'     => $a->due_date?->toDateString(),
                    'days_overdue' => $a->daysOverdue(),
                ];
            })->sortByDesc('days_overdue')->values()->all();

        $snapshot = LmsComplianceReportSnapshot::create([
            'report_type'       => $reportType,
            'scope_id'          => $scopeId,
            'scope_name'        => $scopeName,
            'period_start'      => $start->toDateString(),
            'period_end'        => $end->toDateString(),
            'total_employees'   => $assignments->pluck('user_id')->unique()->count(),
            'total_assignments' => $total,
            'completed_count'   => $completed,
            'overdue_count'     => $overdue,
            'pending_count'     => $pending,
            'compliance_rate'   => $rate,
            'report_data'       => ['by_rule' => $byRule, 'overdue_list' => $overdueList],
            'generated_at'      => now(),
            'generated_by'      => $generatedBy ?: null,
        ]);

        return $snapshot;
    }

    // ── Block restricted actions ───────────────────────────────────────────

    /**
     * Returns true (action blocked) if the user has an overdue assignment
     * for any rule that lists $actionKey in its restrict_actions array.
     */
    public function blockRestrictedActionIfTrainingMissing(int $userId, string $actionKey): bool
    {
        return LmsComplianceAssignment::where('user_id', $userId)
            ->where('status', 'overdue')
            ->whereHas('rule', function ($q) use ($actionKey) {
                $q->whereNotNull('restrict_actions')
                  ->whereRaw("restrict_actions::jsonb @> ?", [json_encode([$actionKey])]);
            })
            ->exists();
    }

    /** Returns the list of blocked action keys for a user. */
    public function getBlockedActions(int $userId): array
    {
        $overdueRules = LmsComplianceAssignment::where('user_id', $userId)
            ->where('status', 'overdue')
            ->whereHas('rule', fn ($q) => $q->whereNotNull('restrict_actions'))
            ->with('rule:id,restrict_actions')
            ->get();

        return $overdueRules
            ->flatMap(fn ($a) => $a->rule?->restrict_actions ?? [])
            ->unique()
            ->values()
            ->all();
    }

    // ── Waive assignment ───────────────────────────────────────────────────

    public function waiveAssignment(LmsComplianceAssignment $assignment, int $waivedBy, string $reason): void
    {
        $assignment->update([
            'status'       => 'waived',
            'waived_by'    => $waivedBy,
            'waived_reason'=> $reason,
            'waived_at'    => now(),
        ]);
    }

    // ── Acknowledge escalation ─────────────────────────────────────────────

    public function acknowledgeEscalation(LmsComplianceEscalation $escalation, int $resolvedBy): void
    {
        $escalation->update([
            'status'      => 'acknowledged',
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function resolveUsersForRule(LmsComplianceRule $rule): Collection
    {
        $query = User::with('person')->whereHas('person');

        return match ($rule->scope_type) {
            'position'   => $query->whereHas('person', fn ($q) => $q->where('position_id', $rule->scope_id))->get(),
            'department' => $query->whereHas('person', fn ($q) => $q->where('division_level_1', $rule->scope_id))->get(),
            'location'   => $query->whereHas('person', fn ($q) => $q->where('division_level_2', $rule->scope_id))->get(),
            'employee'   => User::where('id', $rule->scope_id)->get(),
            default      => $query->get(),   // 'all'
        };
    }

    private function currentPeriodStart(LmsComplianceRule $rule): string
    {
        return match ($rule->frequency) {
            'annual'    => now()->startOfYear()->toDateString(),
            'biannual'  => now()->month <= 6 ? now()->startOfYear()->toDateString() : now()->startOfYear()->addMonths(6)->toDateString(),
            'quarterly' => now()->startOfQuarter()->toDateString(),
            default     => now()->toDateString(),
        };
    }

    private function currentPeriodEnd(LmsComplianceRule $rule): ?string
    {
        return match ($rule->frequency) {
            'annual'    => now()->endOfYear()->toDateString(),
            'biannual'  => now()->month <= 6 ? now()->startOfYear()->addMonths(6)->subDay()->toDateString() : now()->endOfYear()->toDateString(),
            'quarterly' => now()->endOfQuarter()->toDateString(),
            default     => null,
        };
    }

    private function resolveEscalationRecipient(LmsComplianceAssignment $assignment): ?User
    {
        $role = $assignment->rule?->escalate_to_role ?? 'manager';

        // Try to find manager (supervisor) — look for a user with HR role or just use admin for now
        if ($role === 'manager') {
            // Find user's direct manager via person hierarchy if available
            // Fallback: return null (notification still logged in DB)
            return null;
        }

        if ($role === 'hr') {
            return $this->firstUserWithRole('hr');
        }

        return $this->firstUserWithRole('admin');
    }

    /**
     * The first user holding a role, or null when no such role exists on this site.
     *
     * Spatie's role() scope throws RoleDoesNotExist rather than matching nothing, and it matches
     * names exactly. Both bit here: the roles are 'HR' and 'administrator', not 'hr' and 'admin',
     * so every escalation threw — aborting the nightly sweep and leaving every company behind the
     * failure with no checks run at all, while the command reported success.
     *
     * `escalate_to_role` is also free text a tenant configures, so any value that does not name a
     * real role would do the same. Escalation degrades to null instead; the notification is still
     * written to the database, which the caller already handles.
     */
    private function firstUserWithRole(string $name): ?User
    {
        $resolved = Role::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->value('name');

        return $resolved ? User::role($resolved)->first() : null;
    }

    private function fireReminder(LmsComplianceAssignment $assignment, int $daysRelative): void
    {
        $type = $daysRelative < 0 ? 'overdue' : 'upcoming_due';

        LmsTrainingReminder::create([
            'assignment_id' => $assignment->id,
            'user_id'       => $assignment->user_id,
            'reminder_type' => $type,
            'days_relative' => $daysRelative,
            'sent_at'       => now(),
            'sent_via'      => 'both',
        ]);

        try {
            $assignment->user?->notify(new TrainingReminderNotification($assignment, $daysRelative));
        } catch (\Throwable $e) {
            Log::warning('[LmsCompliance] Reminder notification failed', [
                'assignment_id' => $assignment->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function reminderAlreadySent(LmsComplianceAssignment $assignment, int $daysUntil): bool
    {
        return LmsTrainingReminder::where('assignment_id', $assignment->id)
            ->where('days_relative', $daysUntil)
            ->where('reminder_type', 'upcoming_due')
            ->exists();
    }

    private function reminderAlreadySentToday(LmsComplianceAssignment $assignment, string $type): bool
    {
        return LmsTrainingReminder::where('assignment_id', $assignment->id)
            ->where('reminder_type', $type)
            ->whereDate('sent_at', today())
            ->exists();
    }
}
