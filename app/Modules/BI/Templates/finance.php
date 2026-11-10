<?php

use App\Modules\BI\Support\Tpl;

// Finance templates — the reference example for every other templates file.
// Layout: KPI row at y=0 (h=4, four cards of w=3), then charts below on a 12-column grid.

return [
    'finance_overview' => [
        'name' => 'Finance Overview', 'group' => 'Finance', 'icon' => 'tabler-report-money',
        'description' => 'Revenue, spend, cash and what customers owe — the whole business on one page.',
        'widgets' => [
            Tpl::kpi('Revenue this year', 'fin_sales', ['revenue', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Spend this year', 'fin_purchases', ['revenue', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Net cash flow', 'fin_cashflow', ['net_cash'], 6),
            Tpl::kpi('Customers owe', 'fin_sales', ['outstanding', 'overdue_amount'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('combo_column_line', 'Revenue by month vs last year', 'fin_sales', ['measures' => ['revenue', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Revenue by customer category', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'party_category', 'top' => 6, 'other' => true], 8, 4, 4, 9),
            Tpl::w('line_multi', 'Cash in vs cash out', 'fin_cashflow', ['measures' => ['cash_in', 'cash_out'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('bar_ranked', 'Biggest expense accounts', 'fin_expenses', ['measures' => ['amount'], 'dimension' => 'account', 'top' => 10], 6, 13, 6, 8),
            Tpl::w('smart_narrative', 'In short', 'fin_sales', ['measures' => ['revenue', 'collected', 'outstanding'], 'dimension' => 'party'], 0, 21, 12, 5),
        ],
    ],
    'finance_revenue' => [
        'name' => 'Revenue Analysis', 'group' => 'Finance', 'icon' => 'tabler-trending-up',
        'description' => 'Where revenue comes from, how it is growing and which customers drive it.',
        'widgets' => [
            Tpl::kpi('Revenue', 'fin_sales', ['revenue', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Invoices', 'fin_sales', ['documents'], 3),
            Tpl::kpi('Average invoice', 'fin_sales', ['avg_invoice'], 6),
            Tpl::kpi('Active customers', 'fin_sales', ['parties'], 9),
            Tpl::w('line_multi', 'Revenue this year vs last year', 'fin_sales', ['measures' => ['revenue', 'last_year'], 'grain' => 'month', 'period' => 'last_24_months'], 0, 4, 12, 8),
            Tpl::w('bar_ranked', 'Top 10 customers', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 10], 0, 12, 4, 9),
            Tpl::w('pareto', '80/20: customers by revenue', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 20], 4, 12, 8, 9),
            Tpl::w('waterfall', 'What moved revenue vs last year', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 8], 0, 21, 6, 9),
            Tpl::w('column_stacked', 'Revenue by month and customer category', 'fin_sales', ['measures' => ['revenue'], 'series' => 'party_category', 'grain' => 'month', 'period' => 'last_12_months'], 6, 21, 6, 9),
            Tpl::w('bar_ranked', 'Revenue by sales rep', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'sales_rep', 'top' => 10], 0, 30, 6, 8),
            Tpl::w('calendar_heatmap', 'Invoicing calendar', 'fin_sales', ['measures' => ['revenue'], 'period' => 'last_12_months'], 6, 30, 6, 8),
        ],
    ],
    'finance_receivables' => [
        'name' => 'Receivables & Collections', 'group' => 'Finance', 'icon' => 'tabler-cash-banknote',
        'description' => 'Who owes what, what is overdue and how well cash is being collected.',
        'widgets' => [
            Tpl::kpi('Outstanding', 'fin_sales', ['outstanding'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Overdue', 'fin_sales', ['overdue_amount'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Collection rate', 'fin_sales', ['collection_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Cash received', 'fin_cashflow', ['cash_in', 'growth_pct'], 9, 0, 'kpi_sparkline'),
            Tpl::w('bar_ranked', 'Customers who owe the most', 'fin_sales', ['measures' => ['outstanding'], 'dimension' => 'party', 'top' => 12, 'period' => 'all'], 0, 4, 6, 10),
            Tpl::w('donut', 'Overdue vs not overdue', 'fin_sales', ['measures' => ['outstanding'], 'dimension' => 'overdue', 'period' => 'all'], 6, 4, 6, 10),
            Tpl::w('combo_column_line', 'Invoiced vs collected by month', 'fin_sales', ['measures' => ['invoiced', 'collected'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 14, 12, 8),
            Tpl::w('table', 'Customer balances', 'fin_sales', ['measures' => ['invoiced', 'collected', 'outstanding', 'overdue_amount'], 'dimension' => 'party', 'top' => 50, 'period' => 'all'], 0, 22, 12, 10),
        ],
    ],
    'finance_payables' => [
        'name' => 'Payables & Supplier Spend', 'group' => 'Finance', 'icon' => 'tabler-receipt',
        'description' => 'What the business buys, from whom, and what it still has to pay.',
        'widgets' => [
            Tpl::kpi('Spend', 'fin_purchases', ['revenue', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('We owe suppliers', 'fin_purchases', ['outstanding'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Overdue to suppliers', 'fin_purchases', ['overdue_amount'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Suppliers used', 'fin_purchases', ['parties'], 9),
            Tpl::w('bar_ranked', 'Top suppliers by spend', 'fin_purchases', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 10], 0, 4, 6, 9),
            Tpl::w('treemap', 'Spend by supplier category and supplier', 'fin_purchases', ['measures' => ['revenue'], 'dimension' => 'party_category', 'series' => 'party', 'top' => 8], 6, 4, 6, 9),
            Tpl::w('line_multi', 'Spend this year vs last year', 'fin_purchases', ['measures' => ['revenue', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 12, 8),
            Tpl::w('table', 'Supplier balances', 'fin_purchases', ['measures' => ['invoiced', 'collected', 'outstanding'], 'dimension' => 'party', 'top' => 50, 'period' => 'all'], 0, 21, 12, 10),
        ],
    ],
    'finance_cashflow' => [
        'name' => 'Cash Flow', 'group' => 'Finance', 'icon' => 'tabler-arrows-exchange',
        'description' => 'Money in, money out and the net position, month by month and bank by bank.',
        'widgets' => [
            Tpl::kpi('Cash received', 'fin_cashflow', ['cash_in', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Cash paid', 'fin_cashflow', ['cash_out', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Net cash flow', 'fin_cashflow', ['net_cash'], 6, 0, 'variance_card'),
            Tpl::kpi('Transactions', 'fin_cashflow', ['payments'], 9),
            Tpl::w('combo_column_line', 'Received vs paid by month', 'fin_cashflow', ['measures' => ['cash_in', 'cash_out'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Cash by bank account', 'fin_cashflow', ['measures' => ['cash_in'], 'dimension' => 'bank'], 8, 4, 4, 9),
            Tpl::w('running_total', 'Cumulative net cash this year', 'fin_cashflow', ['measures' => ['net_cash'], 'grain' => 'month'], 0, 13, 6, 8),
            Tpl::w('bar_ranked', 'Who paid us the most', 'fin_cashflow', ['measures' => ['cash_in'], 'dimension' => 'party', 'top' => 10], 6, 13, 6, 8),
        ],
    ],
    'finance_profit' => [
        'name' => 'Income & Expenses (P&L view)', 'group' => 'Finance', 'icon' => 'tabler-scale',
        'description' => 'Income and expenses from the general ledger, by account and over time.',
        'widgets' => [
            Tpl::kpi('Income', 'fin_income', ['amount', 'growth_pct'], 0, 0, 'kpi_sparkline', 'ytd', 4),
            Tpl::kpi('Expenses', 'fin_expenses', ['amount', 'growth_pct'], 4, 0, 'kpi_sparkline', 'ytd', 4),
            Tpl::kpi('Ledger lines posted', 'fin_expenses', ['lines'], 8, 0, 'kpi_card', 'ytd', 4),
            Tpl::w('line_multi', 'Income by month', 'fin_income', ['measures' => ['amount', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 6, 8),
            Tpl::w('line_multi', 'Expenses by month', 'fin_expenses', ['measures' => ['amount', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 8),
            Tpl::w('bar_ranked', 'Income by account', 'fin_income', ['measures' => ['amount'], 'dimension' => 'account', 'top' => 10], 0, 12, 6, 9),
            Tpl::w('treemap', 'Expenses by group and account', 'fin_expenses', ['measures' => ['amount'], 'dimension' => 'subtype', 'series' => 'account', 'top' => 8], 6, 12, 6, 9),
            Tpl::w('heatmap_matrix', 'Expense accounts by month', 'fin_expenses', ['measures' => ['amount'], 'dimension' => 'account', 'series' => 'month', 'period' => 'last_12_months', 'top' => 12], 0, 21, 12, 9),
        ],
    ],
    'finance_vat' => [
        'name' => 'VAT & Tax Snapshot', 'group' => 'Finance', 'icon' => 'tabler-percentage',
        'description' => 'VAT charged on sales and paid on purchases, period by period.',
        'widgets' => [
            Tpl::kpi('Output VAT (sales)', 'fin_sales', ['vat', 'growth_pct'], 0, 0, 'kpi_sparkline', 'ytd', 6),
            Tpl::kpi('Input VAT (purchases)', 'fin_purchases', ['vat', 'growth_pct'], 6, 0, 'kpi_sparkline', 'ytd', 6),
            Tpl::w('column_clustered', 'Output VAT by month', 'fin_sales', ['measures' => ['vat'], 'dimension' => 'status'], 0, 4, 6, 8),
            Tpl::w('line', 'Output VAT trend', 'fin_sales', ['measures' => ['vat'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 8),
            Tpl::w('line', 'Input VAT trend', 'fin_purchases', ['measures' => ['vat'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 12, 12, 8),
        ],
    ],
    'finance_credit_control' => [
        'name' => 'Credit Notes & Disputes', 'group' => 'Finance', 'icon' => 'tabler-receipt-refund',
        'description' => 'How much is credited back, to whom, and whether it is rising.',
        'widgets' => [
            Tpl::kpi('Credited', 'fin_credit_notes', ['credited', 'growth_pct'], 0, 0, 'kpi_sparkline', 'ytd', 6),
            Tpl::kpi('Credit notes', 'fin_credit_notes', ['documents'], 6, 0, 'kpi_card', 'ytd', 6),
            Tpl::w('bar_ranked', 'Most credited customers / suppliers', 'fin_credit_notes', ['measures' => ['credited'], 'dimension' => 'party', 'top' => 10], 0, 4, 6, 9),
            Tpl::w('line', 'Credit notes by month', 'fin_credit_notes', ['measures' => ['credited'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 9),
        ],
    ],
];
