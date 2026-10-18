<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceShiftController extends Controller
{
    public function index()
    {
        return Inertia::render('HR/Attendance/Shifts/Index', [
            'shifts' => AttendanceShift::orderBy('name')->get()->map(fn($s) => array_merge(
                $s->toArray(),
                ['net_hours' => $s->net_hours]
            )),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'code'                    => 'required|string|max:30|unique:attendance_shifts,code',
            'start_time'              => 'required|date_format:H:i',
            'end_time'                => 'required|date_format:H:i',
            'break_duration_minutes'  => 'integer|min:0|max:480',
            'is_overnight'            => 'boolean',
            'is_flexible'             => 'boolean',
            'flexible_window_minutes' => 'integer|min:0',
            'grace_period_minutes'    => 'nullable|integer|min:0',
            'description'             => 'nullable|string',
            'status'                  => 'integer|in:0,1',
        ]);

        AttendanceShift::create($data);

        return redirect()->back()->with('success', 'Shift created.');
    }

    public function update(Request $request, AttendanceShift $attendanceShift)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'code'                    => 'required|string|max:30|unique:attendance_shifts,code,' . $attendanceShift->id,
            'start_time'              => 'required|date_format:H:i',
            'end_time'                => 'required|date_format:H:i',
            'break_duration_minutes'  => 'integer|min:0|max:480',
            'is_overnight'            => 'boolean',
            'is_flexible'             => 'boolean',
            'flexible_window_minutes' => 'integer|min:0',
            'grace_period_minutes'    => 'nullable|integer|min:0',
            'description'             => 'nullable|string',
            'status'                  => 'integer|in:0,1',
        ]);

        $attendanceShift->update($data);

        return redirect()->back()->with('success', 'Shift updated.');
    }

    public function destroy(AttendanceShift $attendanceShift)
    {
        $attendanceShift->delete();

        return redirect()->back()->with('success', 'Shift deleted.');
    }
}
