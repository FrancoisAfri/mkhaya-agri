<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payroll Leakage Detection Engine (Phase 4)
 *
 * Detects financial leakage: duplicate allowances, post-termination benefits,
 * allowance overages, recurring drift, and suspicious deduction profiles.
 * All findings are persisted to payroll_anomalies.
 *
 * estimatePayrollLeakage() provides the four dashboard card values.
 */
class PayrollLeakageService
{
    // ── Leakage type metadata ─────────────────────────────────────────────────

    private const LEAKAGE_SEVERITY = [
        'duplicate_allowance'         => 'high',
        'allowance_leakage'           => 'medium',
        'benefit_after_termination'   => 'critical',
        'suspicious_low_deductions'   => 'medium',
        'recurring_payroll_drift'     => 'medium',
    ];

    // ── Orchestrators ─────────────────────────────────────────────────────────

    public function detectPayrollLeakage(PayrollRun $run): array
    {
        return array_merge(
            $this->detectDuplicateAllowances($run),
            $this->detectAllowanceLeakage($run),
            $this->detectBenefitLeakage($run),
            $this->detectHistoricalOverpayments($run),
        );
    }

    public function runLeakageDetection(PayrollRun $run): int
    {
        $results = $this->detectPayrollLeakage($run);
        $drift   = $this->detectRecurringPayrollDrift();

        // Attach run context to drift anomalies before saving
        foreach ($drift as &$d) {
            $d['run_id'] = $run->id;
        }
        unset($d);

        return $this->saveResults($run, array_merge($results, $drift));
    }

    // ── 1. Duplicate allowances ───────────────────────────────────────────────

    public function detectDuplicateAllowances(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'pel.description as allowance_description',
                    DB::raw('COUNT(*) as occurrence_count'),
                    DB::raw('SUM(pel.amount) as total_amount'),
                )
                ->groupBy('ps.employee_id', 'hp.first_name', 'hp.surname', 'pel.description')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->full_name,
                'anomaly_type'  => 'duplicate_allowance',
                'description'   => "{$row->full_name} has duplicate allowance '{$row->allowance_description}' appearing {$row->occurrence_count}× (total R{$row->total_amount}).",
                'severity'      => self::LEAKAGE_SEVERITY['duplicate_allowance'],
                'leakage_amount' => round((float) $row->total_amount - ((float) $row->total_amount / $row->occurrence_count), 2),
                'metadata'      => [
                    'allowance_description' => $row->allowance_description,
                    'occurrence_count'      => $row->occurrence_count,
                    'total_amount'          => $row->total_amount,
                    'expected_amount'       => round((float) $row->total_amount / $row->occurrence_count, 2),
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectDuplicateAllowances: ' . $e->getMessage());
            return [];
        }
    }

    // ── 2. Allowance exceeding 30% of basic ───────────────────────────────────

    public function detectAllowanceLeakage(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->where('ps.basic_salary', '>', 0)
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'ps.basic_salary',
                    'pel.amount',
                    'pel.description as allowance_description',
                )
                ->get();

            $anomalies = [];
            foreach ($rows as $row) {
                $threshold = (float) $row->basic_salary * 0.30;
                if ((float) $row->amount > $threshold) {
                    $excess = round((float) $row->amount - $threshold, 2);
                    $pct    = round(((float) $row->amount / (float) $row->basic_salary) * 100, 1);
                    $anomalies[] = [
                        'employee_id'    => $row->employee_id,
                        'employee_name'  => $row->full_name,
                        'anomaly_type'   => 'allowance_leakage',
                        'description'    => "{$row->full_name} allowance '{$row->allowance_description}' R{$row->amount} is {$pct}% of basic R{$row->basic_salary} (threshold: 30%).",
                        'severity'       => self::LEAKAGE_SEVERITY['allowance_leakage'],
                        'leakage_amount' => $excess,
                        'metadata'       => [
                            'allowance_description' => $row->allowance_description,
                            'allowance_amount'       => $row->amount,
                            'basic_salary'           => $row->basic_salary,
                            'threshold'              => round($threshold, 2),
                            'excess'                 => $excess,
                            'pct_of_basic'           => $pct,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectAllowanceLeakage: ' . $e->getMessage());
            return [];
        }
    }

    // ── 3. Benefits paid after termination ───────────────────────────────────

    public function detectBenefitLeakage(PayrollRun $run): array
    {
        try {
            // date_left is Unix timestamp (unsignedBigInteger on hr_people)
            $periodStart = $run->period_start->timestamp;

            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.date_left')
                ->where('hp.date_left', '>', 0)
                ->where('hp.date_left', '<', $periodStart)  // terminated before period started
                ->where('pel.line_type', '!=', 'basic_salary')
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'hp.date_left',
                    'pel.line_type',
                    'pel.amount',
                    'pel.description as benefit_description',
                )
                ->get();

            return $rows->map(function ($row) {
                $dateLeft = date('Y-m-d', $row->date_left);
                return [
                    'employee_id'    => $row->employee_id,
                    'employee_name'  => $row->full_name,
                    'anomaly_type'   => 'benefit_after_termination',
                    'description'    => "{$row->full_name} (terminated {$dateLeft}) is receiving '{$row->line_type}' R{$row->amount} after termination.",
                    'severity'       => self::LEAKAGE_SEVERITY['benefit_after_termination'],
                    'leakage_amount' => (float) $row->amount,
                    'metadata'       => [
                        'line_type'          => $row->line_type,
                        'benefit_description' => $row->benefit_description,
                        'amount'             => $row->amount,
                        'date_left'          => $dateLeft,
                    ],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('detectBenefitLeakage: ' . $e->getMessage());
            return [];
        }
    }

    // ── 4. Recurring payroll drift (cross-run, not tied to a single run) ──────

    public function detectRecurringPayrollDrift(int $months = 3): array
    {
        try {
            $since = now()->subMonths($months)->startOfMonth();

            $runIds = DB::table('payroll_runs')
                ->where('status', 'released')
                ->where('period_start', '>=', $since)
                ->orderBy('period_start')
                ->pluck('id');

            if ($runIds->count() < 2) {
                return [];
            }

            $payslips = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->whereIn('ps.payroll_run_id', $runIds)
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'ps.payroll_run_id',
                    'ps.net_pay',
                )
                ->orderBy('ps.payroll_run_id')
                ->get()
                ->groupBy('employee_id');

            $anomalies = [];
            foreach ($payslips as $userId => $slips) {
                if ($slips->count() < 2) {
                    continue;
                }
                $nets    = $slips->pluck('net_pay')->map(fn ($v) => (float) $v)->values();
                $maxDiff = 0.0;
                for ($i = 1; $i < $nets->count(); $i++) {
                    if ($nets[$i - 1] > 0) {
                        $diff    = abs($nets[$i] - $nets[$i - 1]) / $nets[$i - 1];
                        $maxDiff = max($maxDiff, $diff);
                    }
                }
                if ($maxDiff > 0.10) {
                    $name   = $slips->first()->full_name;
                    $avgNet = round($nets->avg(), 2);
                    $anomalies[] = [
                        'employee_id'    => $userId,
                        'employee_name'  => $name,
                        'anomaly_type'   => 'recurring_payroll_drift',
                        'description'    => "{$name} shows " . round($maxDiff * 100, 1) . "% month-over-month net pay variance over {$months} months (avg R{$avgNet}).",
                        'severity'       => self::LEAKAGE_SEVERITY['recurring_payroll_drift'],
                        'leakage_amount' => 0.0,
                        'metadata'       => ['max_variance_pct' => round($maxDiff * 100, 1), 'avg_net' => $avgNet, 'months' => $months],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectRecurringPayrollDrift: ' . $e->getMessage());
            return [];
        }
    }

    // ── 5. Suspiciously low deductions (net > 90% of gross) ──────────────────

    public function detectHistoricalOverpayments(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('ps.gross_pay', '>', 0)
                ->whereColumn('ps.net_pay', '>', DB::raw('ps.gross_pay * 0.9'))
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'ps.gross_pay',
                    'ps.net_pay',
                    'ps.total_deductions',
                )
                ->get();

            return $rows->map(function ($row) {
                $expectedMin = round((float) $row->gross_pay * 0.08, 2); // expect at least 8% deductions
                $shortfall   = round($expectedMin - (float) $row->total_deductions, 2);
                return [
                    'employee_id'    => $row->employee_id,
                    'employee_name'  => $row->full_name,
                    'anomaly_type'   => 'suspicious_low_deductions',
                    'description'    => "{$row->full_name} net R{$row->net_pay} is " . round(((float) $row->net_pay / (float) $row->gross_pay) * 100, 1) . "% of gross R{$row->gross_pay} — deductions appear too low.",
                    'severity'       => self::LEAKAGE_SEVERITY['suspicious_low_deductions'],
                    'leakage_amount' => max(0.0, $shortfall),
                    'metadata'       => [
                        'gross_pay'        => $row->gross_pay,
                        'net_pay'          => $row->net_pay,
                        'total_deductions' => $row->total_deductions,
                        'expected_min_deductions' => $expectedMin,
                        'shortfall'        => max(0.0, $shortfall),
                    ],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('detectHistoricalOverpayments: ' . $e->getMessage());
            return [];
        }
    }

    // ── Leakage estimate (dashboard cards) ───────────────────────────────────

    /**
     * Returns four dashboard card values:
     *   monthly_estimate  — total leakage_amount from open leakage anomalies this month
     *   annual_estimate   — monthly × 12
     *   top_sources       — top 5 anomaly types by total leakage
     *   department_breakdown — departments ranked by their share of total payroll
     */
    public function estimatePayrollLeakage(int $months = 1): array
    {
        try {
            $since  = now()->subMonths($months)->startOfMonth();
            $runIds = DB::table('payroll_runs')
                ->whereIn('status', ['released', 'review'])
                ->where('period_start', '>=', $since)
                ->pluck('id');

            // Sum leakage_amount from metadata for known leakage anomaly types
            $leakageTypes = array_keys(self::LEAKAGE_SEVERITY);
            $openAnomalies = DB::table('payroll_anomalies')
                ->whereIn('run_id', $runIds)
                ->whereIn('anomaly_type', $leakageTypes)
                ->whereIn('status', ['open', 'investigating'])
                ->select('anomaly_type', 'metadata')
                ->get();

            $monthlyEstimate = 0.0;
            $sourceAmounts   = [];

            foreach ($openAnomalies as $a) {
                $meta   = is_string($a->metadata) ? json_decode($a->metadata, true) : (array) $a->metadata;
                $amount = (float) ($meta['leakage_amount'] ?? $meta['excess'] ?? $meta['amount'] ?? 0);
                $monthlyEstimate        += $amount;
                $sourceAmounts[$a->anomaly_type] = ($sourceAmounts[$a->anomaly_type] ?? 0) + $amount;
            }

            arsort($sourceAmounts);
            $topSources = array_slice(
                array_map(fn ($type, $amt) => ['type' => $type, 'estimated_leakage' => round($amt, 2)], array_keys($sourceAmounts), $sourceAmounts),
                0, 5
            );

            // Department breakdown by payroll share
            $deptBreakdown = DB::table('payslips as ps')
                ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                ->join('company_units as cu', function ($j) {
                    $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
                })
                ->whereIn('ps.payroll_run_id', $runIds)
                ->select('cu.name as department', DB::raw('SUM(ps.net_pay) as total_net'), DB::raw('COUNT(DISTINCT ps.employee_id) as headcount'))
                ->groupBy('cu.name')
                ->orderByDesc('total_net')
                ->limit(10)
                ->get()
                ->toArray();

            return [
                'monthly_estimate'     => round($monthlyEstimate, 2),
                'annual_estimate'      => round($monthlyEstimate * 12, 2),
                'top_sources'          => $topSources,
                'department_breakdown' => $deptBreakdown,
            ];
        } catch (\Throwable $e) {
            Log::error('estimatePayrollLeakage: ' . $e->getMessage());
            return [
                'monthly_estimate'     => 0.0,
                'annual_estimate'      => 0.0,
                'top_sources'          => [],
                'department_breakdown' => [],
            ];
        }
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function saveResults(PayrollRun $run, array $results): int
    {
        $saved = 0;
        $now   = now();

        foreach ($results as $r) {
            $existing = PayrollAnomaly::where('run_id', $run->id)
                ->where('anomaly_type', $r['anomaly_type'])
                ->where('employee_id', $r['employee_id'] ?? null)
                ->first();

            if ($existing) {
                continue;
            }

            $meta = $r['metadata'] ?? [];
            if (isset($r['leakage_amount'])) {
                $meta['leakage_amount'] = $r['leakage_amount'];
            }

            PayrollAnomaly::create([
                'uuid'         => Str::uuid(),
                'run_id'       => $run->id,
                'employee_id'  => $r['employee_id'] ?? null,
                'anomaly_type' => $r['anomaly_type'],
                'description'  => $r['description'],
                'severity'     => $r['severity'],
                'status'       => 'open',
                'detected_at'  => $now,
                'metadata'     => $meta ?: null,
            ]);
            $saved++;
        }

        return $saved;
    }
}
