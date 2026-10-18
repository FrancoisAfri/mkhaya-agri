<?php

namespace Database\Seeders;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRiskScore;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2 — Payroll Anomaly Firewall
 *
 * Seeds demo anomalies covering all 9 detection types across the April 2026 run
 * (status=review, ai_risk_level=high). Also seeds per-employee risk scores and
 * updates the run's ai_risk_level from the computed scores.
 *
 * Anomaly types demonstrated:
 *   1. duplicate_payment             — critical (blocked)
 *   2. inactive_employee_paid        — high
 *   3. terminated_employee_paid      — critical (blocked)
 *   4. unusual_overtime              — medium
 *   5. unauthorized_salary_increase  — high (escalated)
 *   6. duplicate_bank_account        — critical (run-level)
 *   7. negative_net_pay              — high
 *   8. payroll_above_budget          — high (run-level, escalated)
 *   9. late_payroll_change           — medium
 */
class PayrollPhase2Seeder extends Seeder
{
    public function run(): void
    {
        // Target: April 2026 run which is in 'review' status
        $aprilRun = PayrollRun::where('ref_number', 'PR-202604-0001')->first();
        // Fallback: first non-released run available
        if (!$aprilRun) {
            $aprilRun = PayrollRun::whereNotIn('status', ['released', 'cancelled'])->first();
        }
        if (!$aprilRun) {
            $this->command?->warn('No suitable run found for Phase 2 anomaly demo. Run PayrollPhase1Seeder first.');
            return;
        }

        // March run for salary-increase anomaly (compare against April)
        $marchRun = PayrollRun::where('ref_number', 'PR-202603-0001')->first();

        DB::transaction(function () use ($aprilRun, $marchRun) {
            $this->seedAnomalies($aprilRun, $marchRun);
            $this->seedRiskScores($aprilRun);
            $this->updateRunRiskLevel($aprilRun);
        });
    }

    // ── Demo anomalies ────────────────────────────────────────────────────────

    private function seedAnomalies(PayrollRun $run, ?PayrollRun $prevRun): void
    {
        $now = now();

        $anomalies = [

            // 1. Duplicate payment — employee_id=4 appears twice in payslips
            [
                'anomaly_type' => 'duplicate_payment',
                'employee_id'  => 4,
                'description'  => 'Employee #4 (Nkosana Ncube) appears 2 times in run PR-202604-0001. Possible duplicate payment.',
                'severity'     => 'high',
                'status'       => 'open',
                'metadata'     => ['count' => 2, 'ref_number' => $run->ref_number],
            ],

            // 2. Inactive employee paid — simulated inactive status
            [
                'anomaly_type' => 'inactive_employee_paid',
                'employee_id'  => 6,
                'description'  => 'User001 Demo (#6) has inactive HR status (0) but is included in payroll.',
                'severity'     => 'high',
                'status'       => 'investigating',
                'metadata'     => ['hr_status' => 0, 'employee_name' => 'User001 Demo'],
            ],

            // 3. Terminated employee paid — simulated date_left before period
            [
                'anomaly_type' => 'terminated_employee_paid',
                'employee_id'  => 6,
                'description'  => 'User001 Demo (#6) left on 2026-03-15, before period start 2026-04-01.',
                'severity'     => 'critical',
                'status'       => 'open',
                'metadata'     => ['date_left' => '2026-03-15', 'period_start' => '2026-04-01'],
            ],

            // 4. Unusual overtime — employee_id=2 overtime > 50% of basic
            [
                'anomaly_type' => 'unusual_overtime',
                'employee_id'  => 2,
                'description'  => 'Overtime R25,500 is 56.7% of basic salary R45,000 (threshold: 50%).',
                'severity'     => 'medium',
                'status'       => 'open',
                'metadata'     => [
                    'overtime_amount' => 25500,
                    'basic_salary'    => 45000,
                    'percentage'      => 56.7,
                    'threshold_pct'   => 50,
                ],
            ],

            // 5. Unauthorized salary increase — employee_id=4, 16% above 15% threshold
            [
                'anomaly_type' => 'unauthorized_salary_increase',
                'employee_id'  => 4,
                'description'  => 'Salary increased 16.0% (threshold: 15%) vs previous run ' . ($prevRun?->ref_number ?? 'PR-202603-0001') . '.',
                'severity'     => 'high',
                'status'       => 'open',
                'metadata'     => [
                    'previous_salary'  => 38000,
                    'current_salary'   => 44080,
                    'increase_pct'     => 16.0,
                    'previous_run_ref' => $prevRun?->ref_number ?? 'PR-202603-0001',
                ],
            ],

            // 6. Duplicate bank account — run-level (employee_id=null)
            [
                'anomaly_type' => 'duplicate_bank_account',
                'employee_id'  => null,
                'description'  => 'Account 6281847391 (FNB) is shared by 2 employees (IDs: 4, 6). Possible ghost employee.',
                'severity'     => 'critical',
                'status'       => 'open',
                'metadata'     => [
                    'account_number' => '6281847391',
                    'bank_name'      => 'FNB',
                    'employee_ids'   => '4, 6',
                    'count'          => 2,
                ],
            ],

            // 7. Negative net pay — employee_id=6 (over-deducted)
            [
                'anomaly_type' => 'negative_net_pay',
                'employee_id'  => 6,
                'description'  => 'Employee #6 has negative net pay of R-2,150.00 (gross: R28,000, deductions: R30,150.00).',
                'severity'     => 'high',
                'status'       => 'open',
                'metadata'     => [
                    'net_pay'          => -2150.00,
                    'gross_pay'        => 28000,
                    'total_deductions' => 30150,
                ],
            ],

            // 8. Payroll above budget — run-level (employee_id=null)
            [
                'anomaly_type' => 'payroll_above_budget',
                'employee_id'  => null,
                'description'  => 'Total net pay R238,456.65 exceeds budget R200,000.00 by R38,456.65 (19.2% over).',
                'severity'     => 'high',
                'status'       => 'open',
                'metadata'     => [
                    'budget'    => 200000,
                    'total_net' => 238456.65,
                    'excess'    => 38456.65,
                    'over_pct'  => 19.2,
                ],
            ],

            // 9. Late payroll change — change logged after period end
            [
                'anomaly_type' => 'late_payroll_change',
                'employee_id'  => 1,
                'description'  => "Change to 'basic_salary' recorded on 2026-05-02 — after period end 2026-04-30.",
                'severity'     => 'medium',
                'status'       => 'resolved',
                'metadata'     => [
                    'field_changed' => 'basic_salary',
                    'changed_at'    => '2026-05-02 14:35:00',
                    'old_value'     => '85000',
                    'new_value'     => '88000',
                ],
            ],
        ];

        foreach ($anomalies as $anomaly) {
            PayrollAnomaly::firstOrCreate(
                [
                    'run_id'       => $run->id,
                    'anomaly_type' => $anomaly['anomaly_type'],
                    'employee_id'  => $anomaly['employee_id'],
                ],
                [
                    'uuid'             => Str::uuid(),
                    'description'      => $anomaly['description'],
                    'severity'         => $anomaly['severity'],
                    'status'           => $anomaly['status'],
                    'detected_at'      => $anomaly['status'] === 'resolved' ? now()->subDays(3) : now()->subDays(1),
                    'metadata'         => $anomaly['metadata'],
                    'resolved_by'      => $anomaly['status'] === 'resolved' ? DB::table('users')->orderBy('id')->value('id') : null,
                    'resolved_at'      => $anomaly['status'] === 'resolved' ? now()->subDay() : null,
                    'resolution_notes' => $anomaly['status'] === 'resolved'
                        ? 'Salary change was pre-approved by HR Director on 2026-04-28. Accepted.'
                        : null,
                ]
            );
        }
    }

    // ── Per-employee risk scores ───────────────────────────────────────────────

    private function seedRiskScores(PayrollRun $run): void
    {
        $weights = ['critical' => 40, 'high' => 20, 'medium' => 10, 'low' => 5];

        // Employee-level open anomalies only
        $groups = PayrollAnomaly::where('run_id', $run->id)
            ->where('status', 'open')
            ->whereNotNull('employee_id')
            ->get()
            ->groupBy('employee_id');

        foreach ($groups as $empId => $anomalies) {
            $score   = 0;
            $factors = [];

            foreach ($anomalies as $a) {
                $pts      = $weights[$a->severity] ?? 5;
                $score   += $pts;
                $factors[] = [
                    'type'     => $a->anomaly_type,
                    'severity' => $a->severity,
                    'points'   => $pts,
                ];
            }

            $score = min($score, 100);
            $level = match(true) {
                $score >= 70 => 'critical',
                $score >= 40 => 'high',
                $score >= 20 => 'medium',
                default      => 'low',
            };

            $payslipId = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('employee_id', $empId)
                ->value('id');

            PayrollRiskScore::updateOrCreate(
                ['run_id' => $run->id, 'employee_id' => $empId],
                [
                    'payslip_id'    => $payslipId,
                    'score'         => $score,
                    'level'         => $level,
                    'factors'       => $factors,
                    'calculated_at' => now(),
                ]
            );
        }
    }

    // ── Update run risk level ─────────────────────────────────────────────────

    private function updateRunRiskLevel(PayrollRun $run): void
    {
        $maxScore = PayrollRiskScore::where('run_id', $run->id)->max('score') ?? 0;

        $runLevel = match(true) {
            $maxScore >= 70 => 'critical',
            $maxScore >= 40 => 'high',
            $maxScore >= 20 => 'medium',
            default         => 'low',
        };

        $run->update([
            'ai_risk_level'     => $runLevel,
            'ai_anomaly_summary' => [
                'summary'        => "April 2026 payroll run flagged {$run->anomalies()->where('status', 'open')->count()} open anomalies including critical terminated-employee and duplicate bank account issues. Immediate review required before release.",
                'risk_level'     => $runLevel,
                'open_anomalies' => $run->anomalies()->where('status', 'open')->count(),
                'generated_at'   => now()->toDateTimeString(),
            ],
        ]);
    }
}
