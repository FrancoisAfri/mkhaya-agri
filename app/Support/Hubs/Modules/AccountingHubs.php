<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/** Accounting (GL) hubs: Dashboard + Sales / Purchases / Ledger / Reporting / Configure. */
class AccountingHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'accounting';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'path' => 'finance/accounting/dashboard', 'icon' => 'layout-dashboard', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            'sales' => [
                'name' => 'Sales', 'path' => 'finance/accounting/sales-hub', 'icon' => 'coin', 'tier' => self::WRITE,
                'title' => 'accounting.hubs.sales.title', 'subtitle' => 'accounting.hubs.sales.subtitle',
                'cards' => [
                    self::card('customers', 'finance/accounting/customers', 'account-cash-outline', self::WRITE, 'blue'),
                    self::card('quotes', 'finance/accounting/quotes', 'file-document-outline', self::WRITE, 'indigo'),
                    self::card('sales_orders', 'finance/accounting/sales-orders', 'cart-outline', self::WRITE, 'teal'),
                    self::card('recurring', 'finance/accounting/recurring', 'repeat', self::WRITE, 'cyan'),
                    self::card('price_lists', 'finance/accounting/price-lists', 'tag-outline', self::WRITE, 'green'),
                    self::card('contracts', 'finance/accounting/contracts', 'file-sign', self::WRITE, 'blue-grey'),
                    self::card('receivables', 'finance/accounting/receivables', 'cash-plus', self::MODIFY, 'green'),
                    self::card('debtors', 'finance/accounting/debtors', 'account-alert-outline', self::MODIFY, 'orange'),
                    self::card('disputes', 'finance/accounting/disputes', 'message-alert-outline', self::WRITE, 'red'),
                ],
            ],
            'purchases' => [
                'name' => 'Purchases', 'path' => 'finance/accounting/purchases-hub', 'icon' => 'cart', 'tier' => self::WRITE,
                'title' => 'accounting.hubs.purchases.title', 'subtitle' => 'accounting.hubs.purchases.subtitle',
                'cards' => [
                    self::card('suppliers', 'finance/accounting/suppliers', 'truck-outline', self::WRITE, 'blue'),
                    self::card('items', 'finance/accounting/items', 'package-variant-closed', self::WRITE, 'indigo'),
                    self::card('inventory', 'finance/accounting/inventory', 'warehouse', self::WRITE, 'teal'),
                    self::card('warehouses', 'finance/accounting/warehouses', 'building-warehouse', self::WRITE, 'cyan'),
                    self::card('expenses', 'finance/accounting/expenses', 'receipt-text-outline', self::WRITE, 'orange'),
                    self::card('payables', 'finance/accounting/payables', 'cash-minus', self::MODIFY, 'red'),
                    self::card('payment_batches', 'finance/accounting/payment-batches', 'bank-transfer-out', self::MODIFY, 'deep-purple'),
                ],
            ],
            'ledger' => [
                'name' => 'Ledger', 'path' => 'finance/accounting/ledger-hub', 'icon' => 'book', 'tier' => self::MODIFY,
                'title' => 'accounting.hubs.ledger.title', 'subtitle' => 'accounting.hubs.ledger.subtitle',
                'cards' => [
                    self::card('journals', 'finance/accounting/journals', 'book-open-variant', self::MODIFY, 'blue'),
                    self::card('accounts', 'finance/accounting/accounts', 'file-tree-outline', self::MODIFY, 'indigo'),
                    self::card('ledger', 'finance/accounting/ledger', 'notebook-outline', self::MODIFY, 'teal'),
                    self::card('banking', 'finance/accounting/banking', 'bank-outline', self::MODIFY, 'green'),
                    self::card('bank_feeds', 'finance/accounting/bank-feeds', 'bank-transfer', self::MODIFY, 'cyan'),
                    self::card('periods', 'finance/accounting/periods', 'calendar-range-outline', self::MODIFY, 'orange'),
                    self::card('fixed_assets', 'finance/accounting/assets', 'office-building-outline', self::MODIFY, 'blue-grey'),
                ],
            ],
            'reporting' => [
                'name' => 'Reporting', 'path' => 'finance/accounting/reporting-hub', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'accounting.hubs.reporting.title', 'subtitle' => 'accounting.hubs.reporting.subtitle',
                'cards' => [
                    self::card('reports', 'finance/accounting/reports', 'file-chart-outline', self::READ, 'blue'),
                    self::card('statements', 'finance/accounting/statements', 'file-document-multiple-outline', self::READ, 'indigo'),
                    self::card('report_builder', 'finance/accounting/report-builder', 'table-cog', self::MODIFY, 'teal'),
                    self::card('analytics', 'finance/accounting/analytics', 'chart-line', self::READ, 'green'),
                    self::card('cash_forecast', 'finance/accounting/cash-forecast', 'chart-timeline-variant', self::MODIFY, 'orange'),
                    self::card('consolidation', 'finance/accounting/consolidation', 'source-merge', self::ADMIN, 'red'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'finance/accounting/configure-hub', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'accounting.hubs.configure.title', 'subtitle' => 'accounting.hubs.configure.subtitle',
                'cards' => [
                    // The businesses this tenant keeps books for. First card in Configure because
                    // everything else on this hub is set up per business, not per tenant.
                    self::card('entities', 'finance/accounting/entities', 'domain', self::ADMIN, 'primary'),
                    self::card('settings', 'finance/accounting/settings', 'cog-outline', self::ADMIN, 'blue-grey'),
                    self::card('templates', 'finance/accounting/templates', 'file-document-outline', self::ADMIN, 'purple'),
                    self::card('import', 'finance/accounting/import', 'database-import', self::ADMIN, 'indigo'),
                    self::card('tax', 'finance/accounting/tax', 'percent-outline', self::ADMIN, 'red'),
                    self::card('sars_config', 'finance/accounting/sars/config', 'shield-check-outline', self::ADMIN, 'green'),
                    self::card('sars_vat201', 'finance/accounting/sars/vat201', 'file-percent-outline', self::ADMIN, 'teal'),
                    self::card('sars_submissions', 'finance/accounting/sars/submissions', 'file-certificate-outline', self::ADMIN, 'indigo'),
                    self::card('budgets', 'finance/accounting/budgets', 'wallet-outline', self::ADMIN, 'green'),
                    self::card('exchange_rates', 'finance/accounting/exchange-rates', 'currency-usd', self::ADMIN, 'teal'),
                    self::card('posting_limits', 'finance/accounting/posting-limits', 'lock-outline', self::ADMIN, 'orange'),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: these hubs fold into their neighbours.
            'reporting' => ['absorb' => ['configure'], 'name' => 'Reports & Settings'],
        ]);
    }
}
