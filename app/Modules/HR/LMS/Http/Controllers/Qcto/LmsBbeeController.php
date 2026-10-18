<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\Qcto\LmsBbeeTrainingSpend;
use App\Modules\HR\LMS\Models\Qcto\LmsPayrollSnapshot;
use App\Modules\HR\LMS\Services\Qcto\LmsBbeeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsBbeeController extends Controller
{
    public function __construct(private readonly LmsBbeeService $service) {}

    public function dashboard(Request $request)
    {
        $year     = $request->integer('year', now()->year);
        $snapshot = LmsPayrollSnapshot::where('plan_year', $year)->first();
        $payroll  = $snapshot ? (float) $snapshot->annual_leviable_payroll : 0.0;

        $scorecard = $this->service->calculateScorecard($year, $payroll);

        $years = LmsBbeeTrainingSpend::selectRaw('DISTINCT spend_year')
            ->orderByDesc('spend_year')
            ->pluck('spend_year');

        $byType = LmsBbeeTrainingSpend::where('spend_year', $year)
            ->selectRaw('training_type, SUM(cost) as total, COUNT(*) as count')
            ->groupBy('training_type')
            ->get();

        $monthly = LmsBbeeTrainingSpend::where('spend_year', $year)
            ->selectRaw("to_char(training_date, 'Mon') as month, EXTRACT(MONTH FROM training_date) as month_num, SUM(cost) as total")
            ->groupByRaw("month, month_num")
            ->orderBy('month_num')
            ->get();

        return Inertia::render('Modules/LMS/Admin/BbbeeDashboard', [
            'scorecard' => $scorecard,
            'byType'    => $byType,
            'monthly'   => $monthly,
            'years'     => $years,
            'year'      => $year,
            'snapshot'  => $snapshot,
        ]);
    }

    public function updatePayroll(Request $request)
    {
        $data = $request->validate([
            'plan_year'                => 'required|integer|min:2000|max:2100',
            'annual_leviable_payroll'  => 'required|numeric|min:0',
            'levy_percentage'          => 'nullable|numeric|min:0|max:1',
            'notes'                    => 'nullable|string|max:500',
        ]);

        $levyPct    = (float) ($data['levy_percentage'] ?? 0.01);
        $levyAmount = (float) $data['annual_leviable_payroll'] * $levyPct;

        LmsPayrollSnapshot::updateOrCreate(
            ['plan_year' => $data['plan_year']],
            [
                'annual_leviable_payroll' => $data['annual_leviable_payroll'],
                'levy_percentage'         => $levyPct,
                'levy_amount'             => $levyAmount,
                'notes'                   => $data['notes'] ?? null,
                'captured_by'             => auth()->id(),
            ]
        );

        return back()->with('success', 'Payroll snapshot saved for ' . $data['plan_year'] . '.');
    }
}
