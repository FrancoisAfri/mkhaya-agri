<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\Attendance\AttendanceAbsenceExemption;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveAbsenceUploadBatch;
use App\Modules\HR\Leave\Models\LeaveAbsenceUploadBatchItem;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attendance-driven absence upload.
 *
 * HR uploads a spreadsheet of days employees did not clock in; this works out
 * exactly what would be deducted (and from whom), then — once confirmed —
 * creates an already-approved leave request per day, debits the balance ledger
 * and records everything against a batch so the whole run can be reversed.
 *
 * Deductions go through LeaveBalanceService so the balance employees actually
 * see moves; writing to the legacy leave_credit table alone would not.
 */
class LeaveAbsenceUploadService
{
    /** Row outcomes shown in the preview. */
    public const OUTCOME_APPLY        = 'apply';
    public const OUTCOME_NEGATIVE     = 'apply_negative';
    public const OUTCOME_DUPLICATE    = 'duplicate_in_file';
    public const OUTCOME_ON_LEAVE     = 'already_on_leave';
    public const OUTCOME_EXEMPT       = 'exempt';
    public const OUTCOME_NOT_FOUND    = 'employee_not_found';

    public function __construct(private readonly LeaveBalanceService $balances) {}

    // ── Parsing ───────────────────────────────────────────────────────────────

    /**
     * Pull (employee_number, date) pairs out of the uploaded rows. The header is
     * located by matching column names rather than assuming a fixed position, so
     * decorative title rows above it don't matter.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{records: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function extractAbsences(array $rows): array
    {
        $headerIndex = -1;
        $cols = ['number' => -1, 'date' => -1, 'name' => -1];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $found = ['number' => -1, 'date' => -1, 'name' => -1];

            foreach ($row as $c => $cell) {
                $h = strtolower(trim((string) $cell));
                if ($h === '') {
                    continue;
                }
                if ($found['number'] === -1 && (str_contains($h, 'employee number') || str_contains($h, 'employee no') || str_contains($h, 'pin'))) {
                    $found['number'] = $c;
                } elseif ($found['date'] === -1 && str_contains($h, 'date')) {
                    $found['date'] = $c;
                } elseif ($found['name'] === -1 && str_contains($h, 'name')) {
                    $found['name'] = $c;
                }
            }

            if ($found['number'] !== -1 && $found['date'] !== -1) {
                $headerIndex = $i;
                $cols = $found;
                break;
            }
        }

        if ($headerIndex === -1) {
            return ['records' => [], 'errors' => []];
        }

        $records = [];
        $errors  = [];

        for ($i = $headerIndex + 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            if (! is_array($row)) {
                continue;
            }

            $number  = $cols['number'] !== -1 ? trim((string) ($row[$cols['number']] ?? '')) : '';
            $dateRaw = $cols['date'] !== -1 ? ($row[$cols['date']] ?? '') : '';
            $name    = $cols['name'] !== -1 ? trim((string) ($row[$cols['name']] ?? '')) : '';

            if ($number === '' && (string) $dateRaw === '' && $name === '') {
                continue; // blank row
            }

            if ($number === '' || (string) $dateRaw === '') {
                $errors[] = 'Row ' . ($i + 1) . ' skipped: missing employee number or date.';
                continue;
            }

            $date = $this->parseDate($dateRaw);

            if (! $date) {
                $errors[] = 'Row ' . ($i + 1) . ': could not read the date "' . (string) $dateRaw . '".';
                continue;
            }

            $records[] = [
                'employee_number' => $number,
                'employee_name'   => $name,
                'date'            => $date->toDateString(),
                'day_of_week'     => $date->format('l'),
            ];
        }

        return ['records' => $records, 'errors' => $errors];
    }

    /** Spreadsheet dates arrive as Excel serials, DateTime objects or strings. */
    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            // Excel serial: day 1 is 1900-01-01, offset by the well-known 1900 leap bug.
            try {
                return Carbon::createFromTimestamp(((float) $value - 25569) * 86400)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);

        /*
         * Day-first formats are tried explicitly before falling back to Carbon's
         * own parsing, which reads 21/07/2026 as month 21 and fails. Spreadsheets
         * produced in South Africa are day-first, so this is the common case.
         */
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (\Throwable) {
                continue; // not this format
            }

            // Round-trip check rejects near-misses such as 2026-13-01.
            if ($parsed && $parsed->format($format) === $text) {
                return $parsed->startOfDay();
            }
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Preview ───────────────────────────────────────────────────────────────

    /**
     * Group the parsed rows per employee and work out what each day would do to
     * their balance, without writing anything.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function preview(array $records, int $leaveTypeId, float $hoursPerDay): array
    {
        $byNumber = [];
        foreach ($records as $record) {
            $byNumber[$record['employee_number']][] = $record;
        }

        $employees = [];
        $applyItems = [];
        $totals = ['employees' => 0, 'apply_days' => 0, 'apply_hours' => 0, 'skipped' => 0, 'not_found' => 0, 'goes_negative' => 0];
        $exempt = AttendanceAbsenceExemption::activeEmployeeIds();

        foreach ($byNumber as $number => $rows) {
            $person = HRPerson::where('employee_number', $number)->where('status', 1)->first();

            if (! $person) {
                $employees[] = [
                    'found'           => false,
                    'employee_number' => (string) $number,
                    'employee_name'   => $rows[0]['employee_name'] ?: '(unknown)',
                    'dates'           => array_map(fn ($r) => [
                        'date' => $r['date'], 'day' => $r['day_of_week'], 'outcome' => self::OUTCOME_NOT_FOUND,
                    ], $rows),
                    'apply_days'      => 0,
                ];
                $totals['not_found']++;
                continue;
            }

            $running = (float) $this->balances->getBalance($person->id, $leaveTypeId);
            $opening = $running;

            usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

            $seen = [];
            $dates = [];
            $applyDays = 0;

            foreach ($rows as $row) {
                $line = ['date' => $row['date'], 'day' => $row['day_of_week']];

                if (isset($seen[$row['date']])) {
                    $line['outcome'] = self::OUTCOME_DUPLICATE;
                    $dates[] = $line;
                    $totals['skipped']++;
                    continue;
                }
                $seen[$row['date']] = true;

                if (in_array((int) $person->id, $exempt, true)) {
                    $line['outcome'] = self::OUTCOME_EXEMPT;
                    $dates[] = $line;
                    $totals['skipped']++;
                    continue;
                }

                if ($this->hasLeaveOn($person->id, $row['date'])) {
                    $line['outcome'] = self::OUTCOME_ON_LEAVE;
                    $dates[] = $line;
                    $totals['skipped']++;
                    continue;
                }

                $before = $running;
                $after  = $running - $hoursPerDay;
                $running = $after;

                $line['outcome']        = $after < 0 ? self::OUTCOME_NEGATIVE : self::OUTCOME_APPLY;
                $line['balance_before'] = round($before, 2);
                $line['balance_after']  = round($after, 2);
                $line['days_before']    = round($before / max($hoursPerDay, 0.01), 2);
                $line['days_after']     = round($after / max($hoursPerDay, 0.01), 2);
                $dates[] = $line;

                if ($after < 0) {
                    $totals['goes_negative']++;
                }

                $applyItems[] = [
                    'employee_id'     => $person->id,
                    'employee_name'   => trim($person->first_name . ' ' . $person->surname),
                    'employee_number' => (string) $number,
                    'date'            => $row['date'],
                    'day_of_week'     => $row['day_of_week'],
                    'hours'           => $hoursPerDay,
                ];

                $applyDays++;
            }

            $employees[] = [
                'found'           => true,
                'employee_id'     => $person->id,
                'employee_number' => (string) $number,
                'employee_name'   => trim($person->first_name . ' ' . $person->surname),
                'dates'           => $dates,
                'apply_days'      => $applyDays,
                'opening_balance' => round($opening, 2),
                'closing_balance' => round($running, 2),
                'goes_negative'   => $running < 0,
            ];

            if ($applyDays > 0) {
                $totals['employees']++;
                $totals['apply_days']  += $applyDays;
                $totals['apply_hours'] += $applyDays * $hoursPerDay;
            }
        }

        return ['employees' => $employees, 'apply_items' => $applyItems, 'totals' => $totals];
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    /**
     * Apply the previewed absences. Each row is re-checked against live data, so
     * anything that changed since the preview (leave captured in the meantime,
     * an employee deactivated) is skipped rather than double-booked.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{batch: LeaveAbsenceUploadBatch, applied: int, skipped: array<int, string>}
     */
    public function confirm(array $items, int $leaveTypeId, float $hoursPerDay, ?string $fileName): array
    {
        $leaveType = LeaveType::find($leaveTypeId);
        $user      = Auth::user();
        $exempt    = AttendanceAbsenceExemption::activeEmployeeIds();

        return DB::transaction(function () use ($items, $leaveTypeId, $leaveType, $hoursPerDay, $fileName, $user, $exempt) {
            $batch = LeaveAbsenceUploadBatch::create([
                'leave_type_id'     => $leaveTypeId,
                'leave_type_name'   => $leaveType?->name,
                'hours_per_day'     => $hoursPerDay,
                'file_name'         => $fileName,
                'status'            => LeaveAbsenceUploadBatch::STATUS_APPLIED,
                'performed_by'      => $user?->id,
                'performed_by_name' => $this->userName($user),
            ]);

            $applied = 0;
            $skipped = [];
            $employeeIds = [];
            $cycleId = LeaveCycle::active()?->id;

            foreach ($items as $item) {
                $employeeId = (int) ($item['employee_id'] ?? 0);
                $date       = (string) ($item['date'] ?? '');
                $hours      = (float) ($item['hours'] ?? $hoursPerDay);

                if ($employeeId <= 0 || $date === '' || $hours <= 0) {
                    continue;
                }

                $person = HRPerson::where('id', $employeeId)->where('status', 1)->first();
                if (! $person) {
                    $skipped[] = "Employee #{$employeeId} is no longer active — {$date} skipped.";
                    continue;
                }

                if (in_array($employeeId, $exempt, true)) {
                    $skipped[] = trim($person->first_name . ' ' . $person->surname) . " is exempt from absence tracking — {$date} skipped.";
                    continue;
                }

                if ($this->hasLeaveOn($employeeId, $date)) {
                    $skipped[] = trim($person->first_name . ' ' . $person->surname) . " already has leave on {$date} — skipped.";
                    continue;
                }

                $before = (float) $this->balances->getBalance($employeeId, $leaveTypeId);

                $leaveRequest = LeaveRequest::create([
                    'uuid'           => (string) Str::uuid(),
                    'request_number' => 'ABS-' . strtoupper(Str::random(8)),
                    'employee_id'    => $employeeId,
                    'leave_type_id'  => $leaveTypeId,
                    'leave_cycle_id' => $cycleId,
                    'start_date'     => $date,
                    'end_date'       => $date,
                    'working_days'   => 1,
                    'working_hours'  => $hours,
                    'status'         => LeaveRequestStatus::Approved->value,
                    'submitted_at'   => now(),
                    'approved_at'    => now(),
                    'notes'          => 'Absence upload — no clock-in recorded on ' . $date,
                ]);

                // Posts the debit to leave_balance_transactions, the ledger the
                // balances screen and reports read.
                $this->balances->consume($leaveRequest);

                LeaveAbsenceUploadBatchItem::create([
                    'batch_id'         => $batch->id,
                    'employee_id'      => $employeeId,
                    'employee_name'    => trim($person->first_name . ' ' . $person->surname),
                    'employee_number'  => $person->employee_number,
                    'leave_type_id'    => $leaveTypeId,
                    'absence_date'     => $date,
                    'day_of_week'      => $item['day_of_week'] ?? Carbon::parse($date)->format('l'),
                    'hours'            => $hours,
                    'balance_before'   => round($before, 2),
                    'balance_after'    => round($before - $hours, 2),
                    'leave_request_id' => $leaveRequest->id,
                ]);

                $employeeIds[$employeeId] = true;
                $applied++;
            }

            $batch->forceFill([
                'total_employees' => count($employeeIds),
                'total_days'      => $hoursPerDay > 0 ? round(($applied * $hoursPerDay) / $hoursPerDay, 2) : 0,
                'total_hours'     => round($applied * $hoursPerDay, 2),
            ])->save();

            return ['batch' => $batch, 'applied' => $applied, 'skipped' => $skipped];
        });
    }

    // ── Reverse ───────────────────────────────────────────────────────────────

    /**
     * Undo a batch: credit the hours back and cancel the leave requests it
     * created. The requests are cancelled rather than deleted so the record of
     * what happened survives.
     */
    public function reverse(LeaveAbsenceUploadBatch $batch, ?string $reason = null): int
    {
        if ($batch->isReversed()) {
            throw new \RuntimeException('This upload has already been reversed.');
        }

        $user = Auth::user();

        return DB::transaction(function () use ($batch, $reason, $user) {
            $reversed = 0;

            foreach ($batch->items()->where('is_reversed', false)->get() as $item) {
                $this->balances->manualAdjust(
                    hrPersonId:  $item->employee_id,
                    leaveTypeId: $item->leave_type_id,
                    hours:       (float) $item->hours,   // credit back what was taken
                    reason:      'Reversal of absence upload batch #' . $batch->id
                        . ($reason ? ' — ' . $reason : ''),
                );

                if ($item->leave_request_id) {
                    LeaveRequest::where('id', $item->leave_request_id)->update([
                        'status'       => LeaveRequestStatus::Cancelled->value,
                        'cancelled_at' => now(),
                    ]);
                }

                $item->forceFill(['is_reversed' => true])->save();
                $reversed++;
            }

            $batch->forceFill([
                'status'           => LeaveAbsenceUploadBatch::STATUS_REVERSED,
                'reversed_by'      => $user?->id,
                'reversed_by_name' => $this->userName($user),
                'reversed_at'      => now(),
                'reversal_reason'  => $reason,
            ])->save();

            return $reversed;
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Does the employee already have leave (approved or awaiting a decision) on this date? */
    private function hasLeaveOn(int $employeeId, string $date): bool
    {
        return LeaveRequest::where('employee_id', $employeeId)
            ->whereNotIn('status', [
                LeaveRequestStatus::Rejected->value,
                LeaveRequestStatus::Cancelled->value,
                LeaveRequestStatus::Draft->value,
            ])
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    private function userName($user): string
    {
        if (! $user) {
            return 'System';
        }

        $person = $user->person;

        return $person ? trim($person->first_name . ' ' . $person->surname) : (string) $user->email;
    }
}
