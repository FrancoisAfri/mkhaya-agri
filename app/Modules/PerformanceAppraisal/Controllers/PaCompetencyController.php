<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCompetency;
use App\Modules\PerformanceAppraisal\Models\PaCompetencyAnchor;
use App\Modules\PerformanceAppraisal\Models\PaRoleCompetency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaCompetencyController extends Controller
{
    public function index(): Response
    {
        $competencies = PaCompetency::with(['anchors', 'roleLinks'])
            ->orderBy('category')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'category'    => $c->category,
                'description' => $c->description,
                'max_level'   => $c->max_level,
                'is_active'   => $c->is_active,
                'anchors'     => $c->anchors->map(fn ($a) => [
                    'id' => $a->id, 'level' => $a->level, 'label' => $a->label, 'behavior' => $a->behavior,
                ])->values(),
                'role_count'  => $c->roleLinks->count(),
            ]);

        $positions = DB::table('hr_positions')->orderBy('name')->get(['id', 'name']);

        $roleMap = PaRoleCompetency::with('competency')->get()
            ->groupBy('position_id')
            ->map(fn ($links) => $links->map(fn ($l) => [
                'id'             => $l->id,
                'competency_id'  => $l->competency_id,
                'competency'     => $l->competency?->name,
                'expected_level' => $l->expected_level,
                'is_critical'    => $l->is_critical,
            ])->values());

        return Inertia::render('Modules/PerformanceAppraisal/Competencies/Index', [
            'competencies' => $competencies,
            'positions'    => $positions,
            'roleMap'      => $roleMap,
            'categories'   => ['core', 'leadership', 'technical', 'functional'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'category'    => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'max_level'   => 'nullable|integer|min:2|max:10',
        ]);

        PaCompetency::create($data + ['max_level' => $data['max_level'] ?? 5, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Competency created.');
    }

    public function update(Request $request, PaCompetency $competency): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:150',
            'category'    => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'max_level'   => 'nullable|integer|min:2|max:10',
            'is_active'   => 'boolean',
        ]);

        $competency->update($data);

        return back()->with('success', 'Competency updated.');
    }

    public function destroy(PaCompetency $competency): RedirectResponse
    {
        $competency->delete();

        return back()->with('success', 'Competency deleted.');
    }

    public function saveAnchor(Request $request, PaCompetency $competency): RedirectResponse
    {
        $data = $request->validate([
            'level'    => 'required|integer|min:1|max:' . $competency->max_level,
            'label'    => 'nullable|string|max:100',
            'behavior' => 'nullable|string|max:1000',
        ]);

        PaCompetencyAnchor::updateOrCreate(
            ['competency_id' => $competency->id, 'level' => $data['level']],
            ['label' => $data['label'] ?? null, 'behavior' => $data['behavior'] ?? null]
        );

        return back()->with('success', 'Behavioural anchor saved.');
    }

    public function mapRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'competency_id'  => 'required|exists:pa_competencies,id',
            'position_id'    => 'required|exists:hr_positions,id',
            'expected_level' => 'required|integer|min:1|max:10',
            'is_critical'    => 'boolean',
        ]);

        PaRoleCompetency::updateOrCreate(
            ['competency_id' => $data['competency_id'], 'position_id' => $data['position_id']],
            ['expected_level' => $data['expected_level'], 'is_critical' => $data['is_critical'] ?? false]
        );

        return back()->with('success', 'Competency mapped to role.');
    }

    public function unmapRole(PaRoleCompetency $roleCompetency): RedirectResponse
    {
        $roleCompetency->delete();

        return back()->with('success', 'Role mapping removed.');
    }
}
