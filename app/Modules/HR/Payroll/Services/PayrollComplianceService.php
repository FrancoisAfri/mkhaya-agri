<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollComplianceService
{
    // These were constants — NMW at R28.79/hr, the ten-hour overtime ceiling, the 1.5 multiplier.
    // They change every March, so every March somebody had to edit this class and deploy it, on the
    // very code that decides whether a payroll is flagged as underpaying. They are rows now, read by
    // the date of the run, which also means a reprint of an old period is measured against the rate
    // that applied then. See App\Modules\HR\Payroll\Support\LabourRates.
    private const PAYE_MONTHLY_THRESHOLD = 7_979.17;

    private const COMPLIANCE_TYPES = [
        'overtime_law_violation',
        'minimum_wage_violation',
        'leave_compliance_issue',
        'contract_payroll_mismatch',
        'tax_compliance_issue',
        'overtime_rate_violation',
    ];

    // ── Detection methods ─────────────────────────────────────────────────────

    public function detectOvertimeLawViolations(PayrollRun $run): array
    {
        try {
            $periodWeeks = max(1, (int) round($run->period_start->diffInDays($run->period_end) / 7));
            // Read for the period being checked, not for today — a run reprocessed next year must
            // still be judged against the ceiling that applied when it was worked.
            $maxOvertime = app(LabourRates::class)->maxOvertimeHoursPerWeek($run->period_end);

            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'overtime')
                ->select('ps.employee_id', DB::raw('SUM(pel.quantity) as total_overtime_hours'))
                ->groupBy('ps.employee_id')
                ->get();

            $anomalies = [];
            foreach ($rows as $row) {
                $weeklyOt = (float) $row->total_overtime_hours / $periodWeeks;
                if ($weeklyOt > $maxOvertime) {
                    $anomalies[] = [
                        'employee_id' => $row->employee_id,
                        'type'        => 'overtime_law_violation',
                        'description' => "Employee #{$row->employee_id} averages " . round($weeklyOt, 1) . " OT hrs/week, exceeding the BCEA limit of " . $maxOvertime . " hrs/week.",
                        'severity'    => 'high',
                        'metadata'    => ['weekly_overtime_hours' => round($weeklyOt, 2), 'total_hours' => (float) $row->total_overtime_hours],
                    ];
                }
            }

            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectOvertimeLawViolations error: ' . $e->getMessage());
            return [];
        }
    }

    public function detectMinimumWageViolations(PayrollRun $run): array
    {
        try {
            $minimumWage = app(LabourRates::class)->minimumMonthlyWage($run->period_end);

            // No determination loaded for that date is not the same as everybody being underpaid,
            // and flagging the entire payroll would be worse than saying nothing.
            if ($minimumWage <= 0) {
                return [];
            }

            $rows = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('basic_salary', '>', 0)
                ->where('basic_salary', '<', $minimumWage)
                ->select('employee_id', 'basic_salary')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'type'        => 'minimum_wage_violation',
                'description' => "Employee #{$row->employee_id} basic salary R" . number_format((float) $row->basic_salary, 2) . " is below the National Minimum Wage of R" . number_format($minimumWage, 2) . "/month.",
                'severity'    => 'critical',
                'metadata'    => ['basic_salary' => (float) $row->basic_salary, 'nmw_monthly' => $minimumWage],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectMinimumWageViolations error: ' . $e->getMessage());
            return [];
        }
    }

    public function detectLeaveComplianceIssues(PayrollRun $run): array
    {
        try {
            $employeeIds = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->pluck('employee_id');

            // Bridge: payslips.employee_id (users.id) → hr_people.user_id → hr_people.id → leave_requests.employee_id
            $hrPeopleMap = DB::table('hr_people')
                ->whereIn('user_id', $employeeIds)
                ->select('id', 'user_id')
                ->get()->keyBy('id'); // keyed by hr_people.id

            $hrPeopleIds = $hrPeopleMap->keys();

            $since = now()->subMonths(12);

            $hrPeopleWithLeave = DB::table('leave_requests')
                ->whereIn('employee_id', $hrPeopleIds)
                ->where('status', 'approved')
                ->where('start_date', '>=', $since)
                ->pluck('employee_id')
                ->unique();

            // Map hr_people.id back to users.id
            $userIdsWithLeave = $hrPeopleMap->only($hrPeopleWithLeave->toArray())
                ->pluck('user_id');

            $noLeave = $employeeIds->diff($userIdsWithLeave);

            return $noLeave->map(fn ($id) => [
                'employee_id' => $id,
                'type'        => 'leave_compliance_issue',
                'description' => "Employee #{$id} has not taken any approved leave in the last 12 months — potential BCEA annual leave non-compliance.",
                'severity'    => 'medium',
                'metadata'    => ['months_checked' => 12],
            ])->values()->toArray();
        } catch (\Throwable $e) {
            Log::error('detectLeaveComplianceIssues error: ' . $e->getMessage());
            return [];
        }
    }

    public function detectContractPayrollMismatch(PayrollRun $run): array
    {
        try {
            $currentPayslips = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->select('employee_id', 'basic_salary')
                ->get();

            $since = $run->period_start->subMonths(6);

            $historicalAvg = DB::table('payslips as ps')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('pr.status', 'released')
                ->where('pr.period_start', '>=', $since)
                ->where('pr.period_start', '<', $run->period_start)
                ->select('ps.employee_id', DB::raw('AVG(ps.basic_salary) as avg_salary'))
                ->groupBy('ps.employee_id')
                ->get()->keyBy('employee_id');

            $anomalies = [];
            foreach ($currentPayslips as $row) {
                $hist = (float) ($historicalAvg[$row->employee_id]->avg_salary ?? 0);
                if ($hist <= 0) continue;

                $curr    = (float) $row->basic_salary;
                $pctDiff = abs($curr - $hist) / $hist;

                if ($pctDiff < 0.15) continue;

                $hasChangeLog = DB::table('payroll_change_logs')
                    ->where('employee_id', $row->employee_id)
                    ->where('run_id', $run->id)
                    ->where('field_changed', 'like', '%basic%')
                    ->exists();

                if (!$hasChangeLog) {
                    $anomalies[] = [
                        'employee_id' => $row->employee_id,
                        'type'        => 'contract_payroll_mismatch',
                        'description' => "Employee #{$row->employee_id} basic salary R" . number_format($curr, 2) . " differs by " . round($pctDiff * 100, 1) . "% from 6-month average R" . number_format($hist, 2) . " with no change log recorded.",
                        'severity'    => $pctDiff >= 0.30 ? 'high' : 'medium',
                        'metadata'    => ['current_salary' => $curr, 'historical_avg' => round($hist, 2), 'pct_diff' => round($pctDiff * 100, 1)],
                    ];
                }
            }

            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectContractPayrollMismatch error: ' . $e->getMessage());
            return [];
        }
    }

    public function detectTaxComplianceIssues(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('gross_pay', '>', self::PAYE_MONTHLY_THRESHOLD)
                ->where(function ($q) {
                    $q->whereNull('paye')->orWhere('paye', '<=', 0);
                })
                ->select('employee_id', 'gross_pay', 'paye')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'type'        => 'tax_compliance_issue',
                'description' => "Employee #{$row->employee_id} gross pay R" . number_format((float) $row->gross_pay, 2) . " exceeds PAYE threshold R" . number_format(self::PAYE_MONTHLY_THRESHOLD, 2) . "/month but no PAYE was deducted.",
                'severity'    => 'critical',
                'metadata'    => ['gross_pay' => (float) $row->gross_pay, 'paye' => (float) $row->paye, 'threshold' => self::PAYE_MONTHLY_THRESHOLD],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectTaxComplianceIssues error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Overtime paid below the BCEA multiplier.
     *
     * This was called `detectBargainingCouncilViolations`, which it never was — it does not look at
     * a bargaining council, and the name meant an employer could reasonably believe council
     * compliance was covered when no levy was even calculated. BargainingCouncilService does that.
     */
    public function detectOvertimeRateViolations(PayrollRun $run): array
    {
        try {
            // Detect employees whose effective overtime rate is below BCEA minimum 1.5× hourly rate
            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'overtime')
                ->whereRaw('pel.quantity > 0')
                ->select(
                    'ps.employee_id',
                    'ps.basic_salary',
                    DB::raw('SUM(pel.amount) as total_ot_pay'),
                    DB::raw('SUM(pel.quantity) as total_ot_hrs'),
                )
                ->groupBy('ps.employee_id', 'ps.basic_salary')
                ->get();

            $rates = app(LabourRates::class);
            $standardHours = $rates->standardMonthlyHours($run->period_end);
            $otMultiplier = $rates->overtimeMultiplier($run->period_end);

            $anomalies = [];
            foreach ($rows as $row) {
                $hourlyRate   = $standardHours > 0 ? (float) $row->basic_salary / $standardHours : 0.0;
                $minOtRate    = $hourlyRate * $otMultiplier;
                $totalHrs     = (float) $row->total_ot_hrs;
                $actualOtRate = $totalHrs > 0 ? (float) $row->total_ot_pay / $totalHrs : 0;

                if ($actualOtRate > 0 && $actualOtRate < $minOtRate) {
                    $anomalies[] = [
                        'employee_id' => $row->employee_id,
                        'type'        => 'overtime_rate_violation',
                        'description' => "Employee #{$row->employee_id} overtime rate R" . number_format($actualOtRate, 2) . "/hr is below the BCEA minimum of 1.5× (R" . number_format($minOtRate, 2) . "/hr).",
                        'severity'    => 'high',
                        'metadata'    => [
                            'actual_ot_rate' => round($actualOtRate, 2),
                            'min_ot_rate'    => round($minOtRate, 2),
                            'total_ot_hours' => $totalHrs,
                        ],
                    ];
                }
            }

            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectOvertimeRateViolations error: ' . $e->getMessage());
            return [];
        }
    }

    // ── Orchestration & persistence ───────────────────────────────────────────

    public function runAllComplianceChecks(PayrollRun $run): array
    {
        $checks = [
            $this->detectOvertimeLawViolations($run),      // payroll-native (OT earnings lines)
            $this->detectMinimumWageViolations($run),      // payroll-native
            $this->detectContractPayrollMismatch($run),    // payroll-native
            $this->detectTaxComplianceIssues($run),        // payroll-native (PAYE)
            $this->detectOvertimeRateViolations($run),// payroll-native
        ];

        // Leave-compliance reads the Leave module's data; only run it when the tenant
        // actually has that module, otherwise every employee is falsely flagged.
        $leaveInstalled = DB::table('security_modules')
            ->where('code_name', 'leave_management')->where('active', 1)->exists();
        if ($leaveInstalled && app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentAllowsModule('leave_management')) {
            $checks[] = $this->detectLeaveComplianceIssues($run);
        }

        return array_merge(...$checks);
    }

    public function runComplianceChecks(PayrollRun $run): int
    {
        return $this->saveResults($run, $this->runAllComplianceChecks($run));
    }

    public function saveResults(PayrollRun $run, array $results): int
    {
        $saved = 0;

        foreach ($results as $result) {
            $anomaly = PayrollAnomaly::firstOrCreate(
                [
                    'run_id'       => $run->id,
                    'employee_id'  => $result['employee_id'] ?? null,
                    'anomaly_type' => $result['type'],
                ],
                [
                    'description' => $result['description'],
                    'severity'    => $result['severity'],
                    'status'      => 'open',
                    'detected_at' => now(),
                    'metadata'    => $result['metadata'] ?? null,
                ],
            );

            if ($anomaly->wasRecentlyCreated) {
                $saved++;
            }
        }

        return $saved;
    }

    public function getSummary(): object
    {
        $base = PayrollAnomaly::whereIn('anomaly_type', self::COMPLIANCE_TYPES);

        return (object) [
            'total_open'     => (clone $base)->where('status', 'open')->count(),
            'total_critical' => (clone $base)->where('severity', 'critical')->count(),
            'total_high'     => (clone $base)->where('severity', 'high')->count(),
            'total_resolved' => (clone $base)->where('status', 'resolved')->count(),
        ];
    }
}
