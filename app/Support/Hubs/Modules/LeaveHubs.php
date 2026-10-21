<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Leave Management hubs: Dashboard + My Leave (employee, READ/WRITE) / Approvals (manager, MODIFY) /
 * Workforce (HR, ADMIN) / Insights / Configure (ADMIN). Managers approve their team; only HR
 * allocates, imports and configures. The `leave-management/setup` guard floors config at ADMIN.
 * Legacy duplicate ribbons (My Dashboard, Pending Approvals, Team Calendar) are consolidated.
 */
class LeaveHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'leave_management';
    }

    public static function guards(): array
    {
        return ['leave-management/setup' => self::ADMIN];
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'leave-management', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            'my_leave' => [
                'name' => 'My Leave', 'path' => 'leave-management/my-leave-hub', 'icon' => 'calendar-user', 'tier' => self::READ,
                'title' => 'leave_management.hubs.my_leave.title', 'subtitle' => 'leave_management.hubs.my_leave.subtitle',
                'cards' => [
                    self::card('my_leave', 'leave-management/my-applications', 'calendar-check-outline', self::READ, 'blue'),
                    self::card('apply', 'leave-management/apply', 'calendar-plus', self::WRITE, 'green'),
                    self::card('balances', 'leave-management/balances', 'scale-balance', self::READ, 'teal'),
                ],
            ],
            'approvals' => [
                'name' => 'Approvals', 'path' => 'leave-management/approvals-hub', 'icon' => 'checklist', 'tier' => self::MODIFY,
                'title' => 'leave_management.hubs.approvals.title', 'subtitle' => 'leave_management.hubs.approvals.subtitle',
                'cards' => [
                    self::card('approvals', 'leave-management/approvals', 'clipboard-check-outline', self::MODIFY, 'green'),
                    self::card('calendar', 'leave-management/calendar', 'calendar-month-outline', self::MODIFY, 'indigo'),
                    self::card('apply_for_staff', 'leave-management/apply-for-staff', 'calendar-account-outline', self::MODIFY, 'blue'),
                    self::card('manager_dashboard', 'leave-management/manager-dashboard', 'view-dashboard-outline', self::MODIFY, 'teal'),
                ],
            ],
            'workforce' => [
                'name' => 'Workforce', 'path' => 'leave-management/workforce-hub', 'icon' => 'users-group', 'tier' => self::ADMIN,
                'title' => 'leave_management.hubs.workforce.title', 'subtitle' => 'leave_management.hubs.workforce.subtitle',
                'cards' => [
                    self::card('hr_dashboard', 'leave-management/hr-dashboard', 'account-group-outline', self::ADMIN, 'blue'),
                    self::card('workforce_dashboard', 'leave-management/workforce-dashboard', 'chart-box-outline', self::ADMIN, 'deep-purple'),
                    self::card('manual_allocations', 'leave-management/manual-allocations', 'hand-coin-outline', self::ADMIN, 'orange'),
                    self::card('import', 'leave-management/import', 'database-import-outline', self::ADMIN, 'teal'),
                    self::card('absence_upload', 'leave-management/absence-upload', 'calendar-remove-outline', self::ADMIN, 'red'),
                    self::card('executive', 'leave-management/executive-dashboard', 'chart-line', self::ADMIN, 'indigo'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'leave-management/insights-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'leave_management.hubs.insights.title', 'subtitle' => 'leave_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'leave-management/reports', 'file-chart-outline', self::READ, 'blue'),
                    self::card('anomalies', 'leave-management/anomalies', 'alert-hexagon-outline', self::ADMIN, 'red'),
                    self::card('scheduled_reports', 'leave-management/setup/scheduled-reports', 'calendar-clock-outline', self::MODIFY, 'orange'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'leave-management/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'leave_management.hubs.configure.title', 'subtitle' => 'leave_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('leave_types', 'leave-management/setup/leave-types', 'format-list-bulleted-type', self::ADMIN, 'blue'),
                    self::card('configuration', 'leave-management/setup/configuration', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('profiles', 'leave-management/setup/profiles', 'account-cog-outline', self::ADMIN, 'teal'),
                    self::card('approval_config', 'leave-management/setup/approval-config', 'clipboard-check-outline', self::ADMIN, 'green'),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: these hubs fold into their neighbours.
            'insights' => ['absorb' => ['configure'], 'name' => 'Insights & Settings'],
        ]);
    }
}
