<?php

namespace App\Modules\Policies\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicySimulation;
use App\Modules\Policies\Services\PolicySimulationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicySimulationController extends Controller
{
    public function __construct(
        private readonly PolicySimulationService $simulationService
    ) {}

    public function index(): Response
    {
        $policies    = Policy::select('id', 'name', 'status', 'department_id')
            ->orderBy('name')
            ->get();

        $departments = CompanyUnit::select('id', 'name', 'type')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $simulations = PolicySimulation::with(['policy:id,name', 'runner:id,email', 'runner.person:id,user_id,first_name,surname'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn(PolicySimulation $s) => [
                'id'                   => $s->id,
                'policy_name'          => optional($s->policy)->name ?? '—',
                'run_by'               => optional($s->runner)->name ?? '—',
                'status'               => $s->status,
                'projected_violations' => $s->projected_violations,
                'impacted_count'       => count($s->impacted_departments ?? []),
                'date_from'            => $s->simulation_config['date_from'] ?? null,
                'date_to'              => $s->simulation_config['date_to'] ?? null,
                'completed_at'         => $s->completed_at?->toDateTimeString(),
                'created_at'           => $s->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Policies/Simulation/Index', [
            'policies'    => $policies,
            'departments' => $departments,
            'simulations' => $simulations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'policy_id'      => 'required|integer|exists:policy,id',
            'date_from'      => 'required|date|before_or_equal:date_to',
            'date_to'        => 'required|date',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:company_units,id',
        ]);

        $simulation = PolicySimulation::create([
            'policy_id'         => $validated['policy_id'],
            'run_by'            => $request->user()->id,
            'simulation_config' => [
                'date_from'      => $validated['date_from'],
                'date_to'        => $validated['date_to'],
                'department_ids' => $validated['department_ids'] ?? [],
            ],
            'status' => 'queued',
        ]);

        $this->simulationService->run($simulation);

        return redirect()->route('policy-simulation.show', $simulation)
            ->with('success', 'Simulation completed successfully.');
    }

    public function show(PolicySimulation $simulation): Response
    {
        $simulation->load(['policy:id,name,description,status', 'runner:id,email', 'runner.person:id,user_id,first_name,surname']);

        $policies    = Policy::select('id', 'name')->orderBy('name')->get();
        $departments = CompanyUnit::select('id', 'name', 'type')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $simulations = PolicySimulation::with(['policy:id,name', 'runner:id,email', 'runner.person:id,user_id,first_name,surname'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn(PolicySimulation $s) => [
                'id'                   => $s->id,
                'policy_name'          => optional($s->policy)->name ?? '—',
                'run_by'               => optional($s->runner)->name ?? '—',
                'status'               => $s->status,
                'projected_violations' => $s->projected_violations,
                'impacted_count'       => count($s->impacted_departments ?? []),
                'date_from'            => $s->simulation_config['date_from'] ?? null,
                'date_to'              => $s->simulation_config['date_to'] ?? null,
                'completed_at'         => $s->completed_at?->toDateTimeString(),
                'created_at'           => $s->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Policies/Simulation/Index', [
            'policies'    => $policies,
            'departments' => $departments,
            'simulations' => $simulations,
            'activeResult' => [
                'id'                   => $simulation->id,
                'policy_id'            => $simulation->policy_id,
                'policy_name'          => optional($simulation->policy)->name ?? '—',
                'run_by'               => optional($simulation->runner)->name ?? '—',
                'status'               => $simulation->status,
                'config'               => $simulation->simulation_config,
                'results'              => $simulation->results,
                'projected_violations' => $simulation->projected_violations,
                'impacted_departments' => $simulation->impacted_departments,
                'completed_at'         => $simulation->completed_at?->toDateTimeString(),
            ],
        ]);
    }
}
