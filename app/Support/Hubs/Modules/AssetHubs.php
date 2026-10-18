<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/** Asset Management hubs: Dashboard + Register / Maintenance / Finance / Insights / Configure. */
class AssetHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'asset_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'assets/dashboard', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'register' => [
                'name' => 'Register', 'path' => 'assets/register-hub', 'icon' => 'clipboard-list', 'tier' => self::WRITE,
                'title' => 'asset_management.hubs.register.title', 'subtitle' => 'asset_management.hubs.register.subtitle',
                'cards' => [
                    self::card('asset_register', 'assets', 'package-variant-closed', self::WRITE, 'blue'),
                    self::card('scan', 'assets/scan', 'barcode-scan', self::WRITE, 'indigo'),
                    self::card('import', 'assets/import', 'database-import-outline', self::MODIFY, 'teal'),
                    self::card('audits', 'assets/audits', 'clipboard-check-outline', self::MODIFY, 'green'),
                    self::card('exit_clearances', 'assets/exit-clearances', 'exit-run', self::MODIFY, 'orange'),
                ],
            ],
            'maintenance' => [
                'name' => 'Maintenance', 'path' => 'assets/maintenance-hub', 'icon' => 'tools', 'tier' => self::WRITE,
                'title' => 'asset_management.hubs.maintenance.title', 'subtitle' => 'asset_management.hubs.maintenance.subtitle',
                'cards' => [
                    self::card('work_orders', 'assets/work-orders', 'wrench-outline', self::WRITE, 'blue'),
                    self::card('maintenance', 'assets/maintenance', 'wrench-clock', self::WRITE, 'indigo'),
                    self::card('parts', 'assets/parts', 'cog-outline', self::WRITE, 'blue-grey'),
                    self::card('misuse', 'assets/misuse-flags', 'alert-outline', self::MODIFY, 'red'),
                    self::card('doc_extraction', 'assets/document-extractions', 'file-search-outline', self::MODIFY, 'teal'),
                ],
            ],
            'finance' => [
                'name' => 'Finance', 'path' => 'assets/finance-hub', 'icon' => 'coin', 'tier' => self::MODIFY,
                'title' => 'asset_management.hubs.finance.title', 'subtitle' => 'asset_management.hubs.finance.subtitle',
                'cards' => [
                    self::card('depreciation', 'assets/depreciation', 'trending-down', self::MODIFY, 'red'),
                    self::card('procurement', 'assets/procurement', 'cart-outline', self::MODIFY, 'green'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'assets/insights-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'asset_management.hubs.insights.title', 'subtitle' => 'asset_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('utilization', 'assets/utilization-dashboard', 'chart-donut', self::READ, 'blue'),
                    self::card('compliance', 'assets/compliance-dashboard', 'shield-check-outline', self::READ, 'green'),
                    self::card('reports', 'assets/reports', 'file-chart-outline', self::READ, 'teal'),
                    self::card('scheduled_reports', 'assets/settings/scheduled-reports', 'calendar-clock-outline', self::MODIFY, 'orange'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'assets/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'asset_management.hubs.configure.title', 'subtitle' => 'asset_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'assets/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('categories', 'assets/settings/categories', 'shape-outline', self::ADMIN, 'indigo'),
                    self::card('types', 'assets/settings/types', 'format-list-bulleted-type', self::ADMIN, 'teal'),
                    self::card('locations', 'assets/settings/locations', 'map-marker-outline', self::ADMIN, 'green'),
                ],
            ],
        ];
    }
}
