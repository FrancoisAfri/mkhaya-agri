<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollAnomalyRule;
use App\Modules\HR\Payroll\Models\PayrollRiskScore;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Payroll Anomaly Firewall — Phase 2
 *
 * Each detect*() method returns an array of anomaly arrays.
 * Run-level anomalies (budget) have employee_id = null.
 * All detectors are rule-driven: thresholds come from payroll_anomaly_rules.
 */
class PayrollAnomalyService
{
    /** @var Collection<string, object> Keyed by anomaly_type */
    private Collection $rules;

    public function __construct(
        protected PayrollChangeMonitorService $changeMonitorService
    ) {
        $this->rules = collect();
    }

    // ── Rule helpers ─────────────────────────────────────────────────────────

    private function loadRules(): void
    {
        if ($this->rules->isNotEmpty()) {
            return;
        }
        $this->rules = PayrollAnomalyRule::where('active', true)
            ->get()
            ->keyBy('anomaly_type');
    }

    private function rule(string $type): ?object
    {
        $this->loadRules();
        return $this->rules->get($type);
    }

    private function threshold(string $type, float $default): float
    {
        return (float) ($this->rule($type)?->threshold_value ?? $default);
    }

    private function severity(string $type, string $default): string
    {
        return $this->rule($type)?->severity ?? $default;
    }

    // ── Detection functions ───────────────────────────────────────────────────

    /**
     * Detect duplicate payments: same employee on the same run more than once.
     */
    public function detectDuplicatePayments(PayrollRun $run): array
    {
        try {
            $duplicates = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->select('employee_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('employee_id')
                ->having('cnt', '>', 1)
                ->get();

            $refNumber = $run->ref_number;
            return $duplicates->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'type'        => 'duplicate_payment',
                'description' => "Employee #{$row->employee_id} appears {$row->cnt} times in run {$refNumber}. Possible duplicate payment.",
                'severity'    => $this->severity('duplicate_payment', 'high'),
                'metadata'    => ['count' => $row->cnt, 'ref_number' => $refNumber],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectDuplicatePayments: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect inactive employees: hr_people.status != 1 (active).
     */
    public function detectInactiveEmployeePaid(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where(fn ($q) => $q->whereNull('hp.status')->orWhere('hp.status', '!=', 1))
                ->select('ps.employee_id', 'hp.status',
                    DB::raw("COALESCE(TRIM(CONCAT(hp.first_name, ' ', COALESCE(hp.surname, ''))), CAST(ps.employee_id AS VARCHAR)) AS emp_name"))
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'type'        => 'inactive_employee_paid',
                'description' => "{$row->emp_name} (#{$row->employee_id}) has inactive HR status ({$row->status}) but is included in payroll.",
                'severity'    => $this->severity('inactive_employee_paid', 'high'),
                'metadata'    => ['hr_status' => $row->status, 'employee_name' => $row->emp_name],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectInactiveEmployeePaid: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect terminated employees: date_left (Unix timestamp) before period_start.
     */
    public function detectTerminatedEmployeePaid(PayrollRun $run): array
    {
        try {
            $periodStart = $run->period_start->timestamp; // compare as Unix ts

            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.date_left')
                ->where('hp.date_left', '>', 0)
                ->where('hp.date_left', '<', $periodStart)
                ->select('ps.employee_id', 'hp.date_left',
                    DB::raw("COALESCE(TRIM(CONCAT(hp.first_name, ' ', COALESCE(hp.surname, ''))), CAST(ps.employee_id AS VARCHAR)) AS emp_name"))
                ->get();

            return $rows->map(function ($row) use ($run) {
                $leftDate = date('Y-m-d', (int) $row->date_left);
                return [
                    'employee_id' => $row->employee_id,
                    'type'        => 'terminated_employee_paid',
                    'description' => "{$row->emp_name} (#{$row->employee_id}) left on {$leftDate}, before period start {$run->period_start->toDateString()}.",
                    'severity'    => $this->severity('terminated_employee_paid', 'critical'),
                    'metadata'    => ['date_left' => $leftDate, 'period_start' => $run->period_start->toDateString()],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('detectTerminatedEmployeePaid: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect unusual overtime: overtime earnings line > threshold% of basic salary.
     */
    public function detectUnusualOvertime(PayrollRun $run): array
    {
        try {
            $thresholdPct = $this->threshold('unusual_overtime', 50) / 100;

            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'overtime')
                ->where('ps.basic_salary', '>', 0)
                ->select('ps.employee_id', 'ps.basic_salary', 'pel.amount')
                ->get();

            $anomalies = [];
            foreach ($rows as $row) {
                $cap = (float) $row->basic_salary * $thresholdPct;
                if ((float) $row->amount > $cap) {
                    $pct = round(((float) $row->amount / (float) $row->basic_salary) * 100, 1);
                    $anomalies[] = [
                        'employee_id' => $row->employee_id,
                        'type'        => 'unusual_overtime',
                        'description' => "Overtime R{$row->amount} is {$pct}% of basic salary R{$row->basic_salary} (threshold: " . ($thresholdPct * 100) . "%).",
                        'severity'    => $this->severity('unusual_overtime', 'medium'),
                        'metadata'    => [
                            'overtime_amount' => $row->amount,
                            'basic_salary'    => $row->basic_salary,
                            'percentage'      => $pct,
                            'threshold_pct'   => $thresholdPct * 100,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectUnusualOvertime: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect unauthorized salary increases vs the previous released run.
     * Threshold comes from payroll_anomaly_rules (default 15%).
     */
    public function detectUnauthorizedSalaryIncrease(PayrollRun $run): array
    {
        try {
            $thresholdPct = $this->threshold('unauthorized_salary_increase', 15) / 100;

            $previousRun = PayrollRun::where('period_start', '<', $run->period_start)
                ->where('status', 'released')
                ->latest('period_start')
                ->first();

            if (!$previousRun) {
                return [];
            }

            $current  = DB::table('payslips')->where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
            $previous = DB::table('payslips')->where('payroll_run_id', $previousRun->id)->get()->keyBy('employee_id');

            $anomalies = [];
            foreach ($current as $empId => $curr) {
                $prev = $previous[$empId] ?? null;
                if (!$prev || (float) $prev->basic_salary <= 0) {
                    continue;
                }
                $increase = ((float) $curr->basic_salary - (float) $prev->basic_salary) / (float) $prev->basic_salary;
                if ($increase > $thresholdPct) {
                    $pct = round($increase * 100, 1);
                    $anomalies[] = [
                        'employee_id' => $empId,
                        'type'        => 'unauthorized_salary_increase',
                        'description' => "Salary increased {$pct}% (threshold: " . ($thresholdPct * 100) . "%) vs previous run {$previousRun->ref_number}.",
                        'severity'    => $this->severity('unauthorized_salary_increase', 'high'),
                        'metadata'    => [
                            'previous_salary'  => $prev->basic_salary,
                            'current_salary'   => $curr->basic_salary,
                            'increase_pct'     => $pct,
                            'previous_run_ref' => $previousRun->ref_number,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectUnauthorizedSalaryIncrease: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect duplicate bank accounts: two or more employees in the run sharing
     * the same non-null account_number from hr_people.
     */
    public function detectDuplicateBankAccounts(PayrollRun $run): array
    {
        try {
            // Find account numbers shared by 2+ employees on this run
            $duplicates = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.account_number')
                ->where('hp.account_number', '!=', '')
                ->select('hp.account_number', 'hp.bank_name',
                    DB::raw('COUNT(ps.employee_id) as emp_count'),
                    DB::raw("STRING_AGG(CAST(ps.employee_id AS VARCHAR), ', ') AS employee_ids"))
                ->groupBy('hp.account_number', 'hp.bank_name')
                ->having(DB::raw('COUNT(ps.employee_id)'), '>', 1)
                ->get();

            return $duplicates->map(fn ($row) => [
                'employee_id' => null, // run-level anomaly
                'type'        => 'duplicate_bank_account',
                'description' => "Account {$row->account_number} ({$row->bank_name}) is shared by {$row->emp_count} employees (IDs: {$row->employee_ids}).",
                'severity'    => $this->severity('duplicate_bank_account', 'critical'),
                'metadata'    => [
                    'account_number' => $row->account_number,
                    'bank_name'      => $row->bank_name,
                    'employee_ids'   => $row->employee_ids,
                    'count'          => $row->emp_count,
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectDuplicateBankAccounts: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect negative net pay.
     */
    public function detectNegativeNetPay(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('net_pay', '<', 0)
                ->select('employee_id', 'net_pay', 'total_deductions', 'gross_pay')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'type'        => 'negative_net_pay',
                'description' => "Employee #{$row->employee_id} has negative net pay of R{$row->net_pay} (gross: R{$row->gross_pay}, deductions: R{$row->total_deductions}).",
                'severity'    => $this->severity('negative_net_pay', 'high'),
                'metadata'    => [
                    'net_pay'          => $row->net_pay,
                    'gross_pay'        => $row->gross_pay,
                    'total_deductions' => $row->total_deductions,
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectNegativeNetPay: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect payroll above budget: total_net > payroll_settings.payroll_budget.
     */
    public function detectPayrollAboveBudget(PayrollRun $run): array
    {
        try {
            $setting = DB::table('payroll_settings')->first();
            if (!$setting || empty($setting->payroll_budget)) {
                return [];
            }

            $budget   = (float) $setting->payroll_budget;
            $totalNet = (float) $run->total_net;

            if ($totalNet <= $budget) {
                return [];
            }

            $excess  = round($totalNet - $budget, 2);
            $overPct = round(($excess / $budget) * 100, 1);

            return [[
                'employee_id' => null,
                'type'        => 'payroll_above_budget',
                'description' => "Total net pay R{$totalNet} exceeds budget R{$budget} by R{$excess} ({$overPct}% over).",
                'severity'    => $this->severity('payroll_above_budget', 'high'),
                'metadata'    => [
                    'budget'    => $budget,
                    'total_net' => $totalNet,
                    'excess'    => $excess,
                    'over_pct'  => $overPct,
                ],
            ]];
        } catch (\Throwable $e) {
            Log::error('detectPayrollAboveBudget: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect late payroll changes: change_logs recorded after period_end.
     */
    public function detectLatePayrollChanges(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payroll_change_logs')
                ->where('run_id', $run->id)
                ->whereDate('changed_at', '>', $run->period_end)
                ->select('employee_id', 'field_changed', 'changed_at', 'old_value', 'new_value')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id' => $row->employee_id ?? null,
                'type'        => 'late_payroll_change',
                'description' => "Change to '{$row->field_changed}' recorded on {$row->changed_at} — after period end {$run->period_end->toDateString()}.",
                'severity'    => $this->severity('late_payroll_change', 'medium'),
                'metadata'    => [
                    'field_changed' => $row->field_changed,
                    'changed_at'    => $row->changed_at,
                    'old_value'     => $row->old_value,
                    'new_value'     => $row->new_value,
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectLatePayrollChanges: ' . $e->getMessage());
            return [];
        }
    }

    // ── Orchestration ─────────────────────────────────────────────────────────

    public function runFullAnomalyCheck(PayrollRun $run): int
    {
        $detected = array_merge(
            $this->detectDuplicatePayments($run),
            $this->detectInactiveEmployeePaid($run),
            $this->detectTerminatedEmployeePaid($run),
            $this->detectUnusualOvertime($run),
            $this->detectUnauthorizedSalaryIncrease($run),
            $this->detectDuplicateBankAccounts($run),
            $this->detectNegativeNetPay($run),
            $this->detectPayrollAboveBudget($run),
            $this->detectLatePayrollChanges($run),
        );

        $saved = $this->saveAnomalies($run, $detected);
        $this->calculateRiskScores($run);

        return $saved;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function saveAnomalies(PayrollRun $run, array $anomalies): int
    {
        if (empty($anomalies)) {
            return 0;
        }

        $now   = now();
        $saved = 0;

        foreach ($anomalies as $anomaly) {
            $empId = $anomaly['employee_id'] ?? null;

            // Deduplication: skip if an open anomaly of the same type already exists for this run+employee
            $exists = PayrollAnomaly::where('run_id', $run->id)
                ->where('anomaly_type', $anomaly['type'])
                ->where('employee_id', $empId)
                ->where('status', 'open')
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                PayrollAnomaly::create([
                    'run_id'       => $run->id,
                    'employee_id'  => $empId,
                    'anomaly_type' => $anomaly['type'],
                    'description'  => $anomaly['description'],
                    'severity'     => $anomaly['severity'],
                    'status'       => 'open',
                    'detected_at'  => $now,
                    'metadata'     => $anomaly['metadata'] ?? null,
                ]);
                $saved++;
            } catch (\Throwable $e) {
                Log::warning("saveAnomalies: failed for type={$anomaly['type']} emp={$empId}: " . $e->getMessage());
            }
        }

        return $saved;
    }

    // ── Risk scoring ──────────────────────────────────────────────────────────

    /**
     * Calculate a per-employee risk score based on open anomalies.
     * Score 0-100 composed of severity weights:
     *   critical = 40pts, high = 20pts, medium = 10pts, low = 5pts (capped at 100)
     *
     * Also updates the run's ai_risk_level.
     */
    public function calculateRiskScores(PayrollRun $run): void
    {
        $weights = ['critical' => 40, 'high' => 20, 'medium' => 10, 'low' => 5];

        $anomalies = PayrollAnomaly::where('run_id', $run->id)
            ->where('status', 'open')
            ->whereNotNull('employee_id')
            ->select('employee_id', 'severity', 'anomaly_type')
            ->get()
            ->groupBy('employee_id');

        $maxRunScore = 0;

        foreach ($anomalies as $empId => $empAnomalies) {
            $score   = 0;
            $factors = [];

            foreach ($empAnomalies as $a) {
                $pts      = $weights[$a->severity] ?? 5;
                $score   += $pts;
                $factors[] = ['type' => $a->anomaly_type, 'severity' => $a->severity, 'points' => $pts];
            }

            $score = min($score, 100);
            $level = match(true) {
                $score >= 70 => 'critical',
                $score >= 40 => 'high',
                $score >= 20 => 'medium',
                default      => 'low',
            };

            PayrollRiskScore::updateOrCreate(
                ['run_id' => $run->id, 'employee_id' => $empId],
                [
                    'payslip_id'    => DB::table('payslips')->where('payroll_run_id', $run->id)->where('employee_id', $empId)->value('id'),
                    'score'         => $score,
                    'level'         => $level,
                    'factors'       => $factors,
                    'calculated_at' => now(),
                ]
            );

            $maxRunScore = max($maxRunScore, $score);
        }

        // Update the run's ai_risk_level from the highest employee score
        $runLevel = match(true) {
            $maxRunScore >= 70 => 'critical',
            $maxRunScore >= 40 => 'high',
            $maxRunScore >= 20 => 'medium',
            default            => 'low',
        };

        $run->update(['ai_risk_level' => $runLevel]);
    }
}
