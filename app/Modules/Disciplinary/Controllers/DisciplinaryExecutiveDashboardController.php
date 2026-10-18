<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorProfile;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation;
use App\Modules\Disciplinary\Models\DisciplinaryRiskScore;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryExecutiveDashboardController extends Controller
{
    use DisciplinaryAuthTrait;

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $now = now();

        // ── Widget 1: Open Cases ──────────────────────────────────────────────
        $openCasesCount = DisciplinaryCase::where(fn($q) =>
            $q->whereDoesntHave('status')->orWhereHas('status', fn($q2) => $q2->where('is_closed', false))
        )->count();

        $openCasesTrend = DB::table('disciplinary_cases')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $now->copy()->subMonths(6))
            ->selectRaw("TO_CHAR(created_at, 'Mon YYYY') as month, TO_CHAR(created_at, 'YYYY-MM') as month_key, count(*) as total")
            ->groupByRaw("TO_CHAR(created_at, 'Mon YYYY'), TO_CHAR(created_at, 'YYYY-MM')")
            ->orderBy('month_key')
            ->get();

        // ── Widget 2: Cases by Category ───────────────────────────────────────
        $casesByCategory = DB::table('disciplinary_cases as dc')
            ->leftJoin('disciplinary_categories as cat', 'cat.id', '=', 'dc.category_id')
            ->whereNull('dc.deleted_at')
            ->selectRaw("COALESCE(cat.name, 'Uncategorised') as category, cat.severity_level, count(*) as total")
            ->groupByRaw("COALESCE(cat.name, 'Uncategorised'), cat.severity_level")
            ->orderByDesc('total')
            ->get();

        // ── Widget 3: Repeat Offenders ────────────────────────────────────────
        $repeatOffenders = DisciplinaryRiskScore::with('employee.person')
            ->where('is_repeat_offender', true)
            ->orderByDesc('risk_score')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'employee_id'    => $r->employee_id,
                'employee_name'  => $this->resolveEmployeeName($r->employee),
                'risk_score'     => round($r->risk_score, 1),
                'risk_level'     => $r->risk_level,
                'risk_color'     => $r->riskColor(),
                'total_cases'    => $r->total_cases,
                'last_case_date' => $r->last_case_date?->format('d M Y'),
            ]);

        // ── Widget 4: Policy Violations ───────────────────────────────────────
        $policyViolations = [
            'total'      => DisciplinaryPolicyViolation::count(),
            'unresolved' => DisciplinaryPolicyViolation::where('is_resolved', false)->count(),
            'critical'   => DisciplinaryPolicyViolation::where('severity', 'critical')->count(),
            'by_type'    => DB::table('disciplinary_policy_violations')
                ->selectRaw("violation_type, count(*) as total")
                ->groupBy('violation_type')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
        ];

        // ── Widget 5: Suspended Employees ─────────────────────────────────────
        $suspendedEmployees = DisciplinarySuspension::with(['employee.person', 'case'])
            ->where('status', 'active')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn($s) => [
                'id'              => $s->id,
                'employee'        => $s->employee->person?->full_name ?? $s->employee->email,
                'suspension_type' => $s->suspension_type,
                'start_date'      => $s->start_date?->format('d M Y'),
                'end_date'        => $s->end_date?->format('d M Y'),
                'days_remaining'  => $s->end_date ? max(0, (int) now()->diffInDays($s->end_date, false)) : null,
                'case_number'     => $s->case?->case_number,
            ]);

        // ── Widget 6: Dismissal Risk ──────────────────────────────────────────
        $dismissalRisk = DisciplinaryRiskScore::with('employee.person')
            ->whereIn('risk_level', ['critical', 'high'])
            ->orderByDesc('risk_score')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'employee_id'   => $r->employee_id,
                'employee_name' => $this->resolveEmployeeName($r->employee),
                'risk_score'    => round($r->risk_score, 1),
                'risk_level'    => $r->risk_level,
                'total_cases'   => $r->total_cases,
                'is_repeat'     => $r->is_repeat_offender,
            ]);

        // ── Widget 7: Department Risk Scores ──────────────────────────────────
        $departmentRisk = DB::table('disciplinary_cases as dc')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'dc.employee_id')
            ->leftJoin('company_units as cu', function ($j) {
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
            })
            ->whereNull('dc.deleted_at')
            ->selectRaw("COALESCE(cu.name, 'No Department') as department, count(*) as total_cases,
                         count(DISTINCT dc.employee_id) as affected_employees")
            ->groupByRaw("COALESCE(cu.name, 'No Department')")
            ->orderByDesc('total_cases')
            ->limit(10)
            ->get();

        // ── Widget 8: Hearing Backlog ─────────────────────────────────────────
        $hearingBacklog = [
            'scheduled'    => DisciplinaryHearing::where('status', 'scheduled')->count(),
            'postponed'    => DisciplinaryHearing::where('status', 'postponed')->count(),
            'overdue'      => DisciplinaryHearing::whereNotIn('status', ['completed', 'cancelled'])
                ->where('scheduled_at', '<', $now)->count(),
            'this_week'    => DisciplinaryHearing::whereBetween('scheduled_at', [
                $now->copy()->startOfWeek(), $now->copy()->endOfWeek(),
            ])->whereNotIn('status', ['cancelled'])->count(),
            'upcoming_list'=> DisciplinaryHearing::with('case.employee.person')
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', $now)
                ->orderBy('scheduled_at')
                ->limit(5)
                ->get()
                ->map(fn($h) => [
                    'hearing_number' => $h->hearing_number,
                    'case_number'    => $h->case?->case_number,
                    'employee'       => $h->case?->employeeName(),
                    'scheduled_at'   => $h->scheduled_at?->format('d M Y H:i'),
                ]),
        ];

        // ── Widget 9: Investigation Delays ────────────────────────────────────
        $investigationDelays = DisciplinaryInvestigation::with(['case.employee.person', 'investigator.person'])
            ->whereNotIn('status', ['completed', 'closed'])
            ->where('created_at', '<=', $now->copy()->subDays(7))
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn($i) => [
                'id'           => $i->id,
                'case_number'  => $i->case?->case_number,
                'employee'     => $i->case?->employeeName(),
                'investigator' => $i->investigator?->person?->full_name ?? $i->investigator?->email ?? '—',
                'days_open'    => (int) $i->created_at->diffInDays($now),
                'status'       => $i->status,
            ]);

        // ── Widget 10: Appeal Trends ──────────────────────────────────────────
        $appealTrends = [
            'total'     => DisciplinaryAppeal::count(),
            'pending'   => DisciplinaryAppeal::whereNotIn('status', ['decided', 'withdrawn', 'lapsed'])->count(),
            'decided'   => DisciplinaryAppeal::where('status', 'decided')->count(),
            'by_month'  => DB::table('disciplinary_appeals')
                ->where('created_at', '>=', $now->copy()->subMonths(6))
                ->selectRaw("TO_CHAR(created_at, 'Mon YYYY') as month, TO_CHAR(created_at, 'YYYY-MM') as month_key, count(*) as total")
                ->groupByRaw("TO_CHAR(created_at, 'Mon YYYY'), TO_CHAR(created_at, 'YYYY-MM')")
                ->orderBy('month_key')
                ->get(),
        ];

        // ── Widget 11: Behavioral Risk Heatmap ───────────────────────────────
        $behavioralHeatmap = DisciplinaryRiskScore::with('employee.person')
            ->orderByDesc('risk_score')
            ->limit(50)
            ->get()
            ->map(fn($r) => [
                'employee_id'   => $r->employee_id,
                'employee_name' => $this->resolveEmployeeName($r->employee),
                'risk_score'    => round($r->risk_score, 1),
                'risk_level'    => $r->risk_level,
                'month'         => now()->format('Y-m'),
            ]);

        return Inertia::render('Modules/Disciplinary/ExecutiveDashboard/Index', [
            'openCasesCount'     => $openCasesCount,
            'openCasesTrend'     => $openCasesTrend,
            'casesByCategory'    => $casesByCategory,
            'repeatOffenders'    => $repeatOffenders,
            'policyViolations'   => $policyViolations,
            'suspendedEmployees' => $suspendedEmployees,
            'dismissalRisk'      => $dismissalRisk,
            'departmentRisk'     => $departmentRisk,
            'hearingBacklog'     => $hearingBacklog,
            'investigationDelays'=> $investigationDelays,
            'appealTrends'       => $appealTrends,
            'behavioralHeatmap'  => $behavioralHeatmap,
        ]);
    }

    private function resolveEmployeeName(?\App\Core\User\Models\User $user): string
    {
        if (! $user) return 'Unknown';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? 'Unknown');
    }
}
