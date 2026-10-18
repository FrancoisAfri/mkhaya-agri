<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Services\PayrollForecastService;
use Inertia\Inertia;
use Inertia\Response;

class PayrollForecastController extends Controller
{
    public function __construct(private PayrollForecastService $forecastService) {}

    public function index(): Response
    {
        $months   = max(1, min(24, (int) request()->get('months', 6)));
        $bonusPct = max(1.0, min(30.0, (float) request()->get('bonus_pct', 8.0)));

        $annual   = $this->forecastService->forecastAnnualPayrollCost();
        $overtime = $this->forecastService->forecastOvertimeGrowth($months);
        $leave    = $this->forecastService->forecastLeaveLiability();
        $headcount = $this->forecastService->forecastHeadcountCost($months);
        $bonus    = $this->forecastService->forecastBonusExposure($bonusPct / 100);

        // Derive annual totals for summary cards
        $annualTotal    = collect($annual)->sum('forecasted_cost');
        $peakMonthly    = collect($annual)->max('forecasted_cost') ?? 0;
        $overtimeTotal  = collect($overtime)->sum('forecasted_overtime');

        return Inertia::render('Modules/HR/Payroll/Forecasting/Index', [
            'annual'        => $annual,
            'overtime'      => $overtime,
            'leave'         => $leave,
            'headcount'     => $headcount,
            'bonus'         => $bonus,
            'summary'       => [
                'annual_total'    => round($annualTotal, 2),
                'peak_monthly'    => round((float) $peakMonthly, 2),
                'overtime_total'  => round($overtimeTotal, 2),
                'leave_liability' => $leave['estimated_cost'] ?? 0,
                'bonus_pool'      => $bonus['estimated_bonus_pool'] ?? 0,
            ],
            'params'        => ['months' => $months, 'bonus_pct' => $bonusPct],
        ]);
    }
}
