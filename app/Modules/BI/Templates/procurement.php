<?php

use App\Modules\BI\Support\Tpl;

// Procurement, suppliers, contracts, tenders and budgets templates.
// Layout: KPI row at y=0 (h=4, four cards of w=3), then charts below on a 12-column grid.

return [
    // ------------------------------------------------------------------ Procurement
    'proc_approval_times' => [
        'name' => 'Approval Turnaround', 'group' => 'Procurement', 'icon' => 'tabler-clock-hour-4',
        'description' => 'How long procurement approvals take, what is stuck and how often requests are turned down.',
        'widgets' => [
            Tpl::kpi('Average days to decide', 'proc_approvals', ['avg_days'], 0, 0, 'traffic_light'),
            Tpl::kpi('Approvals decided', 'proc_approvals', ['completed', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Still pending', 'proc_approvals', ['pending', 'avg_pending_age'], 6, 0, 'multi_kpi', 'all'),
            Tpl::kpi('Approval rate', 'proc_approvals', ['approval_rate'], 9, 0, 'gauge'),
            Tpl::w('line', 'Average days to decide by month', 'proc_approvals', ['measures' => ['avg_days'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'Turnaround bands', 'proc_approvals', ['measures' => ['approvals'], 'dimension' => 'speed'], 8, 4, 4, 8),
            Tpl::w('lollipop', 'Average days by department', 'proc_approvals', ['measures' => ['avg_days'], 'dimension' => 'department'], 0, 12, 6, 8),
            Tpl::w('column_clustered', 'Outcome by number of steps', 'proc_approvals', ['measures' => ['approved', 'rejected', 'pending'], 'dimension' => 'steps'], 6, 12, 6, 8),
        ],
    ],
    'proc_deviations' => [
        'name' => 'Policy Deviations', 'group' => 'Procurement', 'icon' => 'tabler-shield-exclamation',
        'description' => 'Procurement-policy breaches flagged on requests: which rules, how serious and what is still open.',
        'widgets' => [
            Tpl::kpi('Deviations', 'proc_violations', ['violations', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Still open', 'proc_violations', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Blocking', 'proc_violations', ['blocking'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Documents affected', 'proc_violations', ['documents'], 9),
            Tpl::w('bar_ranked', 'Deviations by rule', 'proc_violations', ['measures' => ['violations'], 'dimension' => 'rule'], 0, 4, 6, 9),
            Tpl::w('rose', 'Severity mix', 'proc_violations', ['measures' => ['violations'], 'dimension' => 'severity'], 6, 4, 6, 9),
            Tpl::w('column_stacked', 'Deviations by month and rule', 'proc_violations', ['measures' => ['violations'], 'series' => 'rule', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Open vs closed', 'proc_violations', ['measures' => ['violations'], 'dimension' => 'status', 'period' => 'all'], 8, 13, 4, 8),
            Tpl::w('matrix_table', 'Rule by severity', 'proc_violations', ['measures' => ['violations'], 'dimension' => 'rule', 'series' => 'severity', 'period' => 'all'], 0, 21, 12, 8),
        ],
    ],

    // ------------------------------------------------------------------ Suppliers
    'sup_overview' => [
        'name' => 'Supplier Base', 'group' => 'Suppliers', 'icon' => 'tabler-building-warehouse',
        'description' => 'Who the suppliers are: segments, categories, B-BBEE levels and risk bands.',
        'widgets' => [
            Tpl::kpi('Suppliers', 'sup_suppliers', ['suppliers'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Preferred / strategic', 'sup_suppliers', ['preferred'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('B-BBEE level 1–4', 'sup_suppliers', ['bee_rate'], 6, 0, 'progress', 'all'),
            Tpl::kpi('High-risk suppliers', 'sup_suppliers', ['high_risk'], 9, 0, 'traffic_light', 'all'),
            Tpl::w('donut', 'Suppliers by segment', 'sup_suppliers', ['measures' => ['suppliers'], 'dimension' => 'segment', 'period' => 'all'], 0, 4, 4, 9),
            Tpl::w('bar_ranked', 'Suppliers by category', 'sup_suppliers', ['measures' => ['suppliers'], 'dimension' => 'category', 'top' => 12, 'period' => 'all'], 4, 4, 4, 9),
            Tpl::w('column_clustered', 'Suppliers by B-BBEE level', 'sup_suppliers', ['measures' => ['suppliers'], 'dimension' => 'bee_level', 'sort' => 'asc', 'period' => 'all'], 8, 4, 4, 9),
            Tpl::w('bar_100', 'Risk band within each segment', 'sup_suppliers', ['measures' => ['suppliers'], 'dimension' => 'segment', 'series' => 'risk_band', 'period' => 'all'], 0, 13, 6, 8),
            Tpl::w('column_simple', 'New suppliers by month', 'sup_suppliers', ['measures' => ['suppliers'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 13, 6, 8),
            Tpl::w('table', 'Supplier register', 'sup_suppliers', ['measures' => ['avg_risk', 'bee_compliant', 'preferred'], 'dimension' => 'supplier', 'top' => 50, 'period' => 'all'], 0, 21, 12, 10),
        ],
    ],
    'sup_risk' => [
        'name' => 'Supplier Risk', 'group' => 'Suppliers', 'icon' => 'tabler-alert-octagon',
        'description' => 'Risk flags, adverse media and risk bands across the supplier base.',
        'widgets' => [
            Tpl::kpi('Open risk flags', 'sup_risk_flags', ['open'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Critical / high flags', 'sup_risk_flags', ['critical'], 3, 0, 'traffic_light', 'all'),
            Tpl::kpi('Negative media, open', 'sup_findings', ['open_negative'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average risk score', 'sup_suppliers', ['avg_risk'], 9, 0, 'linear_gauge', 'all'),
            Tpl::w('bar_ranked', 'Flags by type', 'sup_risk_flags', ['measures' => ['flags'], 'dimension' => 'type', 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('bar_stacked', 'Flags by supplier and severity', 'sup_risk_flags', ['measures' => ['flags'], 'dimension' => 'supplier', 'series' => 'severity', 'top' => 10, 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('treemap', 'Findings by channel and category', 'sup_findings', ['measures' => ['findings'], 'dimension' => 'channel', 'series' => 'category', 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('donut', 'Findings by sentiment', 'sup_findings', ['measures' => ['findings'], 'dimension' => 'sentiment', 'period' => 'all'], 6, 13, 6, 9),
            Tpl::w('pie', 'Suppliers by risk band', 'sup_suppliers', ['measures' => ['suppliers'], 'dimension' => 'risk_band', 'period' => 'all'], 0, 22, 4, 8),
            Tpl::w('table', 'Suppliers with negative findings', 'sup_findings', ['measures' => ['negative', 'serious', 'open_negative'], 'dimension' => 'supplier', 'top' => 30, 'period' => 'all'], 4, 22, 8, 8),
        ],
    ],
    'sup_due_diligence' => [
        'name' => 'Supplier Due Diligence', 'group' => 'Suppliers', 'icon' => 'tabler-file-search',
        'description' => 'Verification checks run on suppliers: what passed, what mismatched and what still needs review.',
        'widgets' => [
            Tpl::kpi('Checks run', 'sup_verifications', ['checks', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Verified', 'sup_verifications', ['pass_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Mismatches', 'sup_verifications', ['mismatch'], 6, 0, 'traffic_light'),
            Tpl::kpi('Needs manual review', 'sup_verifications', ['manual_review'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_100', 'Results by check', 'sup_verifications', ['measures' => ['checks'], 'dimension' => 'check_type', 'series' => 'status'], 0, 4, 8, 10),
            Tpl::w('donut', 'Overall results', 'sup_verifications', ['measures' => ['checks'], 'dimension' => 'status'], 8, 4, 4, 10),
            Tpl::w('line_multi', 'Checks and mismatches by month', 'sup_verifications', ['measures' => ['checks', 'mismatch'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 14, 6, 8),
            Tpl::w('bar_ranked', 'Suppliers with most mismatches', 'sup_verifications', ['measures' => ['mismatch'], 'dimension' => 'supplier', 'top' => 10], 6, 14, 6, 8),
            Tpl::w('table', 'Supplier check summary', 'sup_verifications', ['measures' => ['checks', 'verified', 'mismatch', 'manual_review', 'pass_rate'], 'dimension' => 'supplier', 'top' => 50, 'period' => 'all'], 0, 22, 12, 10),
        ],
    ],
    'sup_invoices' => [
        'name' => 'Supplier Invoices & Matching', 'group' => 'Suppliers', 'icon' => 'tabler-file-invoice',
        'description' => 'Supplier invoices against POs and receipts: matched, exceptions, variances and what is unpaid.',
        'widgets' => [
            Tpl::kpi('Invoiced', 'sup_invoices', ['invoiced', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Match rate', 'sup_invoices', ['match_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Exceptions', 'sup_invoices', ['exceptions', 'variance'], 6, 0, 'multi_kpi'),
            Tpl::kpi('Unpaid', 'sup_invoices', ['unpaid', 'overdue'], 9, 0, 'multi_kpi', 'all'),
            Tpl::w('combo_column_line', 'Invoiced by month', 'sup_invoices', ['measures' => ['invoiced', 'invoices'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Match status', 'sup_invoices', ['measures' => ['invoices'], 'dimension' => 'match_status'], 8, 4, 4, 9),
            Tpl::w('bar_stacked', 'Invoices by supplier and payment status', 'sup_invoices', ['measures' => ['invoiced'], 'dimension' => 'supplier', 'series' => 'payment_status', 'top' => 10], 0, 13, 6, 9),
            Tpl::w('line_multi', 'Supplier bills in the ledger', 'fin_purchases', ['measures' => ['revenue', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 13, 6, 9),
            Tpl::w('table', 'Supplier invoice summary', 'sup_invoices', ['measures' => ['invoices', 'invoiced', 'exceptions', 'variance', 'unpaid'], 'dimension' => 'supplier', 'top' => 40], 0, 22, 12, 9),
        ],
    ],

    // ------------------------------------------------------------------ Contracts
    'con_obligations' => [
        'name' => 'Contract Obligations', 'group' => 'Contracts', 'icon' => 'tabler-checklist',
        'description' => 'Whether contract obligations are being met, breached or slipping past their due dates.',
        'widgets' => [
            Tpl::kpi('Compliance', 'con_obligations', ['compliance_rate'], 0, 0, 'gauge', 'all'),
            Tpl::kpi('Open obligations', 'con_obligations', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Overdue', 'con_obligations', ['overdue'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Breached', 'con_obligations', ['breached'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('donut', 'Obligations by status', 'con_obligations', ['measures' => ['obligations'], 'dimension' => 'status', 'period' => 'all'], 0, 4, 4, 9),
            Tpl::w('bar_stacked', 'Contract by status', 'con_obligations', ['measures' => ['obligations'], 'dimension' => 'contract', 'series' => 'status', 'top' => 12, 'period' => 'all'], 4, 4, 8, 9),
            Tpl::w('column_stacked', 'Obligations due by month', 'con_obligations', ['measures' => ['obligations'], 'series' => 'status', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('pie', 'When open items are due', 'con_obligations', ['measures' => ['obligations'], 'dimension' => 'timing', 'period' => 'all'], 8, 13, 4, 8),
            Tpl::w('table', 'Obligations by owner', 'con_obligations', ['measures' => ['obligations', 'open', 'overdue', 'breached', 'compliance_rate'], 'dimension' => 'owner', 'top' => 30, 'period' => 'all'], 0, 21, 12, 8),
        ],
    ],

    // ------------------------------------------------------------------ Tenders
    'tnd_evaluations' => [
        'name' => 'Tender Evaluations (Buy Side)', 'group' => 'Tenders', 'icon' => 'tabler-gavel',
        'description' => 'Our own tenders under evaluation: bids received, scores, disqualifications and awards.',
        'widgets' => [
            Tpl::kpi('Tenders', 'proc_tender_reviews', ['tenders', 'in_progress'], 0, 0, 'multi_kpi', 'all'),
            Tpl::kpi('Bids received', 'proc_bids', ['bids', 'bids_per_tender'], 3, 0, 'multi_kpi', 'all'),
            Tpl::kpi('Disqualification rate', 'proc_bids', ['disqualification_rate'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Awarded value', 'proc_tender_reviews', ['awarded_value'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('funnel', 'Tenders by evaluation stage', 'proc_tender_reviews', ['measures' => ['tenders'], 'dimension' => 'status', 'period' => 'all'], 0, 4, 4, 9),
            Tpl::w('bar_stacked', 'Bids per tender by outcome', 'proc_bids', ['measures' => ['bids'], 'dimension' => 'tender', 'series' => 'status', 'period' => 'all'], 4, 4, 8, 9),
            Tpl::w('lollipop', 'Average weighted score by bidder', 'proc_bids', ['measures' => ['avg_score'], 'dimension' => 'bidder', 'top' => 12, 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('donut', 'Bids by B-BBEE level', 'proc_bids', ['measures' => ['bids'], 'dimension' => 'bee_level', 'period' => 'all'], 6, 13, 6, 9),
            Tpl::w('ranking_table', 'Bidders', 'proc_bids', ['measures' => ['bids', 'bid_value', 'avg_score', 'awarded', 'disqualified'], 'dimension' => 'bidder', 'top' => 30, 'period' => 'all'], 0, 22, 12, 9),
        ],
    ],

    // ------------------------------------------------------------------ Budgets
    'bud_vs_actual' => [
        'name' => 'Budget vs Actual', 'group' => 'Budgets', 'icon' => 'tabler-scale',
        'description' => 'Budget, actual spend and commitments by department, capex/opex and budget.',
        'widgets' => [
            Tpl::kpi('Budget', 'bud_budgets', ['allocated'], 0),
            Tpl::kpi('Actual spend', 'bud_budgets', ['actual'], 3),
            Tpl::kpi('Committed (open POs)', 'bud_budgets', ['committed'], 6),
            Tpl::kpi('Still available', 'bud_budgets', ['available'], 9, 0, 'variance_card'),
            Tpl::w('column_clustered', 'Budget vs actual by department', 'bud_budgets', ['measures' => ['allocated', 'actual', 'committed'], 'dimension' => 'department'], 0, 4, 8, 9),
            Tpl::w('donut', 'Budget by capex / opex', 'bud_budgets', ['measures' => ['allocated'], 'dimension' => 'expenditure'], 8, 4, 4, 9),
            Tpl::w('diverging_bar', 'Budget less actual, by budget', 'bud_budgets', ['measures' => ['variance'], 'dimension' => 'budget', 'top' => 15], 0, 13, 6, 9),
            Tpl::w('bar_clustered', 'Spent + committed % by department', 'bud_budgets', ['measures' => ['used_pct', 'utilisation'], 'dimension' => 'department'], 6, 13, 6, 9),
            Tpl::w('table', 'Budget vs actual', 'bud_budgets', ['measures' => ['allocated', 'actual', 'committed', 'reserved', 'available', 'used_pct'], 'dimension' => 'budget', 'top' => 50], 0, 22, 12, 10),
        ],
    ],
    'bud_utilisation' => [
        'name' => 'Budget Utilisation', 'group' => 'Budgets', 'icon' => 'tabler-gauge',
        'description' => 'How fast budgets are being used, which are close to the limit and where spend is posted from.',
        'widgets' => [
            Tpl::kpi('Spent', 'bud_budgets', ['utilisation'], 0, 0, 'gauge'),
            Tpl::kpi('Spent + committed', 'bud_budgets', ['used_pct'], 3, 0, 'progress'),
            Tpl::kpi('Budgets over-spent', 'bud_budgets', ['over_budget'], 6, 0, 'traffic_light'),
            Tpl::kpi('Spend posted', 'bud_consumptions', ['spent', 'growth_pct'], 9, 0, 'kpi_sparkline'),
            Tpl::w('running_total', 'Cumulative spend posted this year', 'bud_consumptions', ['measures' => ['spent'], 'grain' => 'month'], 0, 4, 8, 9),
            Tpl::w('donut', 'Budgets by health', 'bud_budgets', ['measures' => ['budgets'], 'dimension' => 'health'], 8, 4, 4, 9),
            Tpl::w('area_stacked', 'Spend posted by department', 'bud_consumptions', ['measures' => ['spent'], 'series' => 'department', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 9),
            Tpl::w('pie', 'Where spend is posted from', 'bud_consumptions', ['measures' => ['spent'], 'dimension' => 'source'], 6, 13, 6, 9),
            Tpl::w('line_multi', 'GL expenses vs last year', 'fin_expenses', ['measures' => ['amount', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 22, 12, 8),
        ],
    ],
    'bud_petty_cash' => [
        'name' => 'Petty Cash', 'group' => 'Budgets', 'icon' => 'tabler-coins',
        'description' => 'Petty cash requested, issued and spent, by float and category — and what is not yet accounted for.',
        'widgets' => [
            Tpl::kpi('Cash issued', 'pc_requests', ['issued', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Spent', 'pc_requests', ['spent'], 3),
            Tpl::kpi('Issued, not settled', 'pc_requests', ['unsettled'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Receipts overdue', 'pc_requests', ['receipts_overdue'], 9, 0, 'traffic_light', 'all'),
            Tpl::w('combo_column_line', 'Requested vs spent by month', 'pc_requests', ['measures' => ['requested', 'spent'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Spend by category', 'pc_requests', ['measures' => ['spent'], 'dimension' => 'category'], 8, 4, 4, 9),
            Tpl::w('bar_clustered', 'By float', 'pc_requests', ['measures' => ['issued', 'spent', 'returned'], 'dimension' => 'float'], 0, 13, 6, 8),
            Tpl::w('funnel', 'Requests by status', 'pc_requests', ['measures' => ['requests'], 'dimension' => 'status'], 6, 13, 6, 8),
        ],
    ],
];
