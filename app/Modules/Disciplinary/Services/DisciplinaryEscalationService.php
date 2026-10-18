<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation;
use App\Modules\Disciplinary\Notifications\DisciplinaryEscalationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DisciplinaryEscalationService
{
    private const DISC_ADMIN_ROLES          = ['super admin', 'administrator', 'HR'];
    private const OVERDUE_INVESTIGATION_DAYS = 14;  // investigations overdue after 14 days
    private const OVERDUE_HEARING_DAYS       = 7;   // hearings overdue when past scheduled date by 7+ days
    private const OVERDUE_NOTICE_DAYS        = 7;   // cases with no notice after incident date + 7 days

    public function __construct(
        private readonly DisciplinaryNotificationService $notifications,
    ) {}

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Run all overdue checks. Called by the scheduled Artisan command.
     * Returns a summary of escalations triggered.
     */
    public function runOverdueChecks(): array
    {
        $summary = [
            'overdue_investigations' => 0,
            'overdue_hearings'       => 0,
            'overdue_notices'        => 0,
            'policy_violations'      => 0,
            'errors'                 => 0,
        ];

        foreach ($this->findOverdueInvestigations() as $investigation) {
            try {
                $this->escalateOverdueInvestigation($investigation);
                $summary['overdue_investigations']++;
            } catch (\Throwable $e) {
                Log::warning("Escalation failed for investigation {$investigation->id}: " . $e->getMessage());
                $summary['errors']++;
            }
        }

        foreach ($this->findOverdueHearings() as $hearing) {
            try {
                $this->escalateOverdueHearing($hearing);
                $summary['overdue_hearings']++;
            } catch (\Throwable $e) {
                Log::warning("Escalation failed for hearing {$hearing->id}: " . $e->getMessage());
                $summary['errors']++;
            }
        }

        foreach ($this->findCasesWithoutNotice() as $case) {
            try {
                $this->escalateOverdueNotice($case);
                $summary['overdue_notices']++;
            } catch (\Throwable $e) {
                Log::warning("Escalation failed for case {$case->id}: " . $e->getMessage());
                $summary['errors']++;
            }
        }

        foreach ($this->findUnresolvedPolicyViolations() as $violation) {
            try {
                $this->escalatePolicyViolation($violation);
                $summary['policy_violations']++;
            } catch (\Throwable $e) {
                Log::warning("Escalation failed for policy violation {$violation->id}: " . $e->getMessage());
                $summary['errors']++;
            }
        }

        activity('disciplinary')
            ->withProperties($summary)
            ->event('escalation_run')
            ->log('Scheduled escalation check completed');

        return $summary;
    }

    // ── Overdue Investigation ─────────────────────────────────────────────────

    public function escalateOverdueInvestigation(DisciplinaryInvestigation $investigation): void
    {
        $investigation->loadMissing(['case.employee.person', 'investigator.person']);

        $daysOverdue = $investigation->created_at
            ? (int) $investigation->created_at->diffInDays(now())
            : 0;

        $caseRef     = $investigation->case?->case_number ?? '—';
        $employee    = $investigation->case?->employeeName() ?? '—';
        $investigator= $investigation->investigator?->person?->full_name
                    ?? $investigation->investigator?->email ?? '—';

        $this->notifyHrAdmins(
            type:    'overdue_investigation',
            subject: "Overdue Investigation — {$caseRef} ({$daysOverdue} days)",
            body:    "The investigation for case **{$caseRef}** (employee: {$employee}, investigator: {$investigator}) has been open for **{$daysOverdue} days** and is overdue. Immediate action required.",
            url:     route('disciplinary.investigations.show', $investigation),
            caseNumber: $caseRef,
            subjectId:  $investigation->id,
        );

        // Mark investigation as escalated in notes
        if ($investigation->case) {
            $investigation->case->increment('escalation_count', 0); // touch without altering count
        }

        activity('disciplinary')
            ->performedOn($investigation)
            ->withProperties([
                'days_overdue' => $daysOverdue,
                'case_number'  => $caseRef,
                'investigator' => $investigator,
            ])
            ->event('escalated_overdue_investigation')
            ->log("Overdue investigation escalated: {$caseRef} ({$daysOverdue} days)");
    }

    // ── Overdue Hearing ───────────────────────────────────────────────────────

    public function escalateOverdueHearing(DisciplinaryHearing $hearing): void
    {
        $hearing->loadMissing(['case.employee.person']);

        $daysOverdue = $hearing->scheduled_at
            ? (int) $hearing->scheduled_at->diffInDays(now())
            : 0;

        $caseRef  = $hearing->case?->case_number ?? '—';
        $employee = $hearing->case?->employeeName() ?? '—';
        $date     = $hearing->scheduled_at?->format('d M Y H:i') ?? '—';

        $this->notifyHrAdmins(
            type:    'overdue_hearing',
            subject: "Overdue Hearing — {$caseRef} (was {$date})",
            body:    "The disciplinary hearing for case **{$caseRef}** (employee: {$employee}) was scheduled for {$date} but has not been completed. It is now **{$daysOverdue} day(s)** overdue.",
            url:     route('disciplinary.cases.show', $hearing->case_id),
            caseNumber: $caseRef,
            subjectId:  $hearing->id,
        );

        activity('disciplinary')
            ->performedOn($hearing)
            ->withProperties([
                'days_overdue'   => $daysOverdue,
                'scheduled_at'   => $hearing->scheduled_at?->toDateTimeString(),
                'case_number'    => $caseRef,
            ])
            ->event('escalated_overdue_hearing')
            ->log("Overdue hearing escalated: {$caseRef} (was {$date})");
    }

    // ── Overdue Notice ────────────────────────────────────────────────────────

    public function escalateOverdueNotice(DisciplinaryCase $case): void
    {
        $case->loadMissing(['employee.person', 'category']);

        $daysOverdue = $case->incident_date
            ? (int) $case->incident_date->diffInDays(now())
            : 0;

        $this->notifyHrAdmins(
            type:    'overdue_notice',
            subject: "Notice Not Issued — {$case->case_number} ({$daysOverdue} days since incident)",
            body:    "Case **{$case->case_number}** for employee **{$case->employeeName()}** has had no disciplinary notice issued {$daysOverdue} days after the incident date. Action required.",
            url:     route('disciplinary.cases.show', $case),
            caseNumber: $case->case_number,
            subjectId:  $case->id,
        );

        activity('disciplinary')
            ->performedOn($case)
            ->withProperties([
                'days_since_incident' => $daysOverdue,
                'case_number'         => $case->case_number,
                'employee'            => $case->employeeName(),
            ])
            ->event('escalated_overdue_notice')
            ->log("Overdue notice escalated for case {$case->case_number}");
    }

    // ── Policy Violation ──────────────────────────────────────────────────────

    public function escalatePolicyViolation(DisciplinaryPolicyViolation $violation): void
    {
        $violation->loadMissing(['actor.person', 'case']);

        $daysOpen = (int) $violation->created_at->diffInDays(now());
        $employee = $violation->actor?->person?->full_name
                 ?? $violation->actor?->email ?? '—';
        $policy   = $violation->policy_name ?? $violation->violation_type ?? 'Unknown policy';
        $caseRef  = $violation->case?->case_number;

        $this->notifyHrAdmins(
            type:    'policy_violation',
            subject: "Unresolved Policy Violation — {$employee} ({$daysOpen} days)",
            body:    "A policy violation for **{$employee}** regarding **{$policy}** has been open for **{$daysOpen} days** without resolution.",
            url:     $caseRef ? route('disciplinary.cases.show', $violation->case_id) : route('disciplinary.policy-enforcement.index'),
            caseNumber: $caseRef,
            subjectId:  $violation->id,
        );

        activity('disciplinary')
            ->performedOn($violation)
            ->withProperties([
                'days_open' => $daysOpen,
                'employee'  => $employee,
                'policy'    => $policy,
            ])
            ->event('escalated_policy_violation')
            ->log("Policy violation escalated: {$employee} — {$policy} ({$daysOpen} days)");
    }

    // ── Discovery queries ─────────────────────────────────────────────────────

    private function findOverdueInvestigations(): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = now()->subDays(self::OVERDUE_INVESTIGATION_DAYS);

        return DisciplinaryInvestigation::with(['case', 'investigator.person'])
            ->whereNotIn('status', ['completed', 'closed'])
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('case', fn($q) => $q->whereHas('status', fn($q2) => $q2->where('is_closed', true)))
            ->get();
    }

    private function findOverdueHearings(): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = now()->subDays(self::OVERDUE_HEARING_DAYS);

        return DisciplinaryHearing::with(['case.employee.person'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where('scheduled_at', '<=', $cutoff)
            ->get();
    }

    private function findCasesWithoutNotice(): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = now()->subDays(self::OVERDUE_NOTICE_DAYS);

        return DisciplinaryCase::with(['employee.person'])
            ->whereNull('notice_issued_date')
            ->where('incident_date', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })
            ->get();
    }

    private function findUnresolvedPolicyViolations(): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = now()->subDays(14);

        return DisciplinaryPolicyViolation::with(['actor.person', 'case'])
            ->where('is_resolved', false)
            ->whereNull('resolved_at')
            ->where('created_at', '<=', $cutoff)
            ->get();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function notifyHrAdmins(
        string $type, string $subject, string $body,
        ?string $url = null, ?string $caseNumber = null, ?int $subjectId = null
    ): void {
        $notification = new DisciplinaryEscalationNotification(
            escalationType: $type,
            subject:        $subject,
            body:           $body,
            url:            $url,
            caseNumber:     $caseNumber,
            subjectId:      $subjectId,
        );

        User::role(self::DISC_ADMIN_ROLES)->get()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (\Throwable $e) {
                Log::warning("Escalation notification failed [{$user->id}]: " . $e->getMessage());
            }
        });
    }
}
