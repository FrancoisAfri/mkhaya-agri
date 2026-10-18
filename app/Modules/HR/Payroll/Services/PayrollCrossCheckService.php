<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payroll Cross-Check Engine (Phase 3)
 *
 * Bridges payslips (employee_id = users.id) to attendance/leave
 * (employee_id = hr_people.id) via hr_people.user_id = payslips.employee_id.
 *
 * All results are saved to payroll_anomalies so they surface in the
 * Anomaly Firewall UI alongside Phase 2 findings.
 */
class PayrollCrossCheckService
{
    // ── Severity map ──────────────────────────────────────────────────────────

    private const SEVERITY = [
        'zero_attendance'              => 'medium',
        'unpaid_leave_full_pay'        => 'high',
        'unpaid_leave_in_period'       => 'medium',
        'excessive_absence_no_leave'   => 'high',
        'overtime_claim_mismatch'      => 'high',
        'contract_ends_in_period'      => 'medium',
        'non_active_employee_in_payroll' => 'high',
        'allowance_with_zero_basic'    => 'medium',
        'acting_allowance_ineligible'  => 'medium',
        'shift_allowance_ineligible'   => 'low',
    ];

    // ── 1. Attendance vs Payroll ───────────────────────────────────────────────

    public function crossCheckAttendanceWithPayroll(PayrollRun $run): array
    {
        try {
            // All employees on the run (employee_id = users.id)
            $employeeUserIds = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->pluck('employee_id');

            // hr_people IDs that have attendance in the period
            $attendedHrIds = DB::table('attendance_records')
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->pluck('employee_id')    // hr_people.id
                ->unique();

            // Map payslip user_id → hr_people.id
            $hrPeople = DB::table('hr_people')
                ->whereIn('user_id', $employeeUserIds)
                ->select('id', 'user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
                ->get()
                ->keyBy('user_id');

            $anomalies = [];
            foreach ($employeeUserIds as $userId) {
                $person = $hrPeople[$userId] ?? null;
                if (!$person) {
                    continue;
                }
                if (!$attendedHrIds->contains($person->id)) {
                    $anomalies[] = [
                        'employee_id'  => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type' => 'zero_attendance',
                        'description'  => "{$person->full_name} is in payroll but has zero attendance records for the period.",
                        'severity'     => self::SEVERITY['zero_attendance'],
                        'metadata'     => ['period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString()],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('crossCheckAttendanceWithPayroll: ' . $e->getMessage());
            return [];
        }
    }

    // ── 2. Leave vs Payroll (unpaid leave receiving full pay) ─────────────────

    public function crossCheckLeaveWithPayroll(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->join('leave_requests as lr', 'lr.employee_id', '=', 'hp.id')
                ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('lr.status', 'approved')
                ->where('lt.is_paid', false)
                ->where('lr.start_date', '<=', $run->period_end->toDateString())
                ->where('lr.end_date', '>=', $run->period_start->toDateString())
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'lt.name as leave_type',
                    'lr.start_date',
                    'lr.end_date',
                    'lr.working_days',
                    'ps.basic_salary',
                )
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->full_name,
                'anomaly_type'  => 'unpaid_leave_full_pay',
                'description'   => "{$row->full_name} is on approved unpaid leave ({$row->leave_type}, {$row->start_date}–{$row->end_date}) but is receiving full pay.",
                'severity'      => self::SEVERITY['unpaid_leave_full_pay'],
                'metadata'      => [
                    'leave_type'   => $row->leave_type,
                    'start_date'   => $row->start_date,
                    'end_date'     => $row->end_date,
                    'working_days' => $row->working_days,
                    'basic_salary' => $row->basic_salary,
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('crossCheckLeaveWithPayroll: ' . $e->getMessage());
            return [];
        }
    }

    // ── 3. Unpaid leave deduction check ───────────────────────────────────────

    public function crossCheckUnpaidLeave(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->join('leave_requests as lr', 'lr.employee_id', '=', 'hp.id')
                ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('lr.status', 'approved')
                ->where('lt.is_paid', false)
                ->where('lr.start_date', '<=', $run->period_end->toDateString())
                ->where('lr.end_date', '>=', $run->period_start->toDateString())
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'lt.name as leave_type',
                    'lr.start_date',
                    'lr.end_date',
                    'lr.working_days',
                    'ps.basic_salary',
                )
                ->get();

            return $rows->map(function ($row) {
                $dailyRate   = (float) $row->basic_salary / 22;
                $deductionAmt = round($dailyRate * (float) ($row->working_days ?? 0), 2);
                return [
                    'employee_id'   => $row->employee_id,
                    'employee_name' => $row->full_name,
                    'anomaly_type'  => 'unpaid_leave_in_period',
                    'description'   => "{$row->full_name} has {$row->working_days} day(s) unpaid leave ({$row->leave_type}). Estimated deduction: R{$deductionAmt}.",
                    'severity'      => self::SEVERITY['unpaid_leave_in_period'],
                    'metadata'      => [
                        'leave_type'       => $row->leave_type,
                        'start_date'       => $row->start_date,
                        'end_date'         => $row->end_date,
                        'working_days'     => $row->working_days,
                        'estimated_deduction' => $deductionAmt,
                    ],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('crossCheckUnpaidLeave: ' . $e->getMessage());
            return [];
        }
    }

    // ── 4. Excessive absence without leave record ─────────────────────────────

    public function crossCheckAbsenceRecords(PayrollRun $run): array
    {
        try {
            $employeeUserIds = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->pluck('employee_id');

            $hrPeople = DB::table('hr_people')
                ->whereIn('user_id', $employeeUserIds)
                ->select('id', 'user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
                ->get()
                ->keyBy('user_id');

            $hrIds = $hrPeople->pluck('id');

            // Days present per hr_people.id
            $attendanceCounts = DB::table('attendance_records')
                ->whereIn('employee_id', $hrIds)
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->select('employee_id', DB::raw('COUNT(*) as days_present'))
                ->groupBy('employee_id')
                ->get()
                ->keyBy('employee_id');

            // hr_people IDs with any approved leave in the period
            $onLeaveHrIds = DB::table('leave_requests')
                ->whereIn('employee_id', $hrIds)
                ->where('status', 'approved')
                ->where('start_date', '<=', $run->period_end->toDateString())
                ->where('end_date', '>=', $run->period_start->toDateString())
                ->pluck('employee_id')
                ->unique();

            $workingDays = $run->period_start->diffInWeekdays($run->period_end) + 1;

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                $daysPresent = (int) ($attendanceCounts[$person->id]->days_present ?? 0);
                $daysAbsent  = $workingDays - $daysPresent;
                if ($daysAbsent > 5 && !$onLeaveHrIds->contains($person->id)) {
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'excessive_absence_no_leave',
                        'description'   => "{$person->full_name} was absent {$daysAbsent} day(s) with no approved leave in the period.",
                        'severity'      => self::SEVERITY['excessive_absence_no_leave'],
                        'metadata'      => ['working_days' => $workingDays, 'days_present' => $daysPresent, 'days_absent' => $daysAbsent],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('crossCheckAbsenceRecords: ' . $e->getMessage());
            return [];
        }
    }

    // ── 5. Overtime claims vs attendance records ──────────────────────────────

    public function crossCheckOvertimeClaims(PayrollRun $run): array
    {
        try {
            $overtimeLines = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'overtime')
                ->select(
                    'ps.employee_id',
                    DB::raw('SUM(pel.amount) as overtime_amount'),
                    DB::raw('SUM(pel.quantity) as claimed_hours'),
                )
                ->groupBy('ps.employee_id')
                ->get();

            if ($overtimeLines->isEmpty()) {
                return [];
            }

            $userIds = $overtimeLines->pluck('employee_id');
            $hrPeople = DB::table('hr_people')
                ->whereIn('user_id', $userIds)
                ->select('id', 'user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
                ->get()
                ->keyBy('user_id');

            $anomalies = [];
            foreach ($overtimeLines as $row) {
                $person          = $hrPeople[$row->employee_id] ?? null;
                $name            = $person ? $person->full_name : "Employee #{$row->employee_id}";
                $attendedOvertime = 0.0;

                if ($person) {
                    $attendedOvertime = (float) DB::table('attendance_records')
                        ->where('employee_id', $person->id)
                        ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                        ->sum('overtime_hours');
                }

                $claimed = (float) $row->claimed_hours;
                if ($claimed > $attendedOvertime + 0.01) {
                    $anomalies[] = [
                        'employee_id'   => $row->employee_id,
                        'employee_name' => $name,
                        'anomaly_type'  => 'overtime_claim_mismatch',
                        'description'   => "{$name} claimed {$claimed}h overtime but attendance shows {$attendedOvertime}h.",
                        'severity'      => self::SEVERITY['overtime_claim_mismatch'],
                        'metadata'      => [
                            'claimed_hours'    => $claimed,
                            'attended_hours'   => $attendedOvertime,
                            'overtime_amount'  => $row->overtime_amount,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('crossCheckOvertimeClaims: ' . $e->getMessage());
            return [];
        }
    }

    // ── 6. Contract / termination end date ────────────────────────────────────

    public function crossCheckContractEndDate(PayrollRun $run): array
    {
        try {
            // date_left is stored as a Unix timestamp (unsignedBigInteger)
            $periodStart = $run->period_start->timestamp;
            $periodEnd   = $run->period_end->timestamp;

            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.date_left')
                ->where('hp.date_left', '>', 0)
                ->whereBetween('hp.date_left', [$periodStart, $periodEnd])
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'hp.date_left',
                )
                ->get();

            return $rows->map(function ($row) {
                $dateLeft = date('Y-m-d', $row->date_left);
                return [
                    'employee_id'   => $row->employee_id,
                    'employee_name' => $row->full_name,
                    'anomaly_type'  => 'contract_ends_in_period',
                    'description'   => "{$row->full_name} contract/termination date ({$dateLeft}) falls within the payroll period.",
                    'severity'      => self::SEVERITY['contract_ends_in_period'],
                    'metadata'      => ['date_left' => $dateLeft],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('crossCheckContractEndDate: ' . $e->getMessage());
            return [];
        }
    }

    // ── 7. Employee status check ──────────────────────────────────────────────

    public function crossCheckEmployeeStatus(PayrollRun $run): array
    {
        try {
            // hr_people.status is smallint: 1 = active
            $rows = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where(fn ($q) => $q->whereNull('hp.status')->orWhere('hp.status', '!=', 1))
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'hp.status',
                )
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->full_name,
                'anomaly_type'  => 'non_active_employee_in_payroll',
                'description'   => "{$row->full_name} has HR status {$row->status} (not active) but is included in payroll.",
                'severity'      => self::SEVERITY['non_active_employee_in_payroll'],
                'metadata'      => ['hr_status' => $row->status],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('crossCheckEmployeeStatus: ' . $e->getMessage());
            return [];
        }
    }

    // ── 8. Allowance with zero basic salary ───────────────────────────────────

    public function crossCheckAllowances(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->where('ps.basic_salary', 0)
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'pel.amount',
                    'pel.description as allowance_description',
                )
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->full_name,
                'anomaly_type'  => 'allowance_with_zero_basic',
                'description'   => "{$row->full_name} has allowance R{$row->amount} ({$row->allowance_description}) with zero basic salary.",
                'severity'      => self::SEVERITY['allowance_with_zero_basic'],
                'metadata'      => ['allowance_amount' => $row->amount, 'allowance_description' => $row->allowance_description],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('crossCheckAllowances: ' . $e->getMessage());
            return [];
        }
    }

    // ── 9. Acting allowance eligibility ──────────────────────────────────────

    public function crossCheckActingAllowances(PayrollRun $run): array
    {
        try {
            // Flag acting allowances for employees not acting as someone's manager
            $actingLines = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->whereRaw("LOWER(pel.description) LIKE '%acting%'")
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'hp.id as hr_person_id',
                    'pel.amount',
                    'pel.description as allowance_description',
                )
                ->get();

            if ($actingLines->isEmpty()) {
                return [];
            }

            // hr_people IDs that are listed as managers for at least one active employee
            $managingHrIds = DB::table('hr_people')
                ->whereIn('manager_id', $actingLines->pluck('hr_person_id'))
                ->where('status', 1)
                ->pluck('manager_id')
                ->unique();

            $anomalies = [];
            foreach ($actingLines as $row) {
                if (!$managingHrIds->contains($row->hr_person_id)) {
                    $anomalies[] = [
                        'employee_id'   => $row->employee_id,
                        'employee_name' => $row->full_name,
                        'anomaly_type'  => 'acting_allowance_ineligible',
                        'description'   => "{$row->full_name} is receiving acting allowance R{$row->amount} but is not listed as acting manager for any active employee.",
                        'severity'      => self::SEVERITY['acting_allowance_ineligible'],
                        'metadata'      => ['allowance_amount' => $row->amount, 'allowance_description' => $row->allowance_description],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('crossCheckActingAllowances: ' . $e->getMessage());
            return [];
        }
    }

    // ── 10. Shift allowance eligibility ──────────────────────────────────────

    public function crossCheckShiftAllowances(PayrollRun $run): array
    {
        try {
            // Employees claiming shift allowance
            $shiftLines = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->whereRaw("LOWER(pel.description) LIKE '%shift%'")
                ->select(
                    'ps.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS full_name"),
                    'hp.id as hr_person_id',
                    'pel.amount',
                    'pel.description as allowance_description',
                )
                ->get();

            if ($shiftLines->isEmpty()) {
                return [];
            }

            // hr_people IDs that have at least one attendance record with a shift_id in the period
            $hrIdsWithShiftAttendance = DB::table('attendance_records')
                ->whereIn('employee_id', $shiftLines->pluck('hr_person_id'))
                ->whereNotNull('shift_id')
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->pluck('employee_id')
                ->unique();

            $anomalies = [];
            foreach ($shiftLines as $row) {
                if (!$hrIdsWithShiftAttendance->contains($row->hr_person_id)) {
                    $anomalies[] = [
                        'employee_id'   => $row->employee_id,
                        'employee_name' => $row->full_name,
                        'anomaly_type'  => 'shift_allowance_ineligible',
                        'description'   => "{$row->full_name} claims shift allowance R{$row->amount} but has no shift-linked attendance records in the period.",
                        'severity'      => self::SEVERITY['shift_allowance_ineligible'],
                        'metadata'      => ['allowance_amount' => $row->amount, 'allowance_description' => $row->allowance_description],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('crossCheckShiftAllowances: ' . $e->getMessage());
            return [];
        }
    }

    // ── Orchestrator ──────────────────────────────────────────────────────────

    public function runAllCrossChecks(PayrollRun $run): int
    {
        $results = array_merge(
            $this->crossCheckAttendanceWithPayroll($run),
            $this->crossCheckLeaveWithPayroll($run),
            $this->crossCheckUnpaidLeave($run),
            $this->crossCheckAbsenceRecords($run),
            $this->crossCheckOvertimeClaims($run),
            $this->crossCheckContractEndDate($run),
            $this->crossCheckEmployeeStatus($run),
            $this->crossCheckAllowances($run),
            $this->crossCheckActingAllowances($run),
            $this->crossCheckShiftAllowances($run),
        );

        return $this->saveResults($run, $results);
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

            PayrollAnomaly::create([
                'uuid'        => Str::uuid(),
                'run_id'      => $run->id,
                'employee_id' => $r['employee_id'] ?? null,
                'anomaly_type' => $r['anomaly_type'],
                'description'  => $r['description'],
                'severity'     => $r['severity'],
                'status'       => 'open',
                'detected_at'  => $now,
                'metadata'     => $r['metadata'] ?? null,
            ]);
            $saved++;
        }

        return $saved;
    }

    // ── Summary for a run (used by controller index) ──────────────────────────

    public function getSummaryForRun(PayrollRun $run): array
    {
        return DB::table('payroll_anomalies')
            ->where('run_id', $run->id)
            ->whereIn('anomaly_type', array_keys(self::SEVERITY))
            ->select('anomaly_type', 'severity', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('anomaly_type', 'severity', 'status')
            ->get()
            ->toArray();
    }
}
