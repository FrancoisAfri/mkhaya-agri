<?php

namespace App\Modules\HR\Hr\Services;

use App\Modules\HR\Hr\Models\Attendance\AttendanceAbsenceExemption;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAbsentUser;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use Carbon\Carbon;
use ErrorException;
use GuzzleHttp;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReadErsDetails
{
    /**
     * Is the ERS integration switched on for this tenant?
     *
     * Every entry point checks this. Without it the "Enable ERS Biometric
     * Integration" switch on the settings screen was decorative: the nightly
     * commands called the API regardless, and on a tenant that had never
     * configured a token they simply threw "ERS token not configured" every
     * night — which is exactly what "the integration isn't working" looks like.
     */
    public function isEnabled(): bool
    {
        return (bool) AttendanceSetting::current()->ers_enabled;
    }

    // ── Connect to ERS biometric API ─────────────────────────────────────────

    /**
     * @throws ErrorException
     * @throws GuzzleException
     */
    public function connectToErs(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $settings = AttendanceSetting::current();

        $token = $settings->ers_token;
        if (empty($token)) {
            $token = DB::table('leave_configuration')->value('ers_token_number');
        }

        if (empty($token)) {
            throw new ErrorException('ERS token not configured. Please add it in Attendance Settings → ERS Integration.');
        }

        $startTime = substr($settings->ers_clock_start_time ?? '05:00:00', 0, 8);
        $endTime   = substr($settings->ers_clock_end_time   ?? '20:00:00', 0, 8);

        $dateFrom = $dateFrom ?? Carbon::today()->format('Y/m/d') . ' ' . $startTime;
        $dateTo   = $dateTo   ?? Carbon::today()->format('Y/m/d') . ' ' . $endTime;

        $baseUrl = rtrim($settings->ers_api_url ?? 'https://r14.ersbio.co.za/api/data_client.php', '?');

        $url = $baseUrl . '?'
            . 't=' . $token
            . '&to_do=get_clocks'
            . '&imei=0'
            . '&last_id=1'
            . '&date_from=' . urlencode($dateFrom)
            . '&date_to=' . urlencode($dateTo)
            . '&export=0'
            . '&display=2';

        $client = new GuzzleHttp\Client(['timeout' => 30]);
        $body   = $client->request('GET', $url)->getBody()->getContents();

        return json_decode($body, true) ?? [];
    }

    // ── Pull today's clock data and save to attendance_records ───────────────

    /**
     * @throws GuzzleException
     * @throws ErrorException
     */
    public function getTimeAndAttendance(): void
    {
        if (! $this->isEnabled()) {
            Log::info('ERS Sync: skipped — ERS integration is disabled for this company.');
            return;
        }

        $resp = $this->connectToErs();

        $clockData = $resp['Raw_Clocks'] ?? [];
        if (empty($clockData)) {
            Log::info('ERS Sync: No clock-in data returned for today.');
            return;
        }

        $settings = AttendanceSetting::current();
        $grouped  = collect($clockData)->groupBy('Employee_Pin');

        $grouped->each(function ($records, $pin) use ($settings) {
            $sorted     = $records->sortBy('Clocked');
            $firstClock = $sorted->first();
            $lastClock  = $sorted->last();

            $employee = HRPerson::where('employee_number', $pin)->first();
            if (! $employee) {
                Log::warning("ERS Sync: No HR employee found for pin {$pin}");
                return;
            }

            $clockIn     = Carbon::parse($firstClock['Clocked']);
            $clockOut    = Carbon::parse($lastClock['Clocked']);
            $hoursWorked = round($clockIn->diffInMinutes($clockOut) / 60, 2);
            $otHours     = max(0, $hoursWorked - ($settings->working_hours_per_day ?? 8));
            $isLate      = $this->isLate($clockIn, $employee, $settings);
            $isEarly     = $this->earlyLeft($clockOut, $employee, $settings);

            AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $clockIn->toDateString()],
                [
                    'clock_in'                => $clockIn,
                    'clock_out'               => $clockOut,
                    'hours_worked'            => $hoursWorked,
                    'overtime_hours'          => $otHours,
                    'is_late'                 => $isLate,
                    'late_minutes'            => $isLate ? $this->lateMinutes($clockIn, $employee, $settings) : 0,
                    'is_early_departure'      => $isEarly,
                    'early_departure_minutes' => $isEarly ? $this->earlyMinutes($clockOut, $employee, $settings) : 0,
                    'source'                  => 'biometric',
                ]
            );
        });

        Log::info('ERS Sync: Processed ' . $grouped->count() . ' employee clock records.');
    }

    // ── Detect absent users, send reminder emails ────────────────────────────

    /**
     * @throws GuzzleException
     * @throws ErrorException
     */
    public function getErsDetails(): void
    {
        if (! $this->isEnabled()) {
            Log::info('ERS: absence detection skipped — ERS integration is disabled for this company.');
            return;
        }

        if ($this->isPublicHoliday()) {
            Log::info('ERS: Skipping absent detection – today is a public holiday.');
            return;
        }

        $absentEmployeeIds = $this->getAbsentEmployeeIds();
        $settings          = AttendanceSetting::current();
        $today             = Carbon::today()->toDateString();

        foreach ($absentEmployeeIds as $employeeId) {
            $employee = HRPerson::find($employeeId);
            if (! $employee) continue;

            $onLeave = $this->isOnLeave($employeeId);

            // Record the absence
            AttendanceAbsentUser::updateOrCreate(
                ['employee_id' => $employeeId, 'date' => $today],
                ['is_applied' => (bool) $onLeave, 'source' => 'ers']
            );

            // Save attendance record
            AttendanceRecord::updateOrCreate(
                ['employee_id' => $employeeId, 'date' => $today],
                [
                    'clock_in'       => null,
                    'clock_out'      => null,
                    'hours_worked'   => 0,
                    'overtime_hours' => 0,
                    'is_late'        => false,
                    'source'         => 'biometric',
                    'notes'          => $onLeave
                        ? 'On approved leave'
                        : 'Absent — no clock-in detected via ERS',
                ]
            );

            // Send absence notification (in-app bell + email) to absent employees not on leave
            if (! $onLeave && $settings->absent_email_enabled) {
                try {
                    app(AttendanceNotificationService::class)->absent($employeeId, $today);
                    AttendanceAbsentUser::where('employee_id', $employeeId)
                        ->where('date', $today)
                        ->update(['email_sent' => true]);
                    Log::info("ERS: Absence notification sent for employee #{$employeeId}");
                } catch (\Throwable $e) {
                    Log::error("ERS: Absence notification failed for employee #{$employeeId}: " . $e->getMessage());
                }
            }
        }

        // Trigger auto-apply if enabled
        if ($settings->auto_apply_leave_enabled) {
            $this->applyLeaveForUser();
        }
    }

    // ── Resolve which employees are absent today ──────────────────────────────

    /**
     * @throws GuzzleException
     * @throws ErrorException
     */
    public function getAbsentEmployeeIds(): array
    {
        $resp = $this->connectToErs();

        $clockedPins = collect();
        foreach ($resp as $group) {
            if (is_array($group)) {
                foreach ($group as $entry) {
                    if (isset($entry['Employee_Pin'])) {
                        $clockedPins->push((string) $entry['Employee_Pin']);
                    }
                }
            }
        }
        $clockedPins = $clockedPins->unique();

        // All active employees keyed by employee_number → id
        $allActive = HRPerson::where('status', 1)
            ->pluck('id', 'employee_number');

        // Staff who are never expected to clock in (field workers, execs, remote
        // staff) are excluded so they are not chased for leave every day.
        $exempt = AttendanceAbsenceExemption::activeEmployeeIds();

        /*
         * Anyone who clocked in through the app today — kiosk, manual, GPS or
         * WFH — is present even though the biometric reader never saw them.
         * Without this, someone who used the kiosk is reported absent and, with
         * auto-deduct on, has a day's leave taken off them for being at work.
         */
        $clockedInApp = AttendanceClocking::query()
            ->where('type', 'clock_in')
            ->whereDate('clocked_at', Carbon::today()->toDateString())
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return $allActive
            ->filter(fn ($id, $pin) => ! $clockedPins->contains((string) $pin)
                && ! in_array((int) $id, $exempt, true)
                && ! in_array((int) $id, $clockedInApp, true))
            ->values()
            ->toArray();
    }

    // ── Auto-apply leave for employees absent past the configured threshold ───

    public function applyLeaveForUser(): void
    {
        $settings    = AttendanceSetting::current();
        $threshold   = (int) ($settings->auto_apply_days_threshold ?? 3);
        $allowUnpaid = (bool) $settings->allow_unpaid_when_annual_done;
        $autoDeduct  = (bool) $settings->auto_deduct_leave;
        $today       = Carbon::today()->startOfDay();

        $pending = AttendanceAbsentUser::where('is_applied', false)
            ->where('is_deducted', false)
            ->where('date', '<=', $today->toDateString())
            ->get();

        foreach ($pending as $absent) {
            $absentDate = Carbon::parse($absent->date)->startOfDay();
            $diffDays   = $absentDate->diffInDays($today, false);

            // Skip future or within threshold
            if ($diffDays < 0 || $diffDays < $threshold) {
                continue;
            }

            // Skip if leave was already submitted
            if ($this->getLeaveForDate($absent->employee_id, $absentDate)) {
                $absent->update(['is_applied' => true]);
                continue;
            }

            DB::transaction(function () use ($absent, $absentDate, $allowUnpaid, $autoDeduct) {
                [$leaveTypeId, $balance] = $this->resolveLeaveType($absent->employee_id, $allowUnpaid);
                if ($leaveTypeId === null) return;

                $status  = $autoDeduct
                    ? LeaveRequestStatus::Approved->value
                    : LeaveRequestStatus::PendingManager->value;

                $leaveRequest = LeaveRequest::create([
                    'uuid'           => (string) Str::uuid(),
                    'request_number' => 'AUTO-' . strtoupper(Str::random(8)),
                    'employee_id'    => $absent->employee_id,
                    'leave_type_id'  => $leaveTypeId,
                    'leave_cycle_id' => $this->currentCycleId($absentDate),
                    'start_date'     => $absentDate->toDateString(),
                    'end_date'       => $absentDate->toDateString(),
                    'working_days'   => 1,
                    'working_hours'  => 8,
                    'status'         => $status,
                    'submitted_at'   => now(),
                    'approved_at'    => $autoDeduct ? now() : null,
                    'notes'          => $autoDeduct
                        ? 'System automatically deducted leave for absence on ' . $absentDate->toDateString()
                        : 'System automatically applied leave for absence on ' . $absentDate->toDateString(),
                ]);

                // Deduct balance only in auto-deduct mode and when annual leave with balance
                if ($autoDeduct && $balance > 0) {
                    /*
                     * The deduction has to be posted to leave_balance_transactions —
                     * that ledger is what LeaveBalanceService (and therefore the
                     * balances screen, reports and payout calculations) reads.
                     * Decrementing leave_credit alone left the request approved while
                     * the employee's visible balance never moved, so auto-deduct
                     * appeared to do nothing.
                     */
                    app(LeaveBalanceService::class)->consume($leaveRequest);

                    // Legacy mirror: the old leave-history report still reads this table.
                    DB::table('leave_credit')
                        ->where('hr_id', $absent->employee_id)
                        ->where('leave_type_id', $leaveTypeId)
                        ->decrement('leave_balance', 8);
                }

                $absent->update([
                    'is_applied'  => true,
                    'is_deducted' => $autoDeduct && $balance > 0,
                ]);

                Log::info("ERS Auto-apply: leave created for employee #{$absent->employee_id} on {$absentDate->toDateString()} (deduct={$autoDeduct})");
            });
        }
    }

    // ── End-of-month catch-all for still-unapplied absences ─────────────────

    public function autoMonthlyApplyLeave(): void
    {
        if (! $this->isEnabled()) {
            Log::info('ERS: monthly auto-apply skipped — ERS integration is disabled for this company.');
            return;
        }

        $settings    = AttendanceSetting::current();
        $allowUnpaid = (bool) $settings->allow_unpaid_when_annual_done;
        $monthStart  = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd    = Carbon::now()->endOfMonth()->toDateString();

        $absences = AttendanceAbsentUser::where('is_applied', false)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->get();

        foreach ($absences as $absent) {
            $absentDate = Carbon::parse($absent->date)->startOfDay();

            if ($this->getLeaveForDate($absent->employee_id, $absentDate)) {
                $absent->update(['is_applied' => true]);
                continue;
            }

            DB::transaction(function () use ($absent, $absentDate, $allowUnpaid) {
                [$leaveTypeId] = $this->resolveLeaveType($absent->employee_id, $allowUnpaid);
                if ($leaveTypeId === null) return;

                LeaveRequest::create([
                    'uuid'           => (string) Str::uuid(),
                    'request_number' => 'AUTO-' . strtoupper(Str::random(8)),
                    'employee_id'    => $absent->employee_id,
                    'leave_type_id'  => $leaveTypeId,
                    'leave_cycle_id' => $this->currentCycleId($absentDate),
                    'start_date'     => $absentDate->toDateString(),
                    'end_date'       => $absentDate->toDateString(),
                    'working_days'   => 1,
                    'working_hours'  => 8,
                    'status'         => LeaveRequestStatus::PendingManager->value,
                    'submitted_at'   => now(),
                    'notes'          => 'Auto-applied at end of month for absence on ' . $absentDate->toDateString(),
                ]);

                $absent->update(['is_applied' => true]);

                Log::info("ERS Monthly Auto-apply: leave created for employee #{$absent->employee_id} on {$absentDate->toDateString()}");
            });
        }
    }

    // ── Public holiday check ─────────────────────────────────────────────────

    public function isPublicHoliday(?string $date = null): bool
    {
        $date = $date ?? date('Y-m-d');
        $year = (int) date('Y', strtotime($date));

        $holidays = DB::table('public_holidays')
            ->where(function ($q) use ($year) {
                $q->whereNull('year')
                  ->orWhere('year', 0)
                  ->orWhere('year', $year);
            })
            ->where(function ($q) {
                // Shared defaults (NULL company) + the active tenant's own holidays.
                $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
                $q->whereNull('company_id');
                if ($cid !== null) {
                    $q->orWhere('company_id', $cid);
                }
            })
            ->pluck('day')
            ->toArray();

        $easterSunday = easter_date($year);
        $holidays[]   = date('Y-m-d', $easterSunday - 2 * 86400);
        $holidays[]   = date('Y-m-d', $easterSunday + 1 * 86400);

        $normalized = array_map(function ($h) use ($year) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)
                ? $h
                : $year . '-' . date('m-d', strtotime($h));
        }, $holidays);

        return in_array($date, $normalized);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveStartTime(HRPerson $employee, AttendanceSetting $settings): string
    {
        $global = $settings->work_start_time ?? '08:00:00';
        if ($settings->use_employee_work_hours && ! empty($employee->start_time)) {
            return $employee->start_time;
        }
        return $global;
    }

    private function resolveEndTime(HRPerson $employee, AttendanceSetting $settings): string
    {
        $global = $settings->work_end_time ?? '17:00:00';
        if ($settings->use_employee_work_hours && ! empty($employee->end_time)) {
            return $employee->end_time;
        }
        return $global;
    }

    private function isLate(Carbon $clockIn, HRPerson $employee, AttendanceSetting $settings): bool
    {
        $startStr = $this->resolveStartTime($employee, $settings);
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', $clockIn->toDateString() . ' ' . $startStr);
        return $clockIn->greaterThan($expected->copy()->addMinutes($settings->grace_period_minutes ?? 15));
    }

    private function lateMinutes(Carbon $clockIn, HRPerson $employee, AttendanceSetting $settings): int
    {
        $startStr = $this->resolveStartTime($employee, $settings);
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', $clockIn->toDateString() . ' ' . $startStr);
        return max(0, (int) $expected->diffInMinutes($clockIn, false));
    }

    private function earlyLeft(Carbon $clockOut, HRPerson $employee, AttendanceSetting $settings): bool
    {
        $endStr   = $this->resolveEndTime($employee, $settings);
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', $clockOut->toDateString() . ' ' . $endStr);
        return $clockOut->lessThan($expected->copy()->subMinutes($settings->early_departure_threshold_minutes ?? 15));
    }

    private function earlyMinutes(Carbon $clockOut, HRPerson $employee, AttendanceSetting $settings): int
    {
        $endStr   = $this->resolveEndTime($employee, $settings);
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', $clockOut->toDateString() . ' ' . $endStr);
        return max(0, (int) $clockOut->diffInMinutes($expected, false));
    }

    private function isOnLeave(int $employeeId): bool
    {
        return LeaveRequest::where('employee_id', $employeeId)
            ->where('status', LeaveRequestStatus::Approved->value)
            ->where('start_date', '<=', Carbon::today()->toDateString())
            ->where('end_date', '>=', Carbon::today()->toDateString())
            ->exists();
    }

    private function getLeaveForDate(int $employeeId, Carbon $date): bool
    {
        return LeaveRequest::where('employee_id', $employeeId)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->whereNotIn('status', [
                LeaveRequestStatus::Rejected->value,
                LeaveRequestStatus::Cancelled->value,
            ])
            ->exists();
    }

    private function resolveLeaveType(int $employeeId, bool $allowUnpaid): array
    {
        $annualType = LeaveType::where('statutory_type', 'annual')->first()
                   ?? LeaveType::first();

        if (! $annualType) {
            Log::warning("ERS Auto-apply: No leave type found for employee #{$employeeId}");
            return [null, 0];
        }

        $balance = (float) (DB::table('leave_credit')
            ->where('hr_id', $employeeId)
            ->where('leave_type_id', $annualType->id)
            ->value('leave_balance') ?? 0);

        if ($allowUnpaid && $balance <= 0) {
            $unpaidType = LeaveType::where('statutory_type', 'unpaid')->first();
            if ($unpaidType) {
                return [$unpaidType->id, 0];
            }
        }

        return [$annualType->id, $balance];
    }

    private function currentCycleId(Carbon $date): ?int
    {
        return DB::table('leave_cycles')
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->value('id');
    }
}
