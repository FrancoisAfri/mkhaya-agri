<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorProfile;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryStatus;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryDashboardController extends Controller
{
    public function index(): Response
    {
        $now = now();

        $statusCounts = DisciplinaryStatus::withCount(['cases' => fn($q) => $q->whereNull('deleted_at')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'color'       => $s->color,
                'is_closed'   => $s->is_closed,
                'cases_count' => $s->cases_count,
            ]);

        $openCases = DisciplinaryCase::with(['employee.person', 'category', 'status'])
            ->where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'uuid'          => $c->uuid,
                'case_number'   => $c->case_number,
                'title'         => $c->title,
                'employee_name' => $c->employeeName(),
                'category'      => $c->category?->name,
                'status'        => $c->status?->name ?? 'No Status',
                'status_color'  => $c->status?->color ?? 'grey',
                'incident_date' => $c->incident_date?->format('d M Y'),
                'hearing_date'  => $c->hearing_date?->format('d M Y H:i'),
            ]);

        $upcomingHearings = DisciplinaryHearing::with(['case.employee.person'])
            ->where('scheduled_at', '>=', $now)
            ->where('scheduled_at', '<=', $now->copy()->addDays(14))
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get()
            ->map(fn($h) => [
                'id'            => $h->case_id,
                'uuid'          => $h->case?->uuid,
                'case_number'   => $h->case?->case_number,
                'title'         => $h->case?->title,
                'employee_name' => $h->case?->employeeName() ?? '—',
                'hearing_date'  => $h->scheduled_at?->format('d M Y H:i'),
                'status'        => $h->status,
                'status_color'  => $h->statusColor(),
            ]);

        $stats = [
            'total_open'   => DisciplinaryCase::where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })->count(),
            'total_closed' => DisciplinaryCase::whereHas('status', fn($q) => $q->where('is_closed', true))->count(),
            'hearings_this_week' => DisciplinaryHearing::whereBetween('scheduled_at', [
                $now->copy()->startOfWeek(), $now->copy()->endOfWeek(),
            ])->whereNotIn('status', ['cancelled'])->count(),
            'overdue_notices' => DisciplinaryCase::whereNull('notice_issued_date')
                ->where('incident_date', '<=', $now->copy()->subDays(7))
                ->where(function ($q) {
                    $q->whereDoesntHave('status')
                      ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
                })
                ->count(),
            'active_warnings'     => DisciplinaryWarning::where('is_expired', false)->count(),
            'active_suspensions'  => DisciplinarySuspension::where('status', 'active')->count(),
            'pending_appeals'     => DisciplinaryAppeal::whereNotIn('status', ['decided', 'withdrawn', 'lapsed'])->count(),
            'pending_actions'     => DisciplinaryCorrectiveAction::whereIn('status', ['pending', 'in_progress'])->count(),
            'high_risk_employees' => DisciplinaryBehaviorProfile::highRisk()->count(),
        ];

        $categoryCounts = DB::table('disciplinary_cases as dc')
            ->join('disciplinary_categories as cat', 'cat.id', '=', 'dc.category_id')
            ->whereNull('dc.deleted_at')
            ->selectRaw('cat.name, cat.severity_level, count(*) as total')
            ->groupBy('cat.id', 'cat.name', 'cat.severity_level')
            ->orderByDesc('total')
            ->get();

        $moduleLinks = [
            ['label' => 'Warnings',               'route' => 'disciplinary.progressive-discipline.warnings.index',     'icon' => 'mdi-alert',              'color' => 'warning',     'stat_key' => 'active_warnings'],
            ['label' => 'Suspensions',             'route' => 'disciplinary.progressive-discipline.suspensions.index',  'icon' => 'mdi-account-off-outline', 'color' => 'error',       'stat_key' => 'active_suspensions'],
            ['label' => 'Appeals',                 'route' => 'disciplinary.appeals.index',                             'icon' => 'mdi-scale-balance',       'color' => 'deep-purple', 'stat_key' => 'pending_appeals'],
            ['label' => 'Corrective Actions',      'route' => 'disciplinary.corrective-actions.index',                  'icon' => 'mdi-clipboard-check',     'color' => 'teal',        'stat_key' => 'pending_actions'],
            ['label' => 'Workforce Intelligence',  'route' => 'disciplinary.workforce-intelligence.index',              'icon' => 'mdi-account-group',       'color' => 'indigo',      'stat_key' => 'high_risk_employees'],
            ['label' => 'Risk Intelligence',       'route' => 'disciplinary.risk-intelligence.index',                   'icon' => 'mdi-shield-alert',        'color' => 'red',         'stat_key' => null],
            ['label' => 'Escalations',             'route' => 'disciplinary.progressive-discipline.escalations.index',  'icon' => 'mdi-arrow-up-circle',     'color' => 'orange',      'stat_key' => null],
            ['label' => 'Policy Enforcement',      'route' => 'disciplinary.policy-enforcement.index',                  'icon' => 'mdi-shield-check',        'color' => 'blue-grey',   'stat_key' => null],
            // Module 14-16
            ['label' => 'Notifications & Escalations', 'route' => 'disciplinary.notifications-escalations.index', 'icon' => 'mdi-bell-alert',      'color' => 'deep-orange', 'stat_key' => null],
            ['label' => 'Executive Dashboard',         'route' => 'disciplinary.executive-dashboard',             'icon' => 'mdi-view-dashboard',  'color' => 'primary',     'stat_key' => null],
            ['label' => 'AI Workforce Intelligence',   'route' => 'disciplinary.executive-ai.index',              'icon' => 'mdi-robot',           'color' => 'deep-purple', 'stat_key' => null],
        ];

        return Inertia::render('Modules/Disciplinary/Dashboard/Index', [
            'stats'            => $stats,
            'statusCounts'     => $statusCounts,
            'openCases'        => $openCases,
            'upcomingHearings' => $upcomingHearings,
            'categoryCounts'   => $categoryCounts,
            'moduleLinks'      => $moduleLinks,
        ]);
    }
}
