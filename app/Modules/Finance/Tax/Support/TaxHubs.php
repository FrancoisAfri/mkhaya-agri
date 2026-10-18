<?php

namespace App\Modules\Finance\Tax\Support;

use App\Support\Hubs\ModuleHubs;

/**
 * Tax Management navigation (§3): a dashboard, then hubs grouped by how the work is done —
 * set up, prepare, reconcile and comply, govern. Every card states the tier its route needs.
 */
class TaxHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'tax_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Tax Dashboard', 'path' => 'finance/tax', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'setup' => [
                'name' => 'Tax Setup', 'path' => 'finance/tax/setup', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'tax_management.hubs.setup.title', 'subtitle' => 'tax_management.hubs.setup.subtitle',
                'cards' => [
                    self::card('registrations', 'finance/tax/registrations', 'certificate', self::ADMIN, 'primary'),
                    self::card('rules', 'finance/tax/rules', 'percentage', self::ADMIN, 'indigo'),
                    self::card('import', 'finance/tax/import', 'file-import', self::ADMIN, 'teal'),
                    self::card('admin', 'finance/tax/admin', 'settings', self::ADMIN, 'blue-grey'),
                ],
            ],
            'work' => [
                'name' => 'Tax Work', 'path' => 'finance/tax/work', 'icon' => 'file-invoice', 'tier' => self::WRITE,
                'title' => 'tax_management.hubs.work.title', 'subtitle' => 'tax_management.hubs.work.subtitle',
                'cards' => [
                    self::card('periods', 'finance/tax/periods', 'calendar-stats', self::WRITE, 'blue'),
                    self::card('transactions', 'finance/tax/transactions', 'list-details', self::WRITE, 'teal'),
                    self::card('reconciliations', 'finance/tax/reconciliations', 'scale-balance', self::WRITE, 'orange'),
                    self::card('returns', 'finance/tax/returns', 'file-invoice', self::WRITE, 'indigo'),
                    self::card('income_tax', 'finance/tax/income-tax', 'calculator', self::WRITE, 'deep-purple'),
                    self::card('liabilities', 'finance/tax/liabilities', 'receipt', self::WRITE, 'red'),
                ],
            ],
            'compliance' => [
                'name' => 'Compliance', 'path' => 'finance/tax/compliance', 'icon' => 'calendar-due', 'tier' => self::READ,
                'title' => 'tax_management.hubs.compliance.title', 'subtitle' => 'tax_management.hubs.compliance.subtitle',
                'cards' => [
                    self::card('calendar', 'finance/tax/calendar', 'calendar-event', self::READ, 'green'),
                    self::card('reports', 'finance/tax/reports', 'file-text', self::READ, 'blue'),
                    self::card('audit', 'finance/tax/audit', 'shield-search', self::MODIFY, 'red'),
                ],
            ],
        ];
    }
}
