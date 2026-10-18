<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\Attendance\AttendanceNotificationConfig;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Notifications\AttendanceEventNotification;
use Illuminate\Support\Facades\Notification;

class AttendanceNotificationService
{
    /**
     * Dispatch an attendance notification according to the stored config.
     *
     * @param  string   $event       One of AttendanceNotificationConfig::EVENTS
     * @param  int      $employeeId  The affected hr_people.id
     * @param  string   $message     Body of the notification
     * @param  string|null $url      Optional deep-link URL
     * @param  array    $extra       Any extra message context (e.g. date, minutes)
     */
    public function dispatch(
        string  $event,
        int     $employeeId,
        string  $message,
        ?string $url = null,
        array   $extra = []
    ): void {
        $config = $this->config($event);

        if (!$config || !$config['enabled']) {
            return;
        }

        $employee = HRPerson::with('user')->find($employeeId);
        if (!$employee) return;

        $title   = $this->titleFor($event, $extra);
        $channel = $config['channel'] ?? 'email';

        $recipients = collect();

        if ($config['notify_employee'] && $employee->user) {
            $recipients->push($employee->user);
        }

        if ($config['notify_manager']) {
            $manager = $this->resolveManager($employee);
            if ($manager) $recipients->push($manager);
        }

        if ($config['notify_hr']) {
            User::role(['hr', 'super admin'])->get()->each(fn($u) => $recipients->push($u));
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) return;

        $notifChannel = $channel;
        if (($config['whatsapp_enabled'] ?? false) && $channel === 'email') {
            $notifChannel = 'all';
        }

        $notification = new AttendanceEventNotification(
            event:   $event,
            title:   $title,
            message: $message,
            url:     $url,
            channel: $notifChannel,
        );

        Notification::send($recipients, $notification);

        // Outbound webhooks — fired once per event regardless of recipient list.
        $this->fireWebhooks($config, $title, $message, $url);
    }

    private function fireWebhooks(array $config, string $title, string $message, ?string $url): void
    {
        $webhook = app(AttendanceWebhookService::class);

        if (!empty($config['teams_webhook_url'])) {
            rescue(fn() => $webhook->sendTeams($config['teams_webhook_url'], $title, $message, $url));
        }

        if (!empty($config['slack_webhook_url'])) {
            rescue(fn() => $webhook->sendSlack($config['slack_webhook_url'], $title, $message, $url));
        }
    }

    // ── Convenience wrappers ─────────────────────────────────────────────────

    public function lateArrival(int $employeeId, string $date, int $lateMinutes): void
    {
        $this->dispatch(
            'late_arrival',
            $employeeId,
            "You arrived {$lateMinutes} minute(s) late on {$date}.",
            '/attendance/records',
            ['date' => $date, 'minutes' => $lateMinutes]
        );
    }

    public function earlyDeparture(int $employeeId, string $date, int $earlyMinutes): void
    {
        $this->dispatch(
            'early_departure',
            $employeeId,
            "You left {$earlyMinutes} minute(s) early on {$date}.",
            '/attendance/records',
            ['date' => $date, 'minutes' => $earlyMinutes]
        );
    }

    public function overtimeDetected(int $employeeId, string $date, float $overtimeHours): void
    {
        $this->dispatch(
            'overtime',
            $employeeId,
            "Overtime of {$overtimeHours}h detected on {$date}. Please ensure this is approved.",
            '/attendance/records',
            ['date' => $date, 'hours' => $overtimeHours]
        );
    }

    public function absent(int $employeeId, string $date): void
    {
        $this->dispatch(
            'absent',
            $employeeId,
            "No clock-in recorded for {$date}. Please apply for leave if you were absent.",
            '/leave-management/my-applications',
            ['date' => $date]
        );
    }

    public function missingClockOut(int $employeeId, string $date): void
    {
        $this->dispatch(
            'missing_clockout',
            $employeeId,
            "You did not clock out on {$date}. Your clock-out has been auto-set. Please review.",
            '/attendance/clocking/kiosk',
            ['date' => $date]
        );
    }

    public function weeklySummary(int $employeeId, array $stats): void
    {
        $this->dispatch(
            'weekly_summary',
            $employeeId,
            "Your weekly attendance summary: {$stats['days_present']} day(s) present, "
            . "{$stats['late_arrivals']} late arrival(s), {$stats['overtime_hours']}h overtime.",
            '/attendance/records',
            $stats
        );
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function config(string $event): ?array
    {
        static $cache = [];
        if (!isset($cache[$event])) {
            $record = AttendanceNotificationConfig::where('event', $event)->first();
            $cache[$event] = $record ? $record->toArray() : [
                'event'           => $event,
                'enabled'         => true,
                'notify_employee' => true,
                'notify_manager'  => true,
                'notify_hr'       => false,
                'channel'         => 'email',
                'delay_minutes'   => 0,
            ];
        }
        return $cache[$event];
    }

    private function titleFor(string $event, array $extra): string
    {
        return match ($event) {
            'late_arrival'    => 'Late Arrival',
            'absent'          => 'Absent — No Clock-in',
            'overtime'        => 'Overtime Detected',
            'early_departure' => 'Early Departure',
            'missing_clockout'=> 'Missing Clock-out',
            'weekly_summary'  => 'Weekly Attendance Summary',
            default           => 'Attendance Notice',
        };
    }

    private function resolveManager(HRPerson $employee): ?User
    {
        // Try to find a manager/supervisor in the same division
        $dept = $employee->division_level_1;
        if (!$dept) return null;

        return User::whereHas('roles', fn($q) => $q->whereIn('name', ['manager', 'supervisor']))
            ->whereHas('hrPerson', fn($q) => $q->where('division_level_1', $dept))
            ->first();
    }
}
