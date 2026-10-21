<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Time & Attendance hubs: Dashboard + My Time (clock, WRITE) / Manage / Workforce (MODIFY) /
 * Insights / Compliance / Configure. Legacy duplicate ribbons (My Dashboard, Clocking Kiosk,
 * Rosters, Workforce Health, Policy & Compliance) are consolidated to a single card each.
 */
class AttendanceHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'time_attendance';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'attendance', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            'my_time' => [
                'name' => 'My Time', 'path' => 'attendance/my-time-hub', 'icon' => 'clock', 'tier' => self::WRITE,
                'title' => 'time_attendance.hubs.my_time.title', 'subtitle' => 'time_attendance.hubs.my_time.subtitle',
                'cards' => [
                    self::card('clock', 'attendance/clocking/kiosk', 'fingerprint', self::WRITE, 'green'),
                    self::card('badge_kiosk', 'attendance/badge-kiosk', 'qrcode', self::WRITE, 'teal'),
                ],
            ],
            'manage' => [
                'name' => 'Manage', 'path' => 'attendance/manage-hub', 'icon' => 'tools', 'tier' => self::MODIFY,
                'title' => 'time_attendance.hubs.manage.title', 'subtitle' => 'time_attendance.hubs.manage.subtitle',
                'cards' => [
                    self::card('people', 'attendance/people', 'account-group-outline', self::MODIFY, 'primary'),
                    self::card('roster', 'attendance/roster', 'calendar-month-outline', self::MODIFY, 'indigo'),
                    self::card('timesheets', 'attendance/timesheets', 'clock-check-outline', self::MODIFY, 'blue'),
                    self::card('records', 'attendance/records', 'table-clock', self::MODIFY, 'blue-grey'),
                    self::card('missed_clockings', 'attendance/clocking/missed', 'clock-alert-outline', self::MODIFY, 'orange'),
                    self::card('piecework', 'attendance/piecework', 'scale-balance', self::MODIFY, 'green'),
                    self::card('payroll_handoff', 'attendance/payroll-handoff', 'receipt', self::MODIFY, 'deep-purple'),
                    self::card('shifts', 'attendance/time-settings', 'calendar-clock-outline', self::MODIFY, 'teal'),
                ],
            ],
            'workforce' => [
                'name' => 'Workforce', 'path' => 'attendance/workforce-hub', 'icon' => 'users-group', 'tier' => self::MODIFY,
                'title' => 'time_attendance.hubs.workforce.title', 'subtitle' => 'time_attendance.hubs.workforce.subtitle',
                'cards' => [
                    self::card('workforce', 'attendance/workforce', 'account-group-outline', self::MODIFY, 'blue'),
                    self::card('productivity', 'attendance/productivity', 'chart-bar', self::MODIFY, 'green'),
                    self::card('wellness', 'attendance/wellness', 'heart-pulse', self::MODIFY, 'pink'),
                    self::card('discipline', 'attendance/discipline', 'gavel', self::MODIFY, 'red'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'attendance/insights-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'time_attendance.hubs.insights.title', 'subtitle' => 'time_attendance.hubs.insights.subtitle',
                'cards' => [
                    self::card('analytics', 'attendance/analytics', 'chart-line', self::READ, 'blue'),
                    self::card('intelligence', 'attendance/intelligence', 'brain', self::READ, 'deep-purple'),
                    self::card('reports', 'attendance/reports', 'file-chart-outline', self::READ, 'teal'),
                    self::card('executive', 'attendance/executive', 'chart-box-outline', self::MODIFY, 'indigo'),
                    self::card('heatmap', 'attendance/analytics/heatmap', 'grid', self::MODIFY, 'orange'),
                    self::card('absenteeism_cost', 'attendance/analytics/absenteeism-cost', 'cash-remove', self::MODIFY, 'red'),
                    self::card('scheduled_reports', 'attendance/reports/scheduled', 'calendar-clock-outline', self::MODIFY, 'blue-grey'),
                ],
            ],
            'compliance' => [
                'name' => 'Compliance', 'path' => 'attendance/compliance-hub', 'icon' => 'shield-check', 'tier' => self::MODIFY,
                'title' => 'time_attendance.hubs.compliance.title', 'subtitle' => 'time_attendance.hubs.compliance.subtitle',
                'cards' => [
                    self::card('policy', 'attendance/policy', 'file-document-outline', self::MODIFY, 'blue'),
                    self::card('bcea', 'attendance/bcea', 'scale-balance', self::MODIFY, 'green'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'attendance/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'time_attendance.hubs.configure.title', 'subtitle' => 'time_attendance.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'attendance/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('audit', 'attendance/audit', 'history', self::ADMIN, 'red'),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: these hubs fold into their neighbours.
            'manage' => ['absorb' => ['my_time'], 'name' => 'Time & Approvals'],
            'compliance' => ['absorb' => ['configure'], 'name' => 'Compliance & Settings'],
        ]);
    }
}
