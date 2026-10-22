<?php

namespace App\Modules\Agri\Support;

use App\Modules\Agri\Support\RoleCatalog as R;
use App\Support\Hubs\ModuleHubs;

/**
 * Navigation for Mkhaya Agri: the farm dashboard and four hubs, so the sidebar keeps to five
 * ribbons. Every card is READ tier; what decides whether a person sees a card is the farm
 * permission its page checks first, so a worker is not offered Finance only to be refused it. My
 * Work is left open: it accepts any of several permissions and is where a worker lands.
 */
class AgriHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'agri';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Farm Dashboard', 'path' => 'agri',
                'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'production' => [
                'name' => 'Farms & Production', 'path' => 'agri/production-hub', 'icon' => 'sprout', 'tier' => self::READ,
                'title' => 'agri.hubs.production.title', 'subtitle' => 'agri.hubs.production.subtitle',
                'cards' => [
                    self::card('farms', 'agri/farms', 'map-2', self::READ, 'green', self::needs(R::VIEW_FARMS)),
                    self::card('field_map', 'agri/field-map', 'vector-polygon', self::READ, 'light-green', self::needs(R::VIEW_FARMS)),
                    self::card('twin', 'agri/twin', 'map', self::READ, 'teal', self::needs(R::VIEW_FARMS)),
                    self::card('crop_health', 'agri/crop-health', 'satellite', self::READ, 'green', self::needs(R::VIEW_CROPS)),
                    self::card('crops', 'manufacturing/agriculture/crops', 'sprout', self::READ, 'light-green', self::needs(R::VIEW_CROPS)),
                    self::card('crop_intelligence', 'manufacturing/agriculture/crops/intelligence', 'radar', self::READ, 'deep-purple', self::needs(R::VIEW_CROPS)),
                    self::card('livestock', 'manufacturing/agriculture/livestock', 'cow', self::READ, 'brown', self::needs(R::VIEW_LIVESTOCK)),
                    self::card('weather', 'agri/weather', 'cloud', self::READ, 'blue', self::needs(R::VIEW_FARMS)),
                    // Shortcuts: crops, livestock, production cycles, the Command Centre and intelligence are
                    // Manufacturing & Production's screens; the farm opens them from here.
                    self::card('production_cycles', 'manufacturing/agriculture/cycles', 'refresh', self::READ, 'deep-orange', self::needs(R::VIEW_FARMS)),
                    self::card('command_centre', 'manufacturing/command/centre', 'radar', self::READ, 'red', self::needs(R::VIEW_FARMS)),
                    self::card('intelligence', 'manufacturing/intelligence/recommendations', 'brain', self::READ, 'deep-purple', self::needs(R::VIEW_FARMS)),
                ],
            ],
            'operations' => [
                'name' => 'Operations', 'path' => 'agri/operations-hub', 'icon' => 'tractor', 'tier' => self::READ,
                'title' => 'agri.hubs.operations.title', 'subtitle' => 'agri.hubs.operations.subtitle',
                'cards' => [
                    self::card('my_work', 'agri/my-work', 'farm-phone', self::READ, 'primary'),
                    self::card('work_orders', 'agri/work-orders', 'clipboard-list', self::READ, 'indigo', self::needs(R::VIEW_WORK_ORDERS)),
                    self::card('tasks', 'agri/tasks', 'clipboard-check', self::READ, 'cyan', self::needs(R::VIEW_TASKS)),
                    self::card('machinery', 'agri/machinery', 'tractor', self::READ, 'orange', self::needs(R::VIEW_MACHINERY)),
                    self::card('stock', 'agri/stock', 'farm-stock', self::READ, 'blue-grey', self::needs(R::VIEW_STOCK)),
                    // Grading, packing and processing are Manufacturing production runs: a shortcut to the packhouse there.
                    self::card('packhouse', 'manufacturing/agriculture/packhouse', 'package', self::READ, 'amber', self::needs(R::VIEW_STOCK)),
                    self::card('alerts', 'agri/alerts', 'bell', self::READ, 'red', self::needs(R::VIEW_FARMS)),
                    self::card('finance', 'agri/finance', 'farm-money', self::READ, 'green', self::needs(R::VIEW_FINANCE)),
                    self::card('sales', 'agri/sales', 'building-store', self::READ, 'orange', self::needs(R::VIEW_SALES)),
                    self::card('outgrowers', 'agri/outgrowers', 'users', self::READ, 'teal', self::needs(R::VIEW_OUTGROWERS)),
                    self::card('assistant', 'agri/assistant', 'robot-farm', self::READ, 'deep-purple', self::needs(R::USE_ASSISTANT)),
                    self::card('integrations', 'agri/integrations', 'plug', self::READ, 'blue-grey', self::needs(R::MANAGE_INTEGRATIONS)),
                    self::card('settings', 'agri/settings', 'settings', self::READ, 'grey', self::needs(R::VIEW_FARMS)),
                ],
            ],
            // Shortcuts to the farm's people and compliance: the screens are Time & Attendance's, SHEQ's
            // and Document Management's, opened on the farm.
            'people' => [
                'name' => 'People & Compliance', 'path' => 'agri/people-hub', 'icon' => 'users-group', 'tier' => self::READ,
                'title' => 'agri.hubs.people.title', 'subtitle' => 'agri.hubs.people.subtitle',
                'cards' => [
                    self::card('workforce', 'attendance/people', 'users', self::READ, 'primary', self::needs(R::VIEW_WORKFORCE)),
                    self::card('roster', 'attendance/roster', 'calendar-time', self::READ, 'indigo', self::needs(R::VIEW_WORKFORCE)),
                    self::card('attendance', 'attendance/timesheets', 'clock', self::READ, 'teal', self::needs(R::VIEW_WORKFORCE)),
                    self::card('piecework', 'attendance/piecework', 'cash', self::READ, 'green', self::needs(R::VIEW_WORKFORCE)),
                    self::card('payroll', 'attendance/payroll-handoff', 'receipt', self::READ, 'deep-purple', self::needs(R::PAYROLL_HANDOFF)),
                    self::card('compliance', 'sheq/legal-register', 'shield-check', self::READ, 'red', self::needs(R::VIEW_FARMS)),
                    self::card('documents', 'document-management?relation=farm', 'certificate', self::READ, 'blue-grey', self::needs(R::VIEW_DOCUMENTS)),
                ],
            ],
            // Insights: the farm's dashboards, outcomes, reports and analytics, and Farm Operations' Insights page.
            'insights' => [
                'name' => 'Insights', 'path' => 'agri/insights-hub', 'icon' => 'chart-pie', 'tier' => self::READ,
                'title' => 'agri.hubs.insights.title', 'subtitle' => 'agri.hubs.insights.subtitle',
                'cards' => [
                    self::card('insights', 'agri/insights', 'chart-bar', self::READ, 'primary', self::needs(R::VIEW_FARMS)),
                    self::card('outcomes', 'agri/outcomes', 'chart-line', self::READ, 'indigo', self::needs(R::VIEW_REPORTS)),
                    self::card('reports', 'agri/reports', 'chart-bar', self::READ, 'blue', self::needs(R::VIEW_REPORTS)),
                    self::card('finance_intelligence', 'agri/finance/intelligence', 'report-money', self::READ, 'green', self::needs(R::VIEW_FINANCE)),
                    self::card('workforce_analytics', 'agri/workforce/analytics', 'chart-histogram', self::READ, 'teal', self::needs(R::VIEW_WORKFORCE)),
                    self::card('all_insights', 'insights/farm-operations', 'chart-dots', self::READ, 'deep-purple', self::needs(R::VIEW_REPORTS)),
                ],
            ],
        ];
    }

    /** The farm permission a card's page checks before anything else. */
    private static function needs(string $permission): array
    {
        return ['ability' => $permission, 'subject' => null];
    }
}
