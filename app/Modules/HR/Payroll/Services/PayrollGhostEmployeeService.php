<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ghost Employee Detection Engine (Phase 5)
 *
 * A ghost employee is someone drawing a salary but showing no real operational
 * footprint. Detection uses five independent signals:
 *   • Attendance   — no records for the payroll period (hr_people bridge)
 *   • System login — no session in the period (sessions.last_activity)
 *   • System activity — no activity_log entries in the period
 *   • Leave/activity — no leave requests AND no attendance at all
 *   • Manager interaction — manager never approved leave or attendance for this employee
 *
 * Composite ghost (all signals absent) = critical.
 * Individual signal absences = medium/high.
 */
class PayrollGhostEmployeeService
{
    // ── Severity map ──────────────────────────────────────────────────────────

    private const SEVERITY = [
        'ghost_employee'                      => 'critical',
        'ghost_employee_no_attendance'        => 'high',
        'ghost_employee_no_system_activity'   => 'medium',
        'ghost_employee_no_leave_activity'    => 'medium',
        'ghost_employee_no_manager_interaction' => 'medium',
    ];

    // ── Orchestrator ──────────────────────────────────────────────────────────

    public function detectGhostEmployees(PayrollRun $run): array
    {
        // Some signals rely on OTHER modules' data (attendance, leave). If the tenant
        // has not purchased those modules there is simply no data, which would flag
        // EVERY employee as a ghost — a false positive. Only run a cross-module signal
        // when the tenant is actually entitled to that module.
        $hasAttendance = $this->moduleAvailable('time_attendance');
        $hasLeave      = $this->moduleAvailable('leave_management');

        $signals = [
            $this->detectNoSystemActivityEmployees($run),          // core (logins/sessions) — always available
            $this->detectPayrollWithoutOperationalPresence($run),  // payroll-native — always available
        ];

        if ($hasAttendance) {
            $signals[] = $this->detectNoAttendanceEmployees($run);
        }
        if ($hasLeave) {
            $signals[] = $this->detectNoLeaveActivityEmployees($run);
        }
        // Manager-interaction is derived from leave/attendance approvals — only meaningful
        // when at least one of those modules is present.
        if ($hasAttendance || $hasLeave) {
            $signals[] = $this->detectNoManagerInteraction($run);
        }

        return array_merge(...$signals);
    }

    /** True when a module is installed AND the current tenant's plan/licence includes it. */
    private function moduleAvailable(string $code): bool
    {
        $installed = DB::table('security_modules')
            ->where('code_name', $code)->where('active', 1)->exists();

        return $installed
            && app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentAllowsModule($code);
    }

    public function runGhostDetection(PayrollRun $run): int
    {
        $results = $this->detectGhostEmployees($run);
        return $this->saveResults($run, $results);
    }

    // ── Helper: resolve payslip employee_ids → hr_people records ─────────────

    private function getHrPeopleForRun(PayrollRun $run): \Illuminate\Support\Collection
    {
        $userIds = DB::table('payslips')
            ->where('payroll_run_id', $run->id)
            ->pluck('employee_id');  // users.id

        return DB::table('hr_people')
            ->whereIn('user_id', $userIds)
            ->select('id', 'user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"), 'manager_id')
            ->get()
            ->keyBy('user_id');  // keyed by users.id for easy lookup
    }

    // ── 1. No attendance records in payroll period ────────────────────────────

    public function detectNoAttendanceEmployees(PayrollRun $run): array
    {
        try {
            $hrPeople = $this->getHrPeopleForRun($run);
            if ($hrPeople->isEmpty()) {
                return [];
            }

            // hr_people IDs with attendance in period
            $withAttendanceHrIds = DB::table('attendance_records')
                ->whereIn('employee_id', $hrPeople->pluck('id'))
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->pluck('employee_id')
                ->unique();

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                if (!$withAttendanceHrIds->contains($person->id)) {
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'ghost_employee_no_attendance',
                        'description'   => "{$person->full_name} is in payroll but has zero attendance records for the period.",
                        'severity'      => self::SEVERITY['ghost_employee_no_attendance'],
                        'metadata'      => ['signal' => 'no_attendance', 'period' => $run->period_start->toDateString() . '–' . $run->period_end->toDateString()],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectNoAttendanceEmployees: ' . $e->getMessage());
            return [];
        }
    }

    // ── 2. No system activity (activity_log) in payroll period ───────────────

    public function detectNoSystemActivityEmployees(PayrollRun $run): array
    {
        try {
            $hrPeople = $this->getHrPeopleForRun($run);
            if ($hrPeople->isEmpty()) {
                return [];
            }

            $userIds = $hrPeople->pluck('user_id');

            // activity_log.causer_id = users.id, causer_type = App\Models\User
            $withActivity = DB::table('activity_log')
                ->whereIn('causer_id', $userIds)
                ->where('causer_type', 'LIKE', '%User%')
                ->whereBetween('created_at', [
                    $run->period_start->toDateTimeString(),
                    $run->period_end->endOfDay()->toDateTimeString(),
                ])
                ->pluck('causer_id')
                ->unique();

            // Also check sessions table (last_activity is Unix timestamp)
            $periodEndTs    = $run->period_end->endOfDay()->timestamp;
            $periodStartTs  = $run->period_start->timestamp;
            $withSession = DB::table('sessions')
                ->whereIn('user_id', $userIds)
                ->whereBetween('last_activity', [$periodStartTs, $periodEndTs])
                ->pluck('user_id')
                ->unique();

            $withAnyActivity = $withActivity->merge($withSession)->unique();

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                if (!$withAnyActivity->contains($userId)) {
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'ghost_employee_no_system_activity',
                        'description'   => "{$person->full_name} has no system login or activity entries during the payroll period.",
                        'severity'      => self::SEVERITY['ghost_employee_no_system_activity'],
                        'metadata'      => ['signals_checked' => ['activity_log', 'sessions']],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectNoSystemActivityEmployees: ' . $e->getMessage());
            return [];
        }
    }

    // ── 3. No leave records AND no attendance activity ────────────────────────

    public function detectNoLeaveActivityEmployees(PayrollRun $run): array
    {
        try {
            $hrPeople = $this->getHrPeopleForRun($run);
            if ($hrPeople->isEmpty()) {
                return [];
            }

            $hrIds   = $hrPeople->pluck('id');
            $userIds = $hrPeople->pluck('user_id');

            // Has any leave request in the period
            $withLeaveHrIds = DB::table('leave_requests')
                ->whereIn('employee_id', $hrIds)
                ->where('start_date', '<=', $run->period_end->toDateString())
                ->where('end_date', '>=', $run->period_start->toDateString())
                ->pluck('employee_id')
                ->unique();

            // Has any attendance record in period
            $withAttendanceHrIds = DB::table('attendance_records')
                ->whereIn('employee_id', $hrIds)
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->pluck('employee_id')
                ->unique();

            $withAnyRecord = $withLeaveHrIds->merge($withAttendanceHrIds)->unique();

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                if (!$withAnyRecord->contains($person->id)) {
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'ghost_employee_no_leave_activity',
                        'description'   => "{$person->full_name} has no leave requests and no attendance records for the period.",
                        'severity'      => self::SEVERITY['ghost_employee_no_leave_activity'],
                        'metadata'      => ['signals_checked' => ['leave_requests', 'attendance_records']],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectNoLeaveActivityEmployees: ' . $e->getMessage());
            return [];
        }
    }

    // ── 4. No manager interaction (past 90 days) ──────────────────────────────

    public function detectNoManagerInteraction(PayrollRun $run): array
    {
        try {
            $hrPeople = $this->getHrPeopleForRun($run);
            if ($hrPeople->isEmpty()) {
                return [];
            }

            $lookback = $run->period_end->copy()->subDays(90)->toDateString();

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                if (!$person->manager_id) {
                    continue; // Skip employees without an assigned manager
                }

                // Signal A: manager approved any leave for this employee
                $managerApprovedLeave = DB::table('leave_requests')
                    ->where('employee_id', $person->id)
                    ->whereIn('status', ['approved', 'pending_hr', 'pending_payroll'])
                    ->where('current_approver_id', $person->manager_id)
                    ->where('start_date', '>=', $lookback)
                    ->exists();

                // Signal B: manager approved any attendance record
                $managerApprovedAttendance = DB::table('attendance_records')
                    ->where('employee_id', $person->id)
                    ->where('approved_by', $person->manager_id)
                    ->where('date', '>=', $lookback)
                    ->exists();

                // Signal C: manager has any activity_log entry referencing this employee's user record
                $managerHasActivity = DB::table('activity_log')
                    ->where('causer_id', $person->manager_id)
                    ->where('causer_type', 'LIKE', '%User%')
                    ->where(function ($q) use ($userId) {
                        $q->where('subject_id', $userId)->orWhereJsonContains('properties->attributes->employee_id', $userId);
                    })
                    ->where('created_at', '>=', $lookback)
                    ->exists();

                if (!$managerApprovedLeave && !$managerApprovedAttendance && !$managerHasActivity) {
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'ghost_employee_no_manager_interaction',
                        'description'   => "{$person->full_name} has had no manager interaction (leave approvals, attendance sign-offs, activity) in the last 90 days.",
                        'severity'      => self::SEVERITY['ghost_employee_no_manager_interaction'],
                        'metadata'      => ['manager_id' => $person->manager_id, 'lookback_days' => 90, 'lookback_from' => $lookback],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectNoManagerInteraction: ' . $e->getMessage());
            return [];
        }
    }

    // ── 5. Composite: zero operational presence across all signals ────────────

    public function detectPayrollWithoutOperationalPresence(PayrollRun $run): array
    {
        try {
            $hrPeople = $this->getHrPeopleForRun($run);
            if ($hrPeople->isEmpty()) {
                return [];
            }

            $hrIds   = $hrPeople->pluck('id');
            $userIds = $hrPeople->pluck('user_id');

            // Signal 1: attendance
            $withAttendanceHrIds = DB::table('attendance_records')
                ->whereIn('employee_id', $hrIds)
                ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
                ->pluck('employee_id')->unique();

            // Signal 2: system activity
            $withActivityUserIds = DB::table('activity_log')
                ->whereIn('causer_id', $userIds)
                ->where('causer_type', 'LIKE', '%User%')
                ->whereBetween('created_at', [
                    $run->period_start->toDateTimeString(),
                    $run->period_end->endOfDay()->toDateTimeString(),
                ])
                ->pluck('causer_id')->unique();

            // Signal 3: session login
            $withSessionUserIds = DB::table('sessions')
                ->whereIn('user_id', $userIds)
                ->whereBetween('last_activity', [
                    $run->period_start->timestamp,
                    $run->period_end->endOfDay()->timestamp,
                ])
                ->pluck('user_id')->unique();

            // Signal 4: leave records
            $withLeaveHrIds = DB::table('leave_requests')
                ->whereIn('employee_id', $hrIds)
                ->where('start_date', '<=', $run->period_end->toDateString())
                ->where('end_date', '>=', $run->period_start->toDateString())
                ->pluck('employee_id')->unique();

            $anomalies = [];
            foreach ($hrPeople as $userId => $person) {
                $hasAttendance = $withAttendanceHrIds->contains($person->id);
                $hasActivity   = $withActivityUserIds->contains($userId);
                $hasSession    = $withSessionUserIds->contains($userId);
                $hasLeave      = $withLeaveHrIds->contains($person->id);

                if (!$hasAttendance && !$hasActivity && !$hasSession && !$hasLeave) {
                    $signalsSummary = [
                        'attendance'      => false,
                        'system_activity' => false,
                        'session_login'   => false,
                        'leave_records'   => false,
                    ];
                    $anomalies[] = [
                        'employee_id'   => $userId,
                        'employee_name' => $person->full_name,
                        'anomaly_type'  => 'ghost_employee',
                        'description'   => "{$person->full_name} has zero attendance, system activity, session login, and leave records — suspected ghost employee.",
                        'severity'      => self::SEVERITY['ghost_employee'],
                        'metadata'      => ['signals_absent' => $signalsSummary],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectPayrollWithoutOperationalPresence: ' . $e->getMessage());
            return [];
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

            PayrollAnomaly::create([
                'uuid'         => Str::uuid(),
                'run_id'       => $run->id,
                'employee_id'  => $r['employee_id'] ?? null,
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

    // ── Summary for dashboard ─────────────────────────────────────────────────

    public function getSummary(): array
    {
        $types = array_keys(self::SEVERITY);
        $row = DB::table('payroll_anomalies')
            ->whereIn('anomaly_type', $types)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'open')        AS total_open,
                COUNT(*) FILTER (WHERE anomaly_type = 'ghost_employee') AS total_confirmed,
                COUNT(*) FILTER (WHERE severity = 'high')      AS total_high,
                COUNT(*) FILTER (WHERE status = 'resolved')    AS total_resolved
            ")
            ->first();

        return $row ? (array) $row : [
            'total_open'      => 0,
            'total_confirmed' => 0,
            'total_high'      => 0,
            'total_resolved'  => 0,
        ];
    }
}
