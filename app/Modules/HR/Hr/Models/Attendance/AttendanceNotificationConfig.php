<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendanceNotificationConfig extends Model
{
    protected $table = 'attendance_notification_configs';

    protected $fillable = [
        'event', 'enabled', 'notify_employee', 'notify_manager', 'notify_hr',
        'channel', 'delay_minutes', 'custom_message',
        'teams_webhook_url', 'slack_webhook_url', 'whatsapp_enabled',
    ];

    protected $casts = [
        'enabled'          => 'boolean',
        'notify_employee'  => 'boolean',
        'notify_manager'   => 'boolean',
        'notify_hr'        => 'boolean',
        'whatsapp_enabled' => 'boolean',
    ];

    public const EVENTS = [
        'late_arrival',
        'absent',
        'overtime',
        'early_departure',
        'missing_clockout',
        'weekly_summary',
    ];

    public static function defaults(): array
    {
        return collect(self::EVENTS)->map(fn($event) => [
            'event'           => $event,
            'enabled'         => true,
            'notify_employee' => true,
            'notify_manager'  => true,
            'notify_hr'       => false,
            'channel'         => 'email',
            'delay_minutes'   => 0,
        ])->all();
    }
}
