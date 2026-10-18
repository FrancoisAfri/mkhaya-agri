<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\PayrollSimulation;
use App\Modules\HR\Payroll\Services\PayrollSimulationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollSimulationController extends Controller
{
    public function __construct(private PayrollSimulationService $simulationService) {}

    public function index(): Response
    {
        $simulations = PayrollSimulation::with('createdBy.person')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Modules/HR/Payroll/Simulations/Index', [
            'simulations' => $simulations,
        ]);
    }

    public function create(): Response
    {
        $runs = PayrollRun::select(['id', 'uuid', 'ref_number', 'name', 'period_start', 'period_end'])
            ->where('status', 'released')
            ->latest('period_start')
            ->limit(24)
            ->get();

        return Inertia::render('Modules/HR/Payroll/Simulations/Create', [
            'runs' => $runs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:200',
            'simulation_type'     => 'required|string|in:salary_increase,bonus,new_hire,overtime,allowance,union_increase,annual_budget',
            'notes'               => 'nullable|string|max:2000',
            'increase_mode'       => 'nullable|string|in:percentage,fixed_amount',
            'percentage'          => 'nullable|numeric|min:0|max:1000',
            'fixed_amount'        => 'nullable|numeric|min:0',
            'bonus_mode'          => 'nullable|string|in:fixed,pct_gross,pct_basic,multiple_salary',
            'amount_per_employee' => 'nullable|numeric|min:0',
            'percentage_of_gross' => 'nullable|numeric|min:0|max:1000',
            'percentage_of_basic' => 'nullable|numeric|min:0|max:1000',
            'bonus_multiple'      => 'nullable|numeric|min:0|max:24',
            'count'               => 'nullable|integer|min:1',
            'avg_salary'          => 'nullable|numeric|min:0',
            'months'              => 'nullable|integer|min:1|max:60',
            'hours_per_employee'  => 'nullable|numeric|min:0',
            'overtime_rate'       => 'nullable|numeric|min:0',
            'allowance_type'      => 'nullable|string|max:100',
            'amount'              => 'nullable|numeric|min:0',
            'year'                => 'nullable|integer|min:2000|max:2100',
            'buffer_percentage'   => 'nullable|numeric|min:0|max:100',
            'growth_percentage'   => 'nullable|numeric|min:0|max:100',
        ]);

        $simulation = match ($validated['simulation_type']) {
            'salary_increase' => $this->simulationService->simulateSalaryIncrease([
                'increase_mode' => $validated['increase_mode'] ?? 'percentage',
                'percentage'    => (float) ($validated['percentage'] ?? 5),
                'fixed_amount'  => (float) ($validated['fixed_amount'] ?? 0),
                'employee_ids'  => 'all',
                'effective_from'=> now()->startOfMonth()->toDateString(),
            ]),
            'bonus' => $this->simulationService->simulateBonusPayment([
                'bonus_mode'          => $validated['bonus_mode'] ?? 'fixed',
                'amount_per_employee' => (float) ($validated['amount_per_employee'] ?? 0),
                'percentage_of_gross' => (float) ($validated['percentage_of_gross'] ?? 0),
                'percentage_of_basic' => (float) ($validated['percentage_of_basic'] ?? 0),
                'bonus_multiple'      => (float) ($validated['bonus_multiple'] ?? 1),
            ]),
            'new_hire' => $this->simulationService->simulateNewHiringCost([
                'count'      => (int) ($validated['count'] ?? 1),
                'avg_salary' => (float) ($validated['avg_salary'] ?? 0),
                'months'     => (int) ($validated['months'] ?? 1),
            ]),
            'overtime' => $this->simulationService->simulateOvertimeCost([
                'hours_per_employee' => (float) ($validated['hours_per_employee'] ?? 0),
                'overtime_rate'      => (float) ($validated['overtime_rate'] ?? 0),
            ]),
            'allowance' => $this->simulationService->simulateAllowanceChange([
                'allowance_type' => $validated['allowance_type'] ?? 'allowance',
                'amount'         => (float) ($validated['amount'] ?? 0),
            ]),
            'union_increase' => $this->simulationService->simulateUnionIncrease([
                'percentage'              => (float) ($validated['percentage'] ?? 5),
                'affected_department_ids' => [],
            ]),
            'annual_budget' => $this->simulationService->simulateAnnualPayrollBudget([
                'year'              => (int) ($validated['year'] ?? now()->year),
                'buffer_percentage' => (float) ($validated['buffer_percentage'] ?? 15),
                'growth_percentage' => (float) ($validated['growth_percentage'] ?? 0),
            ]),
        };

        $simulation->update([
            'name'   => $validated['name'],
            'notes'  => $validated['notes'] ?? null,
            'status' => 'saved',
        ]);

        return redirect()->route('payroll.simulations.show', $simulation->uuid)
            ->with('success', "Simulation \"{$simulation->name}\" completed successfully.");
    }

    public function show(PayrollSimulation $simulation): Response
    {
        $simulation->load([
            'createdBy.person',
            'baseRun:id,uuid,ref_number,name,period_start,period_end,total_net',
            'items',
        ]);

        // Enrich items with employee names
        $employeeIds = $simulation->items->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $simulation->items->each(function ($item) use ($names) {
            $item->employee_name = $names[$item->employee_id]->full_name ?? null;
        });

        return Inertia::render('Modules/HR/Payroll/Simulations/Show', [
            'simulation' => $simulation,
        ]);
    }
}
