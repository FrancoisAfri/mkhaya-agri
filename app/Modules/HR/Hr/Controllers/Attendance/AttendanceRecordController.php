<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\HR\Hr\Models\Attendance\AttendanceStatus;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAuditLog;
use App\Modules\HR\Hr\Models\HRPerson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceRecordController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date', Carbon::today()->toDateString());

        $records = AttendanceRecord::with(['employee', 'status', 'shift', 'approvedBy'])
            ->whereDate('date', $date)
            ->orderBy('clock_in')
            ->paginate(50)
            ->through(fn($r) => [
                'id'              => $r->id,
                'employee_id'     => $r->employee_id,
                'employee_name'   => $r->employee?->first_name." ".$r->employee?->surname ?? '—',
                'status'          => $r->status?->name ?? '—',
                'status_color'    => $r->status?->color ?? '#6c757d',
                'shift'           => $r->shift?->name ?? '—',
                'date'            => $r->date->toDateString(),
                'clock_in'        => $r->clock_in?->format('H:i'),
                'clock_out'       => $r->clock_out?->format('H:i'),
                'hours_worked'    => $r->hours_worked,
                'overtime_hours'  => $r->overtime_hours,
                'is_late'         => $r->is_late,
                'late_minutes'    => $r->late_minutes,
                'source'          => $r->source,
                'manager_approved' => $r->manager_approved,
                'notes'           => $r->notes,
            ]);

        return Inertia::render('HR/Attendance/Records/Index', [
            'records'    => $records,
            'statuses'   => AttendanceStatus::active()->orderBy('name')->get(['id', 'name', 'color', 'code']),
            'shifts'     => AttendanceShift::active()->orderBy('name')->get(['id', 'name', 'code']),
            'employees'  => HRPerson::where('status', 1)->orderBy('first_name')->get(['id', 'first_name', 'surname']),
            'selectedDate' => $date,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'status_id'   => 'nullable|integer|exists:attendance_statuses,id',
            'shift_id'    => 'nullable|integer|exists:attendance_shifts,id',
            'date'        => 'required|date',
            'clock_in'    => 'nullable|date_format:H:i',
            'clock_out'   => 'nullable|date_format:H:i',
            'source'      => 'string|in:manual,biometric,web,mobile,import',
            'notes'       => 'nullable|string',
        ]);

        $data = $this->computeTimes($data);

        $record = AttendanceRecord::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            $data,
        );

        AttendanceAuditLog::record('manual_clock', $data['employee_id'], [], $data, 'Manual record created', AttendanceRecord::class, $record->id);

        return redirect()->back()->with('success', 'Attendance record saved.');
    }

    public function update(Request $request, AttendanceRecord $attendanceRecord)
    {
        $data = $request->validate([
            'status_id'   => 'nullable|integer|exists:attendance_statuses,id',
            'shift_id'    => 'nullable|integer|exists:attendance_shifts,id',
            'clock_in'    => 'nullable|date_format:H:i',
            'clock_out'   => 'nullable|date_format:H:i',
            'notes'       => 'nullable|string',
        ]);

        $old  = $attendanceRecord->only(['clock_in', 'clock_out', 'status_id', 'shift_id', 'notes']);
        $data = $this->computeTimes($data, $attendanceRecord->date->toDateString());
        $attendanceRecord->update($data);

        AttendanceAuditLog::record('clock_edit', $attendanceRecord->employee_id, $old, $data, null, AttendanceRecord::class, $attendanceRecord->id);

        return redirect()->back()->with('success', 'Record updated.');
    }

    public function approve(AttendanceRecord $attendanceRecord)
    {
        $attendanceRecord->update([
            'manager_approved' => true,
            'approved_by'      => Auth::id(),
            'approved_at'      => now(),
        ]);

        AttendanceAuditLog::record('overtime_approved', $attendanceRecord->employee_id, [], [], 'Record approved', AttendanceRecord::class, $attendanceRecord->id);

        return redirect()->back()->with('success', 'Record approved.');
    }

    public function destroy(AttendanceRecord $attendanceRecord)
    {
        $old = $attendanceRecord->only(['employee_id', 'date', 'clock_in', 'clock_out']);
        $attendanceRecord->delete();

        AttendanceAuditLog::record('record_deleted', $old['employee_id'], $old, [], 'Record deleted', AttendanceRecord::class, $attendanceRecord->id);

        return redirect()->back()->with('success', 'Record deleted.');
    }

    private function computeTimes(array $data, ?string $date = null): array
    {
        $date = $date ?? $data['date'];

        if (!empty($data['clock_in'])) {
            $data['clock_in'] = Carbon::parse("{$date} {$data['clock_in']}");
        }
        if (!empty($data['clock_out'])) {
            $data['clock_out'] = Carbon::parse("{$date} {$data['clock_out']}");
            if (!empty($data['clock_in']) && $data['clock_out']->lt($data['clock_in'])) {
                $data['clock_out']->addDay();
            }
        }
        if (!empty($data['clock_in']) && !empty($data['clock_out'])) {
            $minutes = $data['clock_in']->diffInMinutes($data['clock_out']);
            $data['hours_worked'] = round($minutes / 60, 2);
        }

        return $data;
    }
}
