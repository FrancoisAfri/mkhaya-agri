<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceStatusController extends Controller
{
    public function index()
    {
        return Inertia::render('HR/Attendance/Statuses/Index', [
            'statuses' => AttendanceStatus::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:80',
            'code'              => 'required|string|max:20|unique:attendance_statuses,code',
            'color'             => 'required|string|max:20',
            'is_paid'           => 'boolean',
            'is_present'        => 'boolean',
            'is_absent'         => 'boolean',
            'is_late'           => 'boolean',
            'is_leave'          => 'boolean',
            'is_public_holiday' => 'boolean',
            'status'            => 'integer|in:0,1',
        ]);

        AttendanceStatus::create($data);

        return redirect()->back()->with('success', 'Status created.');
    }

    public function update(Request $request, AttendanceStatus $attendanceStatus)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:80',
            'code'              => 'required|string|max:20|unique:attendance_statuses,code,' . $attendanceStatus->id,
            'color'             => 'required|string|max:20',
            'is_paid'           => 'boolean',
            'is_present'        => 'boolean',
            'is_absent'         => 'boolean',
            'is_late'           => 'boolean',
            'is_leave'          => 'boolean',
            'is_public_holiday' => 'boolean',
            'status'            => 'integer|in:0,1',
        ]);

        $attendanceStatus->update($data);

        return redirect()->back()->with('success', 'Status updated.');
    }

    public function destroy(AttendanceStatus $attendanceStatus)
    {
        $attendanceStatus->delete();

        return redirect()->back()->with('success', 'Status deleted.');
    }
}
