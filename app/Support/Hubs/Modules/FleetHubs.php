<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/** Fleet Management hubs: Dashboard + My Fleet / Approvals / Manage / Insights / Configure. */
class FleetHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'fleet_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'fleet', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'my_fleet' => [
                'name' => 'My Fleet', 'path' => 'fleet/my-fleet-hub', 'icon' => 'layout-grid', 'tier' => self::READ,
                'title' => 'fleet_management.hubs.my_fleet.title', 'subtitle' => 'fleet_management.hubs.my_fleet.subtitle',
                'cards' => [
                    self::card('my_dashboard', 'fleet/my-dashboard', 'view-dashboard-outline', self::READ, 'blue'),
                    self::card('bookings', 'fleet/bookings', 'calendar-search', self::WRITE, 'indigo'),
                    self::card('security_check', 'fleet/security-check', 'shield-check-outline', self::WRITE, 'green'),
                    self::card('my_job_cards', 'fleet/job-cards/my-cards', 'clipboard-account-outline', self::READ, 'deep-purple'),
                ],
            ],
            'approvals' => [
                'name' => 'Approvals', 'path' => 'fleet/approvals-hub', 'icon' => 'checklist', 'tier' => self::MODIFY,
                'title' => 'fleet_management.hubs.approvals.title', 'subtitle' => 'fleet_management.hubs.approvals.subtitle',
                'cards' => [
                    self::card('bookings_approval', 'fleet/approvals', 'clipboard-check-outline', self::MODIFY, 'green'),
                    self::card('vehicle_approvals', 'fleet/vehicles/pending-approvals', 'car-cog', self::MODIFY, 'orange'),
                    self::card('job_card_approvals', 'fleet/job-cards/approvals', 'clipboard-list-outline', self::MODIFY, 'purple'),
                ],
            ],
            'manage' => [
                'name' => 'Manage', 'path' => 'fleet/manage-hub', 'icon' => 'tools', 'tier' => self::MODIFY,
                'title' => 'fleet_management.hubs.manage.title', 'subtitle' => 'fleet_management.hubs.manage.subtitle',
                'cards' => [
                    self::card('vehicles', 'fleet/vehicles', 'car-multiple', self::MODIFY, 'blue'),
                    // A 286-line driver register that no hub offered and nothing linked to.
                    self::card('drivers', 'fleet/drivers', 'card-account-details-outline', self::MODIFY, 'cyan',
                        titleKey: 'fleet_drivers_index.page_title', descKey: 'fleet_drivers_index.description'),
                    self::card('job_cards', 'fleet/job-cards', 'clipboard-text-outline', self::MODIFY, 'deep-purple'),
                    self::card('cards', 'fleet/cards', 'credit-card-outline', self::MODIFY, 'indigo'),
                    self::card('projects', 'fleet/projects', 'folder-outline', self::MODIFY, 'teal'),
                    self::card('alerts', 'fleet/alerts', 'bell-alert-outline', self::MODIFY, 'red'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'fleet/insights-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'fleet_management.hubs.insights.title', 'subtitle' => 'fleet_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'fleet/reports', 'file-chart-outline', self::READ, 'blue'),
                    self::card('analytics', 'fleet/analytics', 'chart-line', self::READ, 'green'),
                    self::card('scheduled_reports', 'fleet/reports/scheduled', 'calendar-clock-outline', self::MODIFY, 'orange'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'fleet/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'fleet_management.hubs.configure.title', 'subtitle' => 'fleet_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('setup', 'fleet/setup', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('job_cards_setup', 'fleet/job-cards/setup', 'clipboard-edit-outline', self::ADMIN, 'deep-purple'),
                    self::card('integrations', 'fleet/integrations', 'connection', self::ADMIN, 'cyan'),
                ],
            ],
        ];
    }
}
