<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * The day's attendance record (attendance_records) from the engine's approved timesheets.
 *
 * Payroll, leave, the BCEA checks and T&A's analytics read one record per person per day. The engine
 * keeps sessions — several a day, corrected, approved — and writes that day's record from the ones
 * approved: hours, overtime, lateness, first in and last out. It writes a record it owns (one it made,
 * or one T&A's own clocking made, whose clockings the engine adopted); a record HR captured by hand or
 * imported from another system is left as it is.
 */
class DailyRecords
{
    public const SOURCE = 'timesheets';

    /** Write the day's record for a worker from their approved timesheets. @return int minutes written (0 if left alone) */
    public function sync(Worker $worker, string $date, ?User $actor, int $extraOvertime = 0, ?string $note = null): int
    {
        $existing = DB::table('attendance_records')->where('employee_id', $worker->hr_person_id)->whereDate('date', $date)->whereNull('deleted_at')->first();
        if ($existing && ! $this->owns($existing)) {
            return 0;
        }
        $day = Timesheet::withoutGlobalScopes()->where('worker_id', $worker->id)->whereDate('work_date', $date)->where('status', Timesheet::APPROVED)->get();
        if ($day->isEmpty() && ! $existing) {
            return 0;
        }
        $worked = (int) $day->sum('worked_minutes');
        $ot = (int) $day->sum('overtime_minutes') + $extraOvertime;
        $row = ['clock_in' => $day->min('clock_in_at'), 'clock_out' => $day->max('clock_out_at'), 'hours_worked' => round($worked / 60, 2),
            'overtime_hours' => round(min($ot, $worked) / 60, 2), 'is_late' => $day->sum('late_minutes') > 0, 'late_minutes' => (int) $day->sum('late_minutes'),
            'is_early_departure' => $day->sum('early_minutes') > 0, 'early_departure_minutes' => (int) $day->sum('early_minutes'),
            'source' => self::SOURCE, 'notes' => $note ?? ($day->isEmpty() ? 'No approved timesheet' : 'Approved timesheet(s) '.$day->pluck('uuid')->implode(', ')),
            'manager_approved' => $day->isNotEmpty(), 'approved_by' => $day->isNotEmpty() ? $actor?->id : null, 'approved_at' => $day->isNotEmpty() ? now() : null,
            'updated_at' => now(), 'company_id' => $worker->company_id];
        if ($existing) {
            DB::table('attendance_records')->where('id', $existing->id)->update($row);
        } else {
            DB::table('attendance_records')->insert($row + ['employee_id' => $worker->hr_person_id, 'date' => $date, 'created_at' => now()]);
        }

        return $worked;
    }

    /** A record the engine may write: its own, the farm handoff's, or one made by clockings the engine adopted. */
    private function owns(object $record): bool
    {
        return in_array($record->source, [self::SOURCE, 'agri'], true)
            || DB::table('attendance_clockings')->where('attendance_record_id', $record->id)->whereNotNull('worker_id')->exists();
    }
}
