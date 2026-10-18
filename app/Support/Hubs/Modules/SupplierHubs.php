<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Supplier Management hubs: Directory (onboarding + KYC), Performance, Sourcing & Spend,
 * Insights and Configure. Every ribbon of this module is READ tier today, so the cards keep
 * READ rather than inventing new gates.
 */
class SupplierHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'supplier_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'suppliers', 'icon' => 'tachometer-alt', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'directory' => [
                'name' => 'Suppliers', 'path' => 'suppliers/directory-hub', 'icon' => 'building', 'tier' => self::READ,
                'title' => 'supplier_management.hubs.directory.title', 'subtitle' => 'supplier_management.hubs.directory.subtitle',
                'cards' => [
                    self::card('suppliers', 'suppliers/list', 'domain', self::READ, 'indigo'),
                    self::card('add_supplier', 'suppliers/create', 'plus-circle-outline', self::READ, 'green'),
                    self::card('onboarding', 'suppliers/onboarding', 'account-plus-outline', self::READ, 'blue'),
                    self::card('kyc', 'suppliers/kyc', 'shield-check-outline', self::READ, 'teal'),
                    // The queue a compliance officer works from — only for tenants on the add-on.
                    self::card('monitoring', 'suppliers/monitoring', 'radar', self::READ, 'deep-orange',
                        gate: ['ability' => 'supplier-due-diligence', 'subject' => null]),
                ],
            ],
            'performance' => [
                'name' => 'Performance', 'path' => 'suppliers/performance-hub', 'icon' => 'star', 'tier' => self::READ,
                'title' => 'supplier_management.hubs.performance.title', 'subtitle' => 'supplier_management.hubs.performance.subtitle',
                'cards' => [
                    self::card('appraisals', 'suppliers/appraisals', 'star-outline', self::READ, 'amber'),
                    self::card('scars', 'suppliers/scars', 'alert-outline', self::READ, 'orange'),
                ],
            ],
            'sourcing' => [
                'name' => 'Sourcing & Spend', 'path' => 'suppliers/sourcing-hub', 'icon' => 'gavel', 'tier' => self::READ,
                'title' => 'supplier_management.hubs.sourcing.title', 'subtitle' => 'supplier_management.hubs.sourcing.subtitle',
                'cards' => [
                    self::card('sourcing', 'suppliers/sourcing', 'gavel', self::READ, 'deep-purple'),
                    self::card('purchase_orders', 'suppliers/purchase-orders', 'file-document-outline', self::READ, 'blue'),
                    self::card('invoices', 'suppliers/invoices', 'receipt', self::READ, 'cyan'),
                    self::card('spend', 'suppliers/spend/report', 'chart-pie', self::READ, 'pink'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'suppliers/insights-hub', 'icon' => 'chart-bar', 'tier' => self::READ,
                'title' => 'supplier_management.hubs.insights.title', 'subtitle' => 'supplier_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('reports', 'suppliers/reports', 'chart-bar', self::READ, 'blue'),
                    self::card('analytics', 'suppliers/analytics', 'chart-scatter-plot', self::READ, 'deep-purple'),
                    self::card('scheduled_reports', 'suppliers/scheduled-reports', 'calendar-clock', self::READ, 'teal'),
                    self::card('search', 'suppliers/search', 'magnify', self::READ, 'blue-grey'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'suppliers/configure-hub', 'icon' => 'cog', 'tier' => self::READ,
                'title' => 'supplier_management.hubs.configure.title', 'subtitle' => 'supplier_management.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'suppliers/settings', 'cog-outline', self::READ, 'blue-grey'),
                    // Only for tenants on the due-diligence add-on: a card that leads to a 403 is
                    // worse than one that is not there.
                    self::card('data_sources', 'suppliers/settings/data-sources', 'database-cog', self::READ, 'indigo',
                        gate: ['ability' => 'supplier-due-diligence', 'subject' => null]),
                ],
            ],
        ];
    }
}
