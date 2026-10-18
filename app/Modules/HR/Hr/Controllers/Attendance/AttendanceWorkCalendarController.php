<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWorkCalendar;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceWorkCalendarController extends Controller
{
    public function index()
    {
        return Inertia::render('HR/Attendance/WorkCalendars/Index', [
            'calendars' => AttendanceWorkCalendar::with('shifts')->orderBy('year', 'desc')->orderBy('name')->get(),
            'shifts'    => AttendanceShift::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'year'                    => 'required|integer|min:2020|max:2100',
            'monday'                  => 'boolean',
            'tuesday'                 => 'boolean',
            'wednesday'               => 'boolean',
            'thursday'                => 'boolean',
            'friday'                  => 'boolean',
            'saturday'                => 'boolean',
            'sunday'                  => 'boolean',
            'include_public_holidays' => 'boolean',
            'description'             => 'nullable|string',
            'is_default'              => 'boolean',
            'status'                  => 'integer|in:0,1',
            'shift_ids'               => 'nullable|array',
            'shift_ids.*'             => 'integer|exists:attendance_shifts,id',
        ]);

        if (!empty($data['is_default'])) {
            AttendanceWorkCalendar::where('is_default', true)->update(['is_default' => false]);
        }

        $shiftIds = $data['shift_ids'] ?? [];
        unset($data['shift_ids']);

        $calendar = AttendanceWorkCalendar::create($data);
        $calendar->shifts()->sync($shiftIds);

        return redirect()->back()->with('success', 'Work calendar created.');
    }

    public function update(Request $request, AttendanceWorkCalendar $attendanceWorkCalendar)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'year'                    => 'required|integer|min:2020|max:2100',
            'monday'                  => 'boolean',
            'tuesday'                 => 'boolean',
            'wednesday'               => 'boolean',
            'thursday'                => 'boolean',
            'friday'                  => 'boolean',
            'saturday'                => 'boolean',
            'sunday'                  => 'boolean',
            'include_public_holidays' => 'boolean',
            'description'             => 'nullable|string',
            'is_default'              => 'boolean',
            'status'                  => 'integer|in:0,1',
            'shift_ids'               => 'nullable|array',
            'shift_ids.*'             => 'integer|exists:attendance_shifts,id',
        ]);

        if (!empty($data['is_default'])) {
            AttendanceWorkCalendar::where('is_default', true)
                ->where('id', '!=', $attendanceWorkCalendar->id)
                ->update(['is_default' => false]);
        }

        $shiftIds = $data['shift_ids'] ?? [];
        unset($data['shift_ids']);

        $attendanceWorkCalendar->update($data);
        $attendanceWorkCalendar->shifts()->sync($shiftIds);

        return redirect()->back()->with('success', 'Work calendar updated.');
    }

    public function destroy(AttendanceWorkCalendar $attendanceWorkCalendar)
    {
        $attendanceWorkCalendar->delete();

        return redirect()->back()->with('success', 'Work calendar deleted.');
    }
}
