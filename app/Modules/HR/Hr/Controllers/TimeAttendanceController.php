<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class TimeAttendanceController extends Controller
{
    public function index(): Response
    {
        if (Schema::hasTable('employees_time_and_attendance')) {
            return $this->renderLegacyAttendance();
        }

        if (Schema::hasTable('attendances')) {
            return $this->renderModernAttendance();
        }

        return Inertia::render('HR/Attendance/Index', [
            'records' => [],
            'summary' => [
                'source' => 'none',
                'total' => 0,
                'present' => 0,
                'late' => 0,
                'absent' => 0,
            ],
        ]);
    }

    private function renderLegacyAttendance(): Response
    {
        $records = DB::table('employees_time_and_attendance as eta')
            ->leftJoin('hr_people as hp', function ($join) {
                // Legacy databases may store hr_id as either hr_people.id or hr_people.user_id.
                $join->on('hp.id', '=', 'eta.hr_id')
                    ->orOn('hp.user_id', '=', 'eta.hr_id');
            })
            ->select([
                'eta.id',
                'eta.hr_id',
                'eta.date_of_action',
                'eta.clokin_time',
                'eta.clockout_time',
                'eta.hours_worked',
                'eta.late_arrival',
                'eta.early_clockout',
                'eta.absent',
                'eta.onleave',
                'eta.leave_type',
                'hp.first_name',
                'hp.surname',
            ])
            ->orderByDesc('eta.id')
            ->limit(200)
            ->get()
            ->map(function ($row) {
                $fullName = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->surname ?? ''));

                return [
                    'id' => $row->id,
                    'employee_name' => $fullName !== '' ? $fullName : ('Employee #' . (string) $row->hr_id),
                    'employee_ref' => (int) $row->hr_id,
                    'date' => $this->formatDate($row->date_of_action),
                    'clock_in' => $row->clokin_time,
                    'clock_out' => $row->clockout_time,
                    'hours_worked' => (float) ($row->hours_worked ?? 0),
                    'late' => (bool) ($row->late_arrival ?? false),
                    'absent' => (bool) ($row->absent ?? false),
                    'on_leave' => (bool) ($row->onleave ?? false),
                    'leave_type' => $row->leave_type,
                ];
            });

        return Inertia::render('HR/Attendance/Index', [
            'records' => $records,
            'summary' => [
                'source' => 'employees_time_and_attendance',
                'total' => $records->count(),
                'present' => $records->where('absent', false)->count(),
                'late' => $records->where('late', true)->count(),
                'absent' => $records->where('absent', true)->count(),
            ],
        ]);
    }

    private function renderModernAttendance(): Response
    {
        $records = DB::table('attendances as a')
            ->select([
                'a.id',
                'a.employee_id',
                'a.date',
                'a.clock_in',
                'a.clock_out',
                'a.overtime_hours',
                'a.is_late',
            ])
            ->orderByDesc('a.id')
            ->limit(200)
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'employee_name' => 'Employee #' . (string) $row->employee_id,
                'employee_ref' => (int) $row->employee_id,
                'date' => $this->formatDate($row->date),
                'clock_in' => $row->clock_in,
                'clock_out' => $row->clock_out,
                'hours_worked' => null,
                'late' => (bool) ($row->is_late ?? false),
                'absent' => empty($row->clock_in),
                'on_leave' => false,
                'leave_type' => null,
                'overtime_hours' => (float) ($row->overtime_hours ?? 0),
            ]);

        return Inertia::render('HR/Attendance/Index', [
            'records' => $records,
            'summary' => [
                'source' => 'attendances',
                'total' => $records->count(),
                'present' => $records->where('absent', false)->count(),
                'late' => $records->where('late', true)->count(),
                'absent' => $records->where('absent', true)->count(),
            ],
        ]);
    }

    private function formatDate(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d', (int) $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
