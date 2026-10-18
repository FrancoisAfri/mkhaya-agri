<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Services\DisciplinaryEscalationService;
use App\Modules\Disciplinary\Services\DisciplinaryNotificationService;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryNotificationsEscalationsController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(
        private readonly DisciplinaryEscalationService   $escalations,
        private readonly DisciplinaryNotificationService $notifications,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        // Recent escalation audit events
        $recentEscalations = DB::table('activity_log')
            ->where('log_name', 'disciplinary')
            ->whereIn('event', [
                'escalated_overdue_investigation',
                'escalated_overdue_hearing',
                'escalated_overdue_notice',
                'escalated_policy_violation',
                'escalation_run',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($row) => [
                'id'          => $row->id,
                'event'       => $row->event,
                'description' => $row->description,
                'properties'  => json_decode($row->properties ?? '{}', true),
                'created_at'  => \Illuminate\Support\Carbon::parse($row->created_at)->format('d M Y H:i'),
            ]);

        // Recent notification audit events
        $recentNotifications = DB::table('activity_log')
            ->where('log_name', 'disciplinary')
            ->whereIn('event', [
                'notified_new_case',
                'notified_hearing_scheduled',
                'notified_investigator_assigned',
                'notified_incident_investigator',
                'notified_employee_notice',
                'notified_dismissal_recommended',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($row) => [
                'id'          => $row->id,
                'event'       => $row->event,
                'description' => $row->description,
                'properties'  => json_decode($row->properties ?? '{}', true),
                'created_at'  => \Illuminate\Support\Carbon::parse($row->created_at)->format('d M Y H:i'),
            ]);

        // Summary counts
        $now = now();
        $stats = [
            'overdue_investigations' => DisciplinaryInvestigation::whereNotIn('status', ['completed', 'closed'])
                ->where('created_at', '<=', $now->copy()->subDays(14))->count(),
            'overdue_hearings'       => DisciplinaryHearing::whereNotIn('status', ['completed', 'cancelled'])
                ->where('scheduled_at', '<=', $now->copy()->subDays(7))->count(),
            'overdue_notices'        => DisciplinaryCase::whereNull('notice_issued_date')
                ->where('incident_date', '<=', $now->copy()->subDays(7))
                ->where(fn($q) => $q->whereDoesntHave('status')->orWhereHas('status', fn($q2) => $q2->where('is_closed', false)))
                ->count(),
            'escalations_today'      => DB::table('activity_log')
                ->where('log_name', 'disciplinary')
                ->where('event', 'like', 'escalated_%')
                ->whereDate('created_at', today())
                ->count(),
        ];

        return Inertia::render('Modules/Disciplinary/NotificationsEscalations/Index', [
            'recentEscalations'   => $recentEscalations,
            'recentNotifications' => $recentNotifications,
            'stats'               => $stats,
        ]);
    }

    // ── Manual trigger (for testing / on-demand) ──────────────────────────────

    public function runEscalations(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $summary = $this->escalations->runOverdueChecks();

        $total = $summary['overdue_investigations']
               + $summary['overdue_hearings']
               + $summary['overdue_notices']
               + $summary['policy_violations'];

        return back()->with('success', "Escalation check complete. {$total} escalation(s) triggered.");
    }

    // ── Manual notification triggers ──────────────────────────────────────────

    public function notifyCase(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->notifications->notifyNewCase($case);
        return back()->with('success', 'HR team notified of case.');
    }

    public function notifyHearing(DisciplinaryHearing $hearing): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->notifications->notifyHearingScheduled($hearing);
        return back()->with('success', 'All parties notified of hearing.');
    }

    public function notifyInvestigator(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->notifications->notifyInvestigatorAssigned($case);
        return back()->with('success', 'Investigator notified.');
    }

    public function notifyEmployee(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->notifications->notifyEmployeeOfNotice($case);
        return back()->with('success', 'Employee notified of disciplinary notice.');
    }

    public function notifyDismissal(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->notifications->notifyExecutivesOfDismissal($case);
        return back()->with('success', 'Executives notified of dismissal recommendation.');
    }
}
