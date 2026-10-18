<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaGoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaGoalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/PerformanceAppraisal/Goals/Index', [
            'goals'  => PaGoal::with(['cycle', 'owner', 'ownerUnit'])
                ->withCount('kpis')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($g) => [
                    'id'            => $g->id,
                    'title'         => $g->title,
                    'description'   => $g->description,
                    'level'         => $g->level,
                    'status'        => $g->status,
                    'target_value'  => $g->target_value,
                    'current_value' => $g->current_value,
                    'unit'          => $g->unit,
                    'progress_pct'  => $g->progressPct(),
                    'start_date'    => $g->start_date?->format('Y-m-d'),
                    'end_date'      => $g->end_date?->format('Y-m-d'),
                    'cycle'         => $g->cycle?->only('id', 'name'),
                    'owner_name'    => $this->ownerName($g),
                    'kpis_count'    => $g->kpis_count,
                    'cycle_id'       => $g->cycle_id,
                    'owner_id'       => $g->owner_id,
                    'owner_unit_id'  => $g->owner_unit_id,
                    'parent_goal_id' => $g->parent_goal_id,
                    'weight'         => $g->weight,
                ]),
            'cycles' => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'agents' => $this->agentList(),
            'units'  => CompanyUnit::where('active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'parentOptions' => PaGoal::orderBy('title')->get(['id', 'title', 'level'])
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'level' => $g->level]),
        ]);
    }

    /** OKR alignment tree — nested goals (org → dept → team) with rolled-up progress. */
    public function tree(Request $request): Response
    {
        $cycleId = $request->query('cycle_id');

        $goals = PaGoal::with(['owner', 'ownerUnit'])
            ->withCount('kpis')
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->get();

        $byParent = $goals->groupBy('parent_goal_id');

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent->get($parentId) ?? collect())->map(fn ($g) => [
                'id'           => $g->id,
                'title'        => $g->title,
                'level'        => $g->level,
                'status'       => $g->status,
                'progress_pct' => $g->progressPct(),
                'weight'       => $g->weight,
                'owner_name'   => $this->ownerName($g),
                'kpis_count'   => $g->kpis_count,
                'children'     => $build($g->id),
            ])->values();
        };

        return Inertia::render('Modules/PerformanceAppraisal/Goals/Tree', [
            'tree'   => $build(null),
            'cycles' => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'cycleId'=> $cycleId ? (int) $cycleId : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        PaGoal::create($data);

        return back()->with('success', 'Goal created.');
    }

    public function update(Request $request, PaGoal $goal): RedirectResponse
    {
        $goal->update($this->validated($request, isUpdate: true));

        return back()->with('success', 'Goal updated.');
    }

    public function destroy(PaGoal $goal): RedirectResponse
    {
        $goal->delete();

        return back()->with('success', 'Goal deleted.');
    }

    public function updateProgress(Request $request, PaGoal $goal): RedirectResponse
    {
        $data = $request->validate([
            'current_value' => ['required', 'numeric'],
            'status'        => ['sometimes', 'in:draft,active,achieved,missed,cancelled'],
        ]);

        $goal->update($data);

        return back()->with('success', 'Goal progress updated.');
    }

    private function validated(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'title'          => [$required, 'string', 'max:200'],
            'description'    => ['nullable', 'string'],
            'level'          => [$required, 'in:organizational,department,team'],
            'cycle_id'       => ['nullable', 'exists:pa_cycles,id'],
            'parent_goal_id' => ['nullable', 'exists:pa_goals,id'],
            'weight'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'owner_id'       => ['nullable', 'exists:users,id'],
            'owner_unit_id'  => ['nullable', 'exists:company_units,id'],
            'start_date'     => ['nullable', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:start_date'],
            'status'         => ['sometimes', 'in:draft,active,achieved,missed,cancelled'],
            'target_value'   => ['nullable', 'numeric'],
            'unit'           => ['nullable', 'string', 'max:50'],
            'current_value'  => ['nullable', 'numeric'],
        ]);
    }

    private function agentList(): \Illuminate\Support\Collection
    {
        return User::leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->select('users.id', DB::raw("COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name,' ',hr_people.surname)),''), users.email) AS name"))
            ->orderByRaw("COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name,' ',hr_people.surname)),''), users.email)")
            ->get()
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name]);
    }

    private function ownerName(PaGoal $goal): string
    {
        if ($goal->ownerUnit) return $goal->ownerUnit->name;
        if ($goal->owner) return $goal->owner->email;

        return 'Organisation';
    }
}
