<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorProfile;
use App\Modules\Disciplinary\Services\WorkforceIntelligenceService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryWorkforceIntelligenceController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(private readonly WorkforceIntelligenceService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $periodEnd   = $request->get('period_end', now()->toDateString());
        $periodStart = $request->get('period_start', now()->subMonths(3)->toDateString());

        $profiles = DisciplinaryBehaviorProfile::with('employee.person')
            ->where('period_end', $periodEnd)
            ->when($request->risk_level, fn($q, $l) => $q->where('risk_level', $l))
            ->orderByDesc('overall_risk_score')
            ->paginate(25)
            ->through(fn($p) => $this->mapProfile($p));

        $stats = [
            'high_risk'   => DisciplinaryBehaviorProfile::where('period_end', $periodEnd)->highRisk()->count(),
            'total'       => DisciplinaryBehaviorProfile::where('period_end', $periodEnd)->count(),
            'critical'    => DisciplinaryBehaviorProfile::where('period_end', $periodEnd)->where('risk_level', 'critical')->count(),
            'medium'      => DisciplinaryBehaviorProfile::where('period_end', $periodEnd)->where('risk_level', 'medium')->count(),
        ];

        $employees = User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);

        return Inertia::render('Modules/Disciplinary/WorkforceIntelligence/Index', [
            'profiles'     => $profiles,
            'stats'        => $stats,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'filters'      => $request->only('period_start', 'period_end', 'risk_level'),
            'employees'    => $employees,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'  => 'required|exists:users,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start',
        ]);

        $profile = $this->service->generateBehaviorProfile(
            $data['employee_id'],
            $data['period_start'],
            $data['period_end'],
        );

        return redirect()->back()->with(
            'success',
            "Behavior profile generated — risk level: {$profile->risk_level}"
        );
    }

    public function showEmployee(int $employeeId, Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $profiles = DisciplinaryBehaviorProfile::where('employee_id', $employeeId)
            ->with('employee.person')
            ->orderByDesc('period_end')
            ->get()
            ->map(fn($p) => $this->mapProfile($p));

        return Inertia::render('Modules/Disciplinary/WorkforceIntelligence/EmployeeProfile', [
            'employee_id' => $employeeId,
            'profiles'    => $profiles,
        ]);
    }

    private function mapProfile(DisciplinaryBehaviorProfile $p): array
    {
        return [
            'id'                    => $p->id,
            'employee_id'           => $p->employee_id,
            'employee'              => $p->employee->person?->full_name ?? $p->employee->email,
            'period_start'          => $p->period_start?->format('d M Y'),
            'period_end'            => $p->period_end?->format('d M Y'),
            'overall_risk_score'    => $p->overall_risk_score,
            'risk_level'            => $p->risk_level,
            'attendance_score'      => $p->attendance_score,
            'performance_score'     => $p->performance_score,
            'disciplinary_score'    => $p->disciplinary_score,
            'compliance_score'      => $p->compliance_score,
            'active_warnings'       => $p->active_warnings,
            'total_cases_ytd'       => $p->total_cases_ytd,
            'repeated_offences'     => $p->repeated_offences,
            'late_arrivals'         => $p->late_arrivals,
            'unexcused_absences'    => $p->unexcused_absences,
            'risk_factors'          => $p->risk_factors,
            'ai_narrative'          => $p->ai_narrative,
            'is_ai_generated'       => $p->is_ai_generated,
            'generated_at'          => $p->updated_at?->format('d M Y H:i'),
        ];
    }
}
