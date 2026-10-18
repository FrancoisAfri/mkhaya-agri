<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaNineBoxPlacement;
use App\Modules\PerformanceAppraisal\Services\PaNineBoxService;
use App\Modules\PerformanceAppraisal\Trait\ReportsHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaNineBoxController extends Controller
{
    use ReportsHierarchy;

    public function __construct(private PaNineBoxService $service) {}

    public function index(Request $request): Response
    {
        $cycles  = PaCycle::orderByDesc('start_date')->get(['id', 'name', 'status']);
        $cycleId = (int) ($request->query('cycle_id') ?: $cycles->first()?->id);
        $allowed = $this->getAllowedEmployeeIds($request->user());

        $placements = PaNineBoxPlacement::where('cycle_id', $cycleId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('employee_id', $allowed))
            ->get();

        // Resolve display names/departments from hr_people in one query.
        $people = DB::table('hr_people')
            ->whereIn('user_id', $placements->pluck('employee_id'))
            ->get(['user_id', 'first_name', 'surname', 'position'])
            ->keyBy('user_id');

        $employees = $placements->map(function ($p) use ($people) {
            $person = $people->get($p->employee_id);
            return [
                'id'                => $p->id,
                'employee_id'       => $p->employee_id,
                'name'              => $person ? trim("{$person->first_name} {$person->surname}") : ('User #' . $p->employee_id),
                'position'          => $person->position ?? null,
                'box'               => $p->box,
                'performance_band'  => $p->performance_band,
                'potential_band'    => $p->potential_band,
                'performance_score' => $p->performance_score,
                'potential_score'   => $p->potential_score,
                'is_manual'         => $p->is_manual,
            ];
        })->values();

        return Inertia::render('Modules/PerformanceAppraisal/NineBox/Index', [
            'cycles'     => $cycles,
            'cycleId'    => $cycleId,
            'boxes'      => PaNineBoxService::BOXES,
            'employees'  => $employees,
        ]);
    }

    public function rebuild(Request $request): RedirectResponse
    {
        $data = $request->validate(['cycle_id' => 'required|exists:pa_cycles,id']);
        $n = $this->service->rebuild((int) $data['cycle_id']);

        return back()->with('success', "9-box grid rebuilt for {$n} employees.");
    }

    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cycle_id'    => 'required|exists:pa_cycles,id',
            'employee_id' => 'required|exists:users,id',
            'box'         => 'required|integer|min:1|max:9',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->service->move((int) $data['cycle_id'], (int) $data['employee_id'], (int) $data['box'], $data['notes'] ?? null);

        return back()->with('success', 'Placement updated.');
    }
}
