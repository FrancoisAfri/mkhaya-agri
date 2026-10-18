<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers the Accounting (GL) module + ribbons under Finance and grants access
 * to every user who can already see a Finance module — so adding it to an
 * existing install reaches all finance users, not just the first. Idempotent;
 * safe to re-run. Run on any environment where Accounting isn't in the menu.
 */
class AccountingModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private array $ribbons = [
        ['name' => 'Dashboard',            'path' => 'finance/accounting/dashboard',      'icon' => 'layout-dashboard'],
        ['name' => 'Journals',             'path' => 'finance/accounting/journals',       'icon' => 'book'],
        ['name' => 'Chart of Accounts',    'path' => 'finance/accounting/accounts',       'icon' => 'list-tree'],
        ['name' => 'General Ledger',       'path' => 'finance/accounting/ledger',         'icon' => 'file-text'],
        ['name' => 'Customers',            'path' => 'finance/accounting/customers',      'icon' => 'users-group'],
        ['name' => 'Suppliers',            'path' => 'finance/accounting/suppliers',      'icon' => 'truck-delivery'],
        ['name' => 'Items',                'path' => 'finance/accounting/items',          'icon' => 'package'],
        ['name' => 'Price Lists',          'path' => 'finance/accounting/price-lists',    'icon' => 'tags'],
        ['name' => 'Quotes',               'path' => 'finance/accounting/quotes',         'icon' => 'file-description'],
        ['name' => 'Sales Orders',         'path' => 'finance/accounting/sales-orders',   'icon' => 'clipboard-list'],
        ['name' => 'Contracts',            'path' => 'finance/accounting/contracts',      'icon' => 'file-certificate'],
        ['name' => 'Recurring Invoices',   'path' => 'finance/accounting/recurring',      'icon' => 'repeat'],
        ['name' => 'Receivables',          'path' => 'finance/accounting/receivables',    'icon' => 'file-invoice'],
        ['name' => 'Payables',             'path' => 'finance/accounting/payables',       'icon' => 'file-dollar'],
        ['name' => 'Payment Batches',      'path' => 'finance/accounting/payment-batches', 'icon' => 'building-bank'],
        ['name' => 'Banking',              'path' => 'finance/accounting/banking',        'icon' => 'businessplan'],
        ['name' => 'Bank Feeds',           'path' => 'finance/accounting/bank-feeds',     'icon' => 'plug-connected'],
        ['name' => 'Cash Flow Forecast',   'path' => 'finance/accounting/cash-forecast',  'icon' => 'chart-arrows-vertical'],
        ['name' => 'Fixed Assets',         'path' => 'finance/accounting/assets',         'icon' => 'building-warehouse'],
        ['name' => 'Budgets',              'path' => 'finance/accounting/budgets',        'icon' => 'chart-pie'],
        ['name' => 'Tax / VAT',            'path' => 'finance/accounting/tax',            'icon' => 'receipt-tax'],
        ['name' => 'Inventory',            'path' => 'finance/accounting/inventory',      'icon' => 'packages'],
        ['name' => 'Expenses',             'path' => 'finance/accounting/expenses',       'icon' => 'receipt'],
        ['name' => 'Debtors Manager',      'path' => 'finance/accounting/debtors',        'icon' => 'alert-triangle'],
        ['name' => 'Reports',              'path' => 'finance/accounting/reports',        'icon' => 'chart-bar'],
        ['name' => 'Financial Statements', 'path' => 'finance/accounting/statements',   'icon' => 'report-money'],
        ['name' => 'Report Builder',       'path' => 'finance/accounting/report-builder', 'icon' => 'table-options'],
        ['name' => 'Analytics',            'path' => 'finance/accounting/analytics',      'icon' => 'chart-donut'],
        ['name' => 'Consolidation',        'path' => 'finance/accounting/consolidation', 'icon' => 'building-community'],
        ['name' => 'Periods',              'path' => 'finance/accounting/periods',        'icon' => 'calendar-stats'],
        ['name' => 'Exchange Rates',       'path' => 'finance/accounting/exchange-rates', 'icon' => 'currency-exchange'],
        ['name' => 'Posting Limits',       'path' => 'finance/accounting/posting-limits', 'icon' => 'shield-lock'],
        ['name' => 'Businesses',           'path' => 'finance/accounting/entities',       'icon' => 'building-community'],
        ['name' => 'Company Details',      'path' => 'finance/accounting/settings',       'icon' => 'building-store'],
    ];

    public function run(): void
    {
        $category = Category::firstOrCreate(
            ['name' => 'Finance'],
            ['active' => 1, 'description' => 'Finance & Claims Management', 'icon' => 'wallet']
        );

        // firstOrNew (not updateOrCreate) so a brand-new row carries its uuid on the
        // INSERT itself — security_modules.uuid is NOT NULL.
        $module = Modules::firstOrNew(['code_name' => 'accounting']);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1, 'category_id' => $category->id, 'name' => 'Accounting (GL)',
            'path' => '/finance/accounting/journals', 'font_awesome' => 'book',
        ])->save();

        $ribbonIds = [];
        foreach ($this->ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active' => 1, 'sort_order' => $i + 1, 'ribbon_path' => $r['path'],
                'description' => $r['name'], 'font_awesome' => $r['icon'], 'access_level' => self::LEVEL,
            ])->save();
            $ribbonIds[] = $ribbon->id;
        }

        // Remove ribbons no longer in the list (e.g. the old "Customers & Suppliers").
        $keep = array_column($this->ribbons, 'name');
        $stale = Ribbon::where('module_id', $module->id)->whereNotIn('ribbon_name', $keep)->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        // Everyone who already has any Finance module (fallback: all users).
        $financeModuleIds = Modules::where('category_id', $category->id)->pluck('id');
        $userIds = ModuleAccess::whereIn('module_id', $financeModuleIds)
            ->where('access_level', '>', 0)->distinct()->pluck('user_id');
        if ($userIds->isEmpty()) {
            // Query builder (not the User model) so this stays safe when the
            // migration runs before users.deleted_at exists — the SoftDeletes
            // global scope would otherwise reference a missing column.
            $userIds = DB::table('users')->pluck('id');
        }

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            ModuleAccess::firstOrCreate(
                ['module_id' => $module->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbonId, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => self::LEVEL]
                );
            }
        }

        $this->command?->info("Accounting module registered and granted to {$userIds->count()} user(s).");
    }
}
