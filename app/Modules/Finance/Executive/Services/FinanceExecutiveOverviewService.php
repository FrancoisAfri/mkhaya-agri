<?php

namespace App\Modules\Finance\Executive\Services;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the executive finance overview as one card per ACTIVE finance-related module
 * (payroll, accounting/GL, claims, procurement, budget, assets, staff loans, petty
 * cash). A card is only produced when the company's plan/entitlement includes that
 * module, so the dashboard adapts to whatever finance modules are switched on. Each
 * provider is wrapped so a single failing/absent query silently drops that card rather
 * than breaking the page, and every count/sum is RLS-scoped to the active company.
 */
class FinanceExecutiveOverviewService
{
    public function __construct(private CompanyEntitlements $entitlements)
    {
    }

    /** @return array<int, array> one finance card per active module */
    public function cards(): array
    {
        $out = [];

        foreach ($this->providers() as $code => $provider) {
            if (! $this->entitlements->currentAllowsModule($code)) {
                continue;
            }

            try {
                $card = $provider();
                if ($card) {
                    $out[] = $card;
                }
            } catch (\Throwable) {
                // One module's metric failing must never break the executive view.
            }
        }

        return $out;
    }

    /** @return array<string, callable> */
    private function providers(): array
    {
        $year = now()->year;
        $month = now()->month;

        return [
            'payroll' => fn () => [
                'code' => 'payroll', 'name' => 'Payroll', 'icon' => 'mdi-cash-multiple', 'color' => 'indigo', 'link' => '/payroll/dashboard',
                'metrics' => [
                    ['label' => 'Latest payroll (net)', 'value' => (float) DB::table('payroll_runs')->orderByDesc('period_end')->value('total_net'), 'format' => 'currency'],
                    ['label' => 'Gross (YTD)', 'value' => (float) DB::table('payroll_runs')->whereYear('period_end', $year)->sum('total_gross'), 'format' => 'currency'],
                    ['label' => 'Employees paid', 'value' => (int) DB::table('payroll_runs')->orderByDesc('period_end')->value('employee_count')],
                ],
            ],

            'accounting' => fn () => [
                'code' => 'accounting', 'name' => 'Accounting (GL)', 'icon' => 'mdi-file-document-outline', 'color' => 'blue', 'link' => '/finance/accounting/journals',
                'metrics' => [
                    ['label' => 'Outstanding invoices', 'value' => (float) DB::table('fin_invoices')
                        ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'entity_id'))->whereNotIn('status', ['paid', 'cancelled', 'void', 'draft'])->sum('total'), 'format' => 'currency'],
                    ['label' => 'Invoiced (YTD)', 'value' => (float) DB::table('fin_invoices')
                        ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'entity_id'))->whereYear('invoice_date', $year)->sum('total'), 'format' => 'currency'],
                    ['label' => 'Open invoices', 'value' => (int) DB::table('fin_invoices')
                        ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'entity_id'))->whereNotIn('status', ['paid', 'cancelled', 'void', 'draft'])->count()],
                ],
            ],

            'claims' => fn () => [
                'code' => 'claims', 'name' => 'Claims', 'icon' => 'mdi-receipt-text-outline', 'color' => 'pink', 'link' => '/claims',
                'metrics' => [
                    ['label' => 'Pending claims', 'value' => (float) DB::table('claims')->whereNotIn('status', ['paid', 'rejected', 'cancelled'])->sum('total_amount'), 'format' => 'currency'],
                    ['label' => 'Paid (this month)', 'value' => (float) DB::table('claims')->whereYear('paid_at', $year)->whereMonth('paid_at', $month)->sum('total_amount'), 'format' => 'currency'],
                    ['label' => 'Open claims', 'value' => (int) DB::table('claims')->whereNotIn('status', ['paid', 'rejected', 'cancelled'])->count()],
                ],
            ],

            'procurement' => fn () => [
                'code' => 'procurement', 'name' => 'Procurement', 'icon' => 'mdi-cart-outline', 'color' => 'deep-purple', 'link' => '/procurement',
                'metrics' => [
                    ['label' => 'Committed (open POs)', 'value' => (float) DB::table('purchase_orders')->whereNotIn('status', ['cancelled', 'closed', 'completed', 'rejected'])->sum('amount'), 'format' => 'currency'],
                    ['label' => 'Open POs', 'value' => (int) DB::table('purchase_orders')->whereNotIn('status', ['cancelled', 'closed', 'completed', 'rejected'])->count()],
                    ['label' => 'Purchase requests', 'value' => (int) DB::table('purchase_requests')->count()],
                ],
            ],

            'budget' => fn () => [
                'code' => 'budget', 'name' => 'Budget', 'icon' => 'mdi-chart-pie', 'color' => 'green', 'link' => '/budget',
                'metrics' => [
                    ['label' => 'Total allocated', 'value' => (float) DB::table('budgets')->sum('allocated_amount'), 'format' => 'currency'],
                    ['label' => 'Budgets', 'value' => (int) DB::table('budgets')->count()],
                ],
            ],

            'asset_management' => fn () => [
                'code' => 'asset_management', 'name' => 'Assets', 'icon' => 'mdi-desktop-classic', 'color' => 'teal', 'link' => '/assets/dashboard',
                'metrics' => [
                    ['label' => 'Asset value (cost)', 'value' => (float) DB::table('assets')->whereNull('deleted_at')->sum('price'), 'format' => 'currency'],
                    ['label' => 'Net book value', 'value' => Schema::hasTable('asset_depreciations') ? (float) DB::table('asset_depreciations')->sum('balance_amount') : 0, 'format' => 'currency'],
                    ['label' => 'Assets', 'value' => (int) DB::table('assets')->whereNull('deleted_at')->count()],
                ],
            ],

            'staff_loans' => fn () => [
                'code' => 'staff_loans', 'name' => 'Staff Loans', 'icon' => 'mdi-bank-outline', 'color' => 'orange', 'link' => '/staff-loans',
                'metrics' => [
                    ['label' => 'Loans outstanding', 'value' => (float) DB::table('staff_loans')->where('status', 'active')->sum('outstanding_balance'), 'format' => 'currency'],
                    ['label' => 'Monthly deductions', 'value' => (float) DB::table('staff_loans')->where('status', 'active')->sum('monthly_installment'), 'format' => 'currency'],
                    ['label' => 'Active loans', 'value' => (int) DB::table('staff_loans')->where('status', 'active')->count()],
                ],
            ],

            'petty_cash' => fn () => [
                'code' => 'petty_cash', 'name' => 'Petty Cash', 'icon' => 'mdi-wallet-outline', 'color' => 'cyan', 'link' => '/petty-cash',
                'metrics' => [
                    ['label' => 'Float exposure', 'value' => (float) DB::table('petty_cash_floats')->where('status', 'active')->sum('float_limit'), 'format' => 'currency'],
                    ['label' => 'Current balance', 'value' => (float) DB::table('petty_cash_floats')->where('status', 'active')->sum('current_balance'), 'format' => 'currency'],
                    ['label' => 'Active floats', 'value' => (int) DB::table('petty_cash_floats')->where('status', 'active')->count()],
                ],
            ],
        ];
    }
}
