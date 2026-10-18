<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBreak;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDeviceLog;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Services\AttendanceClockingService;
use App\Modules\HR\Hr\Services\AttendancePolicyGuard;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceClockingController extends Controller
{
    public function __construct(
        private AttendanceClockingService $service,
        private AttendancePolicyGuard     $policyGuard,
    ) {}

    // ── Self-service kiosk/portal page ───────────────────────────────────────

    public function kiosk()
    {
        $user     = Auth::user();
        $employee = HRPerson::where('user_id', $user->id)->first();

        if (!$employee) {
            return Inertia::render('HR/Attendance/Clocking/Kiosk', [
                'employee'    => null,
                'today'       => now()->toDateString(),
                'clockState'  => null,
                'openBreak'   => null,
                'todayClockings' => [],
            ]);
        }

        $today        = now()->toDateString();
        $clockState   = $this->service->resolveClockTypePublic($employee->id);
        $openBreak    = AttendanceBreak::forEmployee($employee->id)->open()->latest('break_start')->first();

        $todayClockings = AttendanceClocking::forEmployee($employee->id)
            ->forDate($today)
            ->with('gpsLog')
            ->orderBy('clocked_at')
            ->get()
            ->map(fn($c) => [
                'id'        => $c->id,
                'type'      => $c->type,
                'method'    => $c->method,
                'work_type' => $c->work_type,
                'time'      => Carbon::parse($c->clocked_at)->format('H:i:s'),
                'location'  => $c->location,
                'is_missed' => $c->is_missed,
                'is_verified' => $c->is_verified,
            ]);

        $mapsKey = AppSetting::get('google_maps_api_key', '');

        return Inertia::render('HR/Attendance/Clocking/Kiosk', [
            'employee'       => ['id' => $employee->id, 'name' => $employee->full_name],
            'today'          => $today,
            'clockState'     => $clockState,
            'openBreak'      => $openBreak ? [
                'id'          => $openBreak->id,
                'break_type'  => $openBreak->break_type,
                'start_time'  => Carbon::parse($openBreak->break_start)->format('H:i'),
                'duration'    => (int) Carbon::parse($openBreak->break_start)->diffInMinutes(now()),
            ] : null,
            'todayClockings'   => $todayClockings,
            'googleMapsApiKey' => $mapsKey,
        ]);
    }

    // ── Manual clock-in ──────────────────────────────────────────────────────

    public function clockIn(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'work_type'   => 'string|in:office,remote,hybrid',
            'location'    => 'nullable|string',
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->policyGuard->forClockIn(
            employeeId: $data['employee_id'],
            method:     'manual',
            workType:   $data['work_type'] ?? 'office',
            clockedAt:  now()->toIso8601String(),
            latitude:   $data['latitude'] ?? null,
            longitude:  $data['longitude'] ?? null,
            actorId:    Auth::id() ?? 0,
        );

        $this->service->clockIn(
            $data['employee_id'],
            'manual',
            $data['work_type'] ?? 'office',
            $data
        );

        activity('attendance')
            ->causedBy(Auth::user())
            ->withProperties(['employee_id' => $data['employee_id'], 'work_type' => $data['work_type'] ?? 'office'])
            ->event('clock_in')
            ->log('Employee clocked in (manual)');

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    // ── Manual clock-out ─────────────────────────────────────────────────────

    public function clockOut(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->policyGuard->forClockOut(
            employeeId: $data['employee_id'],
            clockedAt:  now()->toIso8601String(),
            actorId:    Auth::id() ?? 0,
        );

        $this->service->clockOut($data['employee_id'], 'manual', $data);

        activity('attendance')
            ->causedBy(Auth::user())
            ->withProperties(['employee_id' => $data['employee_id']])
            ->event('clock_out')
            ->log('Employee clocked out (manual)');

        return redirect()->back()->with('success', 'Clocked out successfully.');
    }

    // ── GPS / Mobile clocking ─────────────────────────────────────────────────

    public function clockGps(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'accuracy'    => 'nullable|numeric',
            'address'     => 'nullable|string',
            'work_type'   => 'string|in:office,remote,hybrid',
        ]);

        $this->service->captureGPSAttendance(
            $data['employee_id'],
            $data['latitude'],
            $data['longitude'],
            $data['work_type'] ?? 'remote',
            $data
        );

        activity('attendance')
            ->causedBy(Auth::user())
            ->withProperties(['employee_id' => $data['employee_id'], 'latitude' => $data['latitude'], 'longitude' => $data['longitude']])
            ->event('clock_gps')
            ->log('Employee clocked via GPS');

        return redirect()->back()->with('success', 'GPS clocking recorded.');
    }

    // ── Work-from-home clocking ───────────────────────────────────────────────

    public function clockWfh(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'notes'       => 'nullable|string',
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'location'    => 'nullable|string|max:300',
        ]);

        $this->service->captureWorkFromHomeAttendance($data['employee_id'], $data);

        activity('attendance')
            ->causedBy(Auth::user())
            ->withProperties(['employee_id' => $data['employee_id'], 'location' => $data['location'] ?? null])
            ->event('clock_wfh')
            ->log('Employee clocked via Work From Home');

        return redirect()->back()->with('success', 'Work-from-home attendance recorded.');
    }

    // ── Break management ──────────────────────────────────────────────────────

    public function breakAction(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:hr_people,id',
            'action'      => 'required|string|in:start,end',
            'break_type'  => 'string|in:lunch,tea,personal,other',
            'notes'       => 'nullable|string',
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'location'    => 'nullable|string|max:300',
        ]);

        $this->service->captureBreakTime(
            $data['employee_id'],
            $data['action'],
            $data['break_type'] ?? 'other',
            $data['notes'] ?? null,
            isset($data['latitude'])  ? (float) $data['latitude']  : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['location'] ?? null,
        );

        $label = $data['action'] === 'start' ? 'Break started.' : 'Break ended.';

        activity('attendance')
            ->causedBy(Auth::user())
            ->withProperties(['employee_id' => $data['employee_id'], 'action' => $data['action'], 'break_type' => $data['break_type'] ?? 'other'])
            ->event('break_' . $data['action'])
            ->log('Employee break ' . $data['action'] . 'ed');

        return redirect()->back()->with('success', $label);
    }

    // ── Manager: missed clockings list ────────────────────────────────────────

    public function missedClockings(Request $request)
    {
        $date = $request->input('date', now()->subDay()->toDateString());

        $missed = AttendanceClocking::with(['employee'])
            ->where('is_missed', true)
            ->whereDate('clocked_at', $date)
            ->orderBy('clocked_at')
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'employee'    => $c->employee?->full_name ?? '—',
                'type'        => $c->type,
                'clocked_at'  => Carbon::parse($c->clocked_at)->format('d/m/Y H:i'),
                'notes'       => $c->notes,
                'is_corrected' => $c->is_corrected,
            ]);

        return Inertia::render('HR/Attendance/Clocking/MissedClockings', [
            'missed'       => $missed,
            'selectedDate' => $date,
        ]);
    }

    // ── Detect missed clockings (manual trigger) ──────────────────────────────

    public function detectMissed(Request $request)
    {
        $date   = $request->input('date', now()->subDay()->toDateString());
        $result = $this->service->detectMissedClocking($date);

        return redirect()->back()->with('success', "Detected {$result['detected']} missed clock-outs for {$date}.");
    }

    // ── Device logs ───────────────────────────────────────────────────────────

    public function deviceLogs()
    {
        $logs = AttendanceDeviceLog::with('employee')
            ->latest('captured_at')
            ->paginate(50)
            ->through(fn($l) => [
                'id'          => $l->id,
                'device_id'   => $l->device_id,
                'device_type' => $l->device_type,
                'device_name' => $l->device_name,
                'employee'    => $l->employee?->full_name ?? '—',
                'status'      => $l->status,
                'captured_at' => Carbon::parse($l->captured_at)->format('d/m/Y H:i'),
                'error'       => $l->error_message,
            ]);

        return Inertia::render('HR/Attendance/Clocking/DeviceLogs', [
            'logs' => $logs,
        ]);
    }
}
