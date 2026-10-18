<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCoachingSession;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Notifications\DisciplinaryCaseAssignedNotification;
use App\Modules\Disciplinary\Notifications\DisciplinaryEscalationNotification;
use App\Modules\Disciplinary\Notifications\DisciplinaryHearingScheduledNotification;
use App\Modules\Disciplinary\Notifications\DisciplinaryIncidentAssignedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Central service for all disciplinary notification dispatch.
 * Use this service instead of calling ->notify() directly on models.
 */
class DisciplinaryNotificationService
{
    private const DISC_ADMIN_ROLES = ['super admin', 'administrator', 'HR'];

    // ── New Case ──────────────────────────────────────────────────────────────

    /**
     * Notify HR admins when a new disciplinary case is opened.
     */
    public function notifyNewCase(DisciplinaryCase $case): void
    {
        $case->loadMissing(['employee.person', 'category']);

        $this->hrAdmins()->each(function (User $hr) use ($case) {
            $this->send($hr, new DisciplinaryEscalationNotification(
                escalationType: 'new_case',
                subject: "New Disciplinary Case — {$case->case_number}",
                body:    "A new disciplinary case has been opened for **{$case->employeeName()}**: {$case->title}.",
                url:     route('disciplinary.cases.show', $case),
                caseNumber: $case->case_number,
                subjectId:  $case->id,
            ));
        });

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($case)
            ->withProperties(['case_number' => $case->case_number, 'employee' => $case->employeeName()])
            ->event('notified_new_case')
            ->log("HR notified of new case {$case->case_number}");
    }

    // ── Hearing ───────────────────────────────────────────────────────────────

    /**
     * Notify all parties when a hearing is scheduled.
     * Sends role-specific emails to: employee, chairperson, HR reps, investigator.
     */
    public function notifyHearingScheduled(DisciplinaryHearing $hearing): void
    {
        $hearing->loadMissing(['case.employee.person', 'attendees.user.person']);

        $notified = [];

        // Employee
        $employee = $hearing->case?->employee;
        if ($employee && ! in_array($employee->id, $notified)) {
            $this->send($employee, new DisciplinaryHearingScheduledNotification($hearing, 'employee'));
            $notified[] = $employee->id;
        }

        // Chairperson
        $chairperson = $hearing->case?->chairperson;
        if ($chairperson && ! in_array($chairperson->id, $notified)) {
            $this->send($chairperson, new DisciplinaryHearingScheduledNotification($hearing, 'chairperson'));
            $notified[] = $chairperson->id;
        }

        // Investigator
        $investigator = $hearing->case?->investigator;
        if ($investigator && ! in_array($investigator->id, $notified)) {
            $this->send($investigator, new DisciplinaryHearingScheduledNotification($hearing, 'investigator'));
            $notified[] = $investigator->id;
        }

        // HR admins (not already notified)
        $this->hrAdmins()->each(function (User $hr) use ($hearing, &$notified) {
            if (! in_array($hr->id, $notified)) {
                $this->send($hr, new DisciplinaryHearingScheduledNotification($hearing, 'hr_rep'));
                $notified[] = $hr->id;
            }
        });

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($hearing)
            ->withProperties([
                'hearing_number' => $hearing->hearing_number,
                'scheduled_at'   => $hearing->scheduled_at?->toDateTimeString(),
                'notified_count' => count($notified),
            ])
            ->event('notified_hearing_scheduled')
            ->log("Parties notified of hearing {$hearing->hearing_number}");
    }

    // ── Investigator ──────────────────────────────────────────────────────────

    /**
     * Notify an investigator when assigned to a case.
     */
    public function notifyInvestigatorAssigned(DisciplinaryCase $case): void
    {
        $case->loadMissing(['investigator.person', 'employee.person']);

        if (! $case->investigator) return;

        $this->send($case->investigator, new DisciplinaryCaseAssignedNotification($case, 'investigator'));

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($case)
            ->withProperties([
                'case_number'  => $case->case_number,
                'investigator' => $case->investigator->person?->full_name ?? $case->investigator->email,
            ])
            ->event('notified_investigator_assigned')
            ->log("Investigator notified for case {$case->case_number}");
    }

    /**
     * Notify an investigator when assigned to an incident.
     */
    public function notifyInvestigatorAssignedToIncident(DisciplinaryIncident $incident): void
    {
        $incident->loadMissing(['investigator.person']);

        if (! $incident->investigator) return;

        $this->send($incident->investigator, new DisciplinaryIncidentAssignedNotification($incident));

        activity('disciplinary')
            ->performedOn($incident)
            ->withProperties(['investigator_id' => $incident->investigator_id])
            ->event('notified_incident_investigator')
            ->log("Investigator notified for incident {$incident->reference_number}");
    }

    // ── Employee Notice ───────────────────────────────────────────────────────

    /**
     * Notify an employee that a disciplinary notice has been issued against them.
     */
    public function notifyEmployeeOfNotice(DisciplinaryCase $case): void
    {
        $case->loadMissing(['employee.person', 'category']);
        $employee = $case->employee;
        if (! $employee) return;

        $this->send($employee, new DisciplinaryEscalationNotification(
            escalationType: 'disciplinary_notice',
            subject: "Disciplinary Notice — {$case->case_number}",
            body:    "A disciplinary notice has been issued in relation to case **{$case->case_number}**: {$case->title}. Please review the case details and respond as required.",
            url:     route('disciplinary.cases.show', $case),
            caseNumber: $case->case_number,
            subjectId:  $case->id,
        ));

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($case)
            ->withProperties(['employee' => $case->employeeName(), 'case_number' => $case->case_number])
            ->event('notified_employee_notice')
            ->log("Employee {$case->employeeName()} notified of disciplinary notice {$case->case_number}");
    }

    // ── Dismissal Recommendation ──────────────────────────────────────────────

    /**
     * Notify executives and HR when a dismissal is recommended for a case.
     */
    public function notifyExecutivesOfDismissal(DisciplinaryCase $case): void
    {
        $case->loadMissing(['employee.person', 'outcome', 'investigator']);

        $this->hrAdmins()->each(function (User $exec) use ($case) {
            $this->send($exec, new DisciplinaryEscalationNotification(
                escalationType: 'dismissal_recommended',
                subject: "Dismissal Recommended — {$case->case_number}",
                body:    "A dismissal has been recommended for employee **{$case->employeeName()}** in case **{$case->case_number}**. Executive review may be required before proceeding.",
                url:     route('disciplinary.cases.show', $case),
                caseNumber: $case->case_number,
                subjectId:  $case->id,
            ));
        });

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($case)
            ->withProperties(['case_number' => $case->case_number, 'employee' => $case->employeeName()])
            ->event('notified_dismissal_recommended')
            ->log("Executives notified of dismissal recommendation for case {$case->case_number}");
    }

    // ── Corrective Action ─────────────────────────────────────────────────────

    public function notifyCorrectiveActionAssigned(DisciplinaryCorrectiveAction $action): void
    {
        $action->loadMissing(['employee.person', 'assignedBy.person', 'case']);

        $employee = $action->employee;
        if (! $employee) return;

        $assignedBy = $action->assignedBy?->person?->full_name ?? 'HR';
        $caseRef    = $action->case?->case_number ? " (Case {$action->case->case_number})" : '';

        $this->send($employee, new DisciplinaryEscalationNotification(
            escalationType: 'corrective_action_assigned',
            subject: "Corrective Action Assigned — {$action->title}",
            body:    "A corrective action **{$action->title}** has been assigned to you by **{$assignedBy}**{$caseRef}. "
                   . "Due date: **{$action->due_date?->format('d M Y')}**. Please review and acknowledge.",
            url:     route('disciplinary.corrective-actions.show', $action),
            caseNumber: $action->case?->case_number,
            subjectId:  $action->id,
        ));

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($action)
            ->withProperties([
                'action_number' => $action->action_number,
                'employee'      => $employee->person?->full_name ?? $employee->email,
                'due_date'      => $action->due_date?->format('d M Y'),
            ])
            ->event('notified_corrective_action_assigned')
            ->log("Employee notified of corrective action {$action->action_number}");
    }

    // ── Coaching Session ──────────────────────────────────────────────────────

    public function notifyCoachingSessionScheduled(DisciplinaryCoachingSession $session): void
    {
        $session->loadMissing(['employee.person', 'coach.person', 'correctiveAction', 'case']);

        $employee   = $session->employee;
        $coach      = $session->coach;
        $scheduledAt = $session->scheduled_at?->format('d M Y H:i') ?? '—';
        $sessionType = ucfirst(str_replace('_', ' ', $session->session_type ?? 'session'));

        if ($employee) {
            $this->send($employee, new DisciplinaryEscalationNotification(
                escalationType: 'coaching_session_scheduled',
                subject: "{$sessionType} Session Scheduled — {$scheduledAt}",
                body:    "A **{$sessionType}** session has been scheduled for you on **{$scheduledAt}**"
                       . ($session->venue ? " at **{$session->venue}**" : '') . '.',
                url:     $session->correctiveAction
                             ? route('disciplinary.corrective-actions.show', $session->corrective_action_id)
                             : route('disciplinary.corrective-actions.index'),
                caseNumber: $session->case?->case_number,
                subjectId:  $session->id,
            ));
        }

        if ($coach && $coach->id !== $employee?->id) {
            $employeeName = $employee?->person?->full_name ?? $employee?->email ?? 'employee';
            $this->send($coach, new DisciplinaryEscalationNotification(
                escalationType: 'coaching_session_assigned',
                subject: "{$sessionType} Session — {$employeeName} on {$scheduledAt}",
                body:    "You have been assigned as coach for a **{$sessionType}** session with **{$employeeName}** on **{$scheduledAt}**"
                       . ($session->venue ? " at **{$session->venue}**" : '') . '.',
                url:     $session->correctiveAction
                             ? route('disciplinary.corrective-actions.show', $session->corrective_action_id)
                             : route('disciplinary.corrective-actions.index'),
                caseNumber: $session->case?->case_number,
                subjectId:  $session->id,
            ));
        }

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($session)
            ->withProperties([
                'session_type' => $session->session_type,
                'scheduled_at' => $scheduledAt,
                'employee'     => $employee?->person?->full_name ?? $employee?->email ?? '—',
                'coach'        => $coach?->person?->full_name ?? $coach?->email ?? '—',
            ])
            ->event('notified_coaching_session_scheduled')
            ->log("Coaching session notifications sent for {$sessionType} on {$scheduledAt}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hrAdmins(): \Illuminate\Database\Eloquent\Collection
    {
        return User::role(self::DISC_ADMIN_ROLES)->with('person')->get();
    }

    private function send(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::warning("Disciplinary notification failed [{$user->id}]: " . $e->getMessage());
        }
    }
}
