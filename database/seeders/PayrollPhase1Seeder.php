<?php

namespace Database\Seeders;

use App\Modules\HR\Payroll\Models\PayrollApproval;
use App\Modules\HR\Payroll\Models\PayrollApprovalStep;
use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use App\Modules\HR\Payroll\Models\PayrollPeriod;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 1 — Core Payroll Foundation
 *
 * Seeds:
 *   • payroll_settings   — company statutory configuration
 *   • payroll_periods    — Jan-May 2026 pay periods
 *   • payroll_runs       — 3 released + 1 review + 1 draft
 *   • payslips           — per-employee payslips with calculated PAYE/UIF/net
 *   • payroll_approvals  — approval records for submitted/released runs
 *   • payroll_approval_steps — individual approval actions
 *   • payroll_audit_logs — full audit trail per operation
 */
class PayrollPhase1Seeder extends Seeder
{
    // ── PAYE / UIF constants (South African 2024/2025) ────────────────────────
    private const PAYE_THRESHOLD = 95_750.00;
    private const PAYE_RATE      = 0.18;
    private const UIF_RATE       = 0.01;
    private const UIF_CAP        = 177.12;

    // ── Demo employee salaries (basic monthly, ZAR) ───────────────────────────
    private array $employees = [
        1 => ['name' => 'Francois Keou',     'salary' => 85_000, 'role' => 'Director'],
        2 => ['name' => 'Support Mkhaya',    'salary' => 45_000, 'role' => 'Support Manager'],
        4 => ['name' => 'Nkosana Ncube',     'salary' => 38_000, 'role' => 'HR Coordinator'],
        5 => ['name' => 'Lerato Mononnyane', 'salary' => 42_000, 'role' => 'Finance Officer'],
        6 => ['name' => 'User001 Demo',      'salary' => 28_000, 'role' => 'Junior Staff'],
    ];

    public function run(): void
    {
        $adminId = DB::table('users')->orderBy('id')->value('id') ?? 1;

        DB::transaction(function () use ($adminId) {
            $this->seedSettings();
            $periods = $this->seedPeriods($adminId);
            $this->seedRunsAndPayslips($adminId, $periods);
        });
    }

    // ── 1. Payroll Settings ───────────────────────────────────────────────────

    private function seedSettings(): void
    {
        PayrollSetting::updateOrCreate(
            ['id' => 1],
            [
                'currency'          => 'ZAR',
                'pay_frequency'     => 'monthly',
                'pay_day'           => 25,
                'overtime_rate'     => 1.50,
                'paye_enabled'      => true,
                'uif_enabled'       => true,
                'sdl_enabled'       => true,
                'uif_employee_rate' => 0.0100,
                'uif_employer_rate' => 0.0100,
                'sdl_rate'          => 0.0100,
                'payroll_budget'    => 200_000.00, // triggers budget anomaly in Phase 2
                'is_active'         => true,
                'settings_json'     => [
                    'probation_months'   => 3,
                    'bonus_month'        => 12,
                    'annual_leave_days'  => 15,
                ],
            ]
        );
    }

    // ── 2. Payroll Periods ────────────────────────────────────────────────────

    private function seedPeriods(int $adminId): array
    {
        $periods = [
            ['name' => 'January 2026',  'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'pay_date' => '2026-01-25', 'status' => 'closed'],
            ['name' => 'February 2026', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'pay_date' => '2026-02-25', 'status' => 'closed'],
            ['name' => 'March 2026',    'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'pay_date' => '2026-03-25', 'status' => 'closed'],
            ['name' => 'April 2026',    'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'pay_date' => '2026-04-25', 'status' => 'locked'],
            ['name' => 'May 2026',      'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'pay_date' => '2026-05-25', 'status' => 'open'],
        ];

        $inserted = [];
        foreach ($periods as $p) {
            $inserted[$p['name']] = PayrollPeriod::firstOrCreate(
                ['period_start' => $p['period_start']],
                array_merge($p, ['frequency' => 'monthly', 'created_by' => $adminId])
            );
        }
        return $inserted;
    }

    // ── 3. Runs + Payslips + Approvals + Audit Logs ───────────────────────────

    private function seedRunsAndPayslips(int $adminId, array $periods): void
    {
        $runDefinitions = [
            [
                'ref'          => 'PR-202601-0001',
                'name'         => 'January 2026 Payroll',
                'period_start' => '2026-01-01',
                'period_end'   => '2026-01-31',
                'status'       => 'released',
                'released_at'  => '2026-01-26 10:00:00',
                'ai_risk_level'=> 'low',
                'notes'        => 'Clean run. No anomalies detected.',
            ],
            [
                'ref'          => 'PR-202602-0001',
                'name'         => 'February 2026 Payroll',
                'period_start' => '2026-02-01',
                'period_end'   => '2026-02-28',
                'status'       => 'released',
                'released_at'  => '2026-02-26 09:30:00',
                'ai_risk_level'=> 'low',
                'notes'        => 'Approved and released without issues.',
            ],
            [
                'ref'          => 'PR-202603-0001',
                'name'         => 'March 2026 Payroll',
                'period_start' => '2026-03-01',
                'period_end'   => '2026-03-31',
                'status'       => 'released',
                'released_at'  => '2026-03-26 11:15:00',
                'ai_risk_level'=> 'medium',
                'notes'        => 'One salary adjustment noted. Cleared by HR.',
            ],
            [
                'ref'          => 'PR-202604-0001',
                'name'         => 'April 2026 Payroll',
                'period_start' => '2026-04-01',
                'period_end'   => '2026-04-30',
                'status'       => 'review',
                'released_at'  => null,
                'ai_risk_level'=> 'high',
                'notes'        => 'Submitted for approval. Anomalies detected — pending review.',
            ],
            [
                'ref'          => 'PR-202605-0001',
                'name'         => 'May 2026 Payroll',
                'period_start' => '2026-05-01',
                'period_end'   => '2026-05-31',
                'status'       => 'draft',
                'released_at'  => null,
                'ai_risk_level'=> null,
                'notes'        => 'Draft — employees not yet added.',
            ],
        ];

        foreach ($runDefinitions as $def) {
            $run = PayrollRun::firstOrCreate(
                ['ref_number' => $def['ref']],
                [
                    'uuid'             => Str::uuid(),
                    'name'             => $def['name'],
                    'period_start'     => $def['period_start'],
                    'period_end'       => $def['period_end'],
                    'status'           => $def['status'],
                    'released_at'      => $def['released_at'],
                    'released_by'      => $def['released_at'] ? $adminId : null,
                    'ai_risk_level'    => $def['ai_risk_level'],
                    'notes'            => $def['notes'],
                    'total_gross'      => 0,
                    'total_deductions' => 0,
                    'total_net'        => 0,
                    'employee_count'   => 0,
                    'created_by'       => $adminId,
                ]
            );

            // Skip payslips for draft run
            if ($def['status'] === 'draft') {
                $this->auditLog($run, $adminId, 'run.created', "Draft run {$def['ref']} created.");
                continue;
            }

            // Apply a 15% salary bump on March run for user_id=4 (triggers Phase 2 anomaly demo)
            $salaryOverrides = [];
            if ($def['ref'] === 'PR-202603-0001') {
                $salaryOverrides[4] = (int) ($this->employees[4]['salary'] * 1.16); // 16% increase — above threshold
            }

            $this->seedPayslips($run, $adminId, $salaryOverrides);
            $this->seedApproval($run, $adminId, $def['status']);
            $this->auditLog($run, $adminId, 'run.created', "Run {$def['ref']} seeded.");

            if (in_array($def['status'], ['review', 'released'])) {
                $this->auditLog($run, $adminId, 'run.employees_added', "All employees added to run.", ['employee_count' => count($this->employees)]);
                $this->auditLog($run, $adminId, 'run.calculated', "Payslips calculated.", ['employee_count' => count($this->employees)]);
                $this->auditLog($run, $adminId, 'run.locked', "Run locked for review.");
                $this->auditLog($run, $adminId, 'run.submitted', "Run submitted for approval.");
            }

            if ($def['status'] === 'released') {
                $this->auditLog($run, $adminId, 'run.released', "Run released after approval.", [
                    'total_net'      => (float) $run->total_net,
                    'employee_count' => (int) $run->employee_count,
                ]);
            }
        }
    }

    // ── Payslip calculation ───────────────────────────────────────────────────

    private function seedPayslips(PayrollRun $run, int $adminId, array $salaryOverrides = []): void
    {
        $totalGross      = 0;
        $totalDeductions = 0;
        $totalNet        = 0;
        $count           = 0;

        foreach ($this->employees as $userId => $emp) {
            if (Payslip::where('payroll_run_id', $run->id)->where('employee_id', $userId)->exists()) {
                continue;
            }

            $basic   = $salaryOverrides[$userId] ?? $emp['salary'];
            $deducts = $this->computeDeductions($basic);
            $net     = round($basic - $deducts['total'], 2);

            Payslip::create([
                'uuid'             => Str::uuid(),
                'payroll_run_id'   => $run->id,
                'employee_id'      => $userId,
                'period_start'     => $run->period_start,
                'period_end'       => $run->period_end,
                'status'           => 'finalised',
                'basic_salary'     => $basic,
                'gross_pay'        => $basic,
                'paye'             => $deducts['paye'],
                'uif'              => $deducts['uif'],
                'pension'          => 0,
                'total_deductions' => $deducts['total'],
                'net_pay'          => $net,
                'issued_at'        => now(),
            ]);

            $totalGross      += $basic;
            $totalDeductions += $deducts['total'];
            $totalNet        += $net;
            $count++;
        }

        $run->update([
            'total_gross'      => round($totalGross, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_net'        => round($totalNet, 2),
            'employee_count'   => $count,
        ]);
    }

    private function computeDeductions(float $basic): array
    {
        $annualGross = $basic * 12;
        $taxable     = max(0, $annualGross - self::PAYE_THRESHOLD);
        $paye        = round(($taxable * self::PAYE_RATE) / 12, 2);
        $uif         = min(round($basic * self::UIF_RATE, 2), self::UIF_CAP);

        return ['paye' => $paye, 'uif' => $uif, 'total' => round($paye + $uif, 2)];
    }

    // ── Approval records ──────────────────────────────────────────────────────

    private function seedApproval(PayrollRun $run, int $adminId, string $runStatus): void
    {
        if (PayrollApproval::where('run_id', $run->id)->exists()) {
            return;
        }

        $approvalStatus = match($runStatus) {
            'released' => 'approved',
            'review'   => 'pending',
            default    => null,
        };

        if (!$approvalStatus) {
            return;
        }

        $approval = PayrollApproval::create([
            'uuid'          => Str::uuid(),
            'run_id'        => $run->id,
            'submitted_by'  => $adminId,
            'current_level' => $runStatus === 'released' ? 'finance' : 'payroll_officer',
            'status'        => $approvalStatus,
            'high_risk'     => in_array($run->ai_risk_level, ['high', 'critical']),
            'due_date'      => now()->addDays(3),
        ]);

        if ($runStatus === 'released') {
            // Payroll officer sign-off
            PayrollApprovalStep::create([
                'approval_id' => $approval->id,
                'approver_id' => $adminId,
                'level'       => 'payroll_officer',
                'action'      => 'approved',
                'comments'    => 'All payslips verified. No issues.',
                'acted_at'    => now()->subDays(2),
            ]);

            // HR manager sign-off
            PayrollApprovalStep::create([
                'approval_id' => $approval->id,
                'approver_id' => $adminId,
                'level'       => 'hr_manager',
                'action'      => 'approved',
                'comments'    => 'Headcount and salary lines confirmed.',
                'acted_at'    => now()->subDays(1),
            ]);

            // Finance sign-off (final)
            PayrollApprovalStep::create([
                'approval_id' => $approval->id,
                'approver_id' => $adminId,
                'level'       => 'finance',
                'action'      => 'approved',
                'comments'    => 'Budget within limits. Approved for release.',
                'acted_at'    => now(),
            ]);
        }
    }

    // ── Audit log helpers ─────────────────────────────────────────────────────

    private function auditLog(PayrollRun $run, int $userId, string $action, string $description, array $meta = []): void
    {
        PayrollAuditLog::create([
            'uuid'        => Str::uuid(),
            'run_id'      => $run->id,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }
}
