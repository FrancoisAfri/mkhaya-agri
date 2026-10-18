<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceNotificationConfig;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Services\ReadErsDetails;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceSettingsController extends Controller
{
    public function index()
    {
        $configs = AttendanceNotificationConfig::all()->keyBy('event');

        $notifications = collect(AttendanceNotificationConfig::EVENTS)->map(
            fn($event) => $configs->get($event)?->toArray() ?? array_merge(
                ['event' => $event, 'enabled' => true, 'notify_employee' => true,
                 'notify_manager' => true, 'notify_hr' => false, 'channel' => 'email', 'delay_minutes' => 0],
            )
        );

        return Inertia::render('HR/Attendance/Settings/Index', [
            'settings'      => AttendanceSetting::current(),
            'notifications' => $notifications->values(),
        ]);
    }

public function updateSettings(Request $request)
{
    $request->merge([
        'work_start_time'     => substr($request->work_start_time, 0, 5),
        'work_end_time'       => substr($request->work_end_time, 0, 5),
        'auto_clock_out_time' => $request->auto_clock_out_time
            ? substr($request->auto_clock_out_time, 0, 5)
            : null,
    ]);

    $data = $request->validate([
        'work_start_time'                   => 'required|date_format:H:i',
        'work_end_time'                     => 'required|date_format:H:i',
        'grace_period_minutes'              => 'required|integer|min:0|max:60',
        'overtime_threshold_minutes'        => 'required|integer|min:0|max:120',
        'early_departure_threshold_minutes' => 'required|integer|min:0|max:60',
        'late_policy_enabled'               => 'boolean',
        'overtime_enabled'                  => 'boolean',
        'auto_clock_out_enabled'            => 'boolean',
        'auto_clock_out_time'               => 'nullable|date_format:H:i',
        'require_shift_assignment'          => 'boolean',
        'allow_manual_entry'                => 'boolean',
        'manager_approval_required'         => 'boolean',
        'working_days_per_week'             => 'required|integer|min:1|max:7',
        'working_hours_per_day'             => 'required|integer|min:1|max:24',
        'timezone'                          => 'required|string',
        'use_employee_work_hours'           => 'boolean',
    ]);

    $settings = AttendanceSetting::current();
    $settings->update($data);

    return redirect()->back()->with('success', 'Attendance settings saved.');
}

public function updateErsSettings(Request $request)
{
    $request->merge([
        'ers_clock_start_time' => substr($request->ers_clock_start_time, 0, 5),
        'ers_clock_end_time'   => substr($request->ers_clock_end_time, 0, 5),
    ]);

    $data = $request->validate([
        'ers_enabled'                   => 'boolean',
        'ers_token'                     => 'nullable|string|max:500',
        'ers_api_url'                   => 'nullable|url|max:500',
        'ers_clock_start_time'          => 'required|date_format:H:i',
        'ers_clock_end_time'            => 'required|date_format:H:i',
        'absent_email_enabled'          => 'boolean',
        'auto_apply_leave_enabled'      => 'boolean',
        'auto_apply_days_threshold'     => 'required|integer|min:0|max:30',
        'auto_deduct_leave'             => 'boolean',
        'allow_unpaid_when_annual_done' => 'boolean',
    ]);

    $settings = AttendanceSetting::current();
    $settings->update($data);

    return redirect()->back()->with('success', 'ERS integration settings saved.');
}

    public function testErsConnection()
    {
        try {
            $service = app(ReadErsDetails::class);
            $resp    = $service->connectToErs();
            $count   = isset($resp['Raw_Clocks']) ? count($resp['Raw_Clocks']) : 0;
            return redirect()->back()->with('success', "ERS connection successful. {$count} clock record(s) returned.");
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'ERS connection failed: ' . $e->getMessage());
        }
    }

    public function updateNotifications(Request $request)
    {
        $items = $request->validate([
            'notifications'                    => 'required|array',
            'notifications.*.event'            => 'required|string|in:' . implode(',', AttendanceNotificationConfig::EVENTS),
            'notifications.*.enabled'          => 'boolean',
            'notifications.*.notify_employee'  => 'boolean',
            'notifications.*.notify_manager'   => 'boolean',
            'notifications.*.notify_hr'        => 'boolean',
            'notifications.*.channel'          => 'string|in:email,sms,both',
            'notifications.*.delay_minutes'    => 'integer|min:0',
            'notifications.*.custom_message'   => 'nullable|string',
        ])['notifications'];

        foreach ($items as $item) {
            AttendanceNotificationConfig::updateOrCreate(
                ['event' => $item['event']],
                $item,
            );
        }

        return redirect()->back()->with('success', 'Notification settings saved.');
    }
}
