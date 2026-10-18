<?php

namespace App\Modules\HR\Payroll\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollForecastService
{
    private const GROWTH_RATE = 0.05; // 5% annual growth assumption

    public function forecastPayrollCost(int $months = 6): array
    {
        try {
            $baseline = $this->getBaselineMonthlyAvg(3);
            $forecast = [];

            for ($i = 1; $i <= $months; $i++) {
                $month    = now()->addMonths($i);
                $growth   = 1 + (self::GROWTH_RATE / 12 * $i);
                $forecast[] = [
                    'month'           => $month->format('Y-m'),
                    'label'           => $month->format('M Y'),
                    'forecasted_cost' => round($baseline * $growth, 2),
                    'growth_pct'      => round(($growth - 1) * 100, 2),
                ];
            }

            return $forecast;
        } catch (\Throwable $e) {
            Log::error('forecastPayrollCost error: ' . $e->getMessage());
            return [];
        }
    }

    public function forecastAnnualPayrollCost(): array
    {
        try {
            $baseline = $this->getBaselineMonthlyAvg(6);
            $forecast = [];

            for ($i = 1; $i <= 12; $i++) {
                $month    = now()->addMonths($i);
                $growth   = 1 + (self::GROWTH_RATE / 12 * $i);
                $forecast[] = [
                    'month'           => $month->format('Y-m'),
                    'label'           => $month->format('M Y'),
                    'forecasted_cost' => round($baseline * $growth, 2),
                    'growth_pct'      => round(($growth - 1) * 100, 2),
                ];
            }

            return $forecast;
        } catch (\Throwable $e) {
            Log::error('forecastAnnualPayrollCost error: ' . $e->getMessage());
            return [];
        }
    }

    public function forecastOvertimeGrowth(int $months = 6): array
    {
        try {
            $since = now()->subMonths(6)->startOfMonth();

            $overtimeByMonth = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('pr.status', 'released')
                ->where('pr.period_start', '>=', $since)
                ->where('pel.line_type', 'overtime')
                ->select(
                    DB::raw("TO_CHAR(pr.period_start, 'YYYY-MM') as month"),
                    DB::raw('SUM(pel.amount) as total_overtime'),
                )
                ->groupBy(DB::raw("TO_CHAR(pr.period_start, 'YYYY-MM')"))
                ->orderBy('month')
                ->get();

            if ($overtimeByMonth->isEmpty()) {
                return [];
            }

            $amounts = $overtimeByMonth->pluck('total_overtime')->map(fn ($v) => (float) $v)->values();
            $avg     = $amounts->avg();
            $trend   = $amounts->count() >= 2
                ? (($amounts->last() - $amounts->first()) / max($amounts->first(), 1)) / max($amounts->count() - 1, 1)
                : 0;

            $forecast = [];
            for ($i = 1; $i <= $months; $i++) {
                $month    = now()->addMonths($i);
                $forecast[] = [
                    'month'               => $month->format('Y-m'),
                    'label'               => $month->format('M Y'),
                    'forecasted_overtime' => round($avg * (1 + $trend * $i), 2),
                    'trend_pct'           => round($trend * $i * 100, 2),
                ];
            }

            return $forecast;
        } catch (\Throwable $e) {
            Log::error('forecastOvertimeGrowth error: ' . $e->getMessage());
            return [];
        }
    }

    public function forecastLeaveLiability(): array
    {
        try {
            $avgDailyRate = (float) DB::table('payslips as ps')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('pr.status', 'released')
                ->whereYear('pr.period_start', now()->year)
                ->selectRaw('AVG(basic_salary / 22.0) as avg_daily')
                ->value('avg_daily');

            $pendingLeaveDays = (int) DB::table('leave_requests')
                ->where('status', 'approved')
                ->whereYear('start_date', now()->year)
                ->selectRaw("SUM(CAST(end_date AS date) - CAST(start_date AS date) + 1) as total_days")
                ->value('total_days');

            $estimatedCost = round($avgDailyRate * $pendingLeaveDays, 2);

            return [
                'current_balance_days' => $pendingLeaveDays,
                'estimated_cost'       => $estimatedCost,
                'avg_daily_rate'       => round($avgDailyRate, 2),
            ];
        } catch (\Throwable $e) {
            Log::error('forecastLeaveLiability error: ' . $e->getMessage());
            return ['current_balance_days' => 0, 'estimated_cost' => 0, 'avg_daily_rate' => 0];
        }
    }

    public function forecastHeadcountCost(int $months = 6): array
    {
        try {
            $since = now()->subMonths(3)->startOfMonth();

            $byMonth = DB::table('payroll_runs as pr')
                ->join('payslips as ps', 'ps.payroll_run_id', '=', 'pr.id')
                ->where('pr.status', 'released')
                ->where('pr.period_start', '>=', $since)
                ->select(
                    DB::raw("TO_CHAR(pr.period_start, 'YYYY-MM') as month"),
                    DB::raw('COUNT(DISTINCT ps.employee_id) as headcount'),
                    DB::raw('SUM(ps.net_pay) as total_net'),
                )
                ->groupBy(DB::raw("TO_CHAR(pr.period_start, 'YYYY-MM')"))
                ->orderBy('month')
                ->get();

            $avgHeadcount = (float) ($byMonth->avg('headcount') ?: 0);
            $avgCost      = (float) ($byMonth->avg('total_net') ?: 0);
            $avgPerHead   = $avgHeadcount > 0 ? $avgCost / $avgHeadcount : 0;

            $forecast = [];
            for ($i = 1; $i <= $months; $i++) {
                $month              = now()->addMonths($i);
                $projectedHeadcount = (int) round($avgHeadcount * (1 + self::GROWTH_RATE / 12 * $i));
                $forecast[] = [
                    'month'          => $month->format('Y-m'),
                    'label'          => $month->format('M Y'),
                    'headcount'      => $projectedHeadcount,
                    'estimated_cost' => round($projectedHeadcount * $avgPerHead, 2),
                    'cost_per_head'  => round($avgPerHead, 2),
                ];
            }

            return $forecast;
        } catch (\Throwable $e) {
            Log::error('forecastHeadcountCost error: ' . $e->getMessage());
            return [];
        }
    }

    public function forecastBonusExposure(float $bonusPercentage = 0.08): array
    {
        try {
            $annualNet = (float) DB::table('payroll_runs')
                ->where('status', 'released')
                ->whereYear('period_start', now()->year)
                ->sum('total_net');

            $bonusPool = round($annualNet * $bonusPercentage, 2);

            $deptBreakdown = DB::table('payslips as ps')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('pr.status', 'released')
                ->whereYear('pr.period_start', now()->year)
                ->whereNotNull('hp.division_level_1')
                ->select('hp.division_level_1 as department', DB::raw('SUM(ps.net_pay) as dept_net'))
                ->groupBy('hp.division_level_1')
                ->orderByDesc('dept_net')
                ->get()
                ->map(fn ($row) => [
                    'department'      => $row->department,
                    'dept_net'        => (float) $row->dept_net,
                    'estimated_bonus' => round((float) $row->dept_net * $bonusPercentage, 2),
                ])
                ->toArray();

            return [
                'estimated_bonus_pool' => $bonusPool,
                'annual_net'           => $annualNet,
                'bonus_percentage'     => $bonusPercentage * 100,
                'per_department'       => $deptBreakdown,
            ];
        } catch (\Throwable $e) {
            Log::error('forecastBonusExposure error: ' . $e->getMessage());
            return ['estimated_bonus_pool' => 0, 'annual_net' => 0, 'bonus_percentage' => 0, 'per_department' => []];
        }
    }

    private function getBaselineMonthlyAvg(int $lookbackMonths): float
    {
        $since = now()->subMonths($lookbackMonths)->startOfMonth();

        return (float) DB::table('payroll_runs')
            ->where('status', 'released')
            ->where('period_start', '>=', $since)
            ->avg('total_net');
    }
}
