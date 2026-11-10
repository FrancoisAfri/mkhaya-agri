<?php

use App\Modules\BI\Support\Tpl;

// Cross-module executive dashboards. Each visual is checked on its own: a company without one of
// these modules sees that visual locked ("Not in your subscription") and the rest still works.

return [
    'exec_coo' => [
        'name' => 'COO Operations Dashboard', 'group' => 'Executive', 'icon' => 'tabler-building-factory-2',
        'description' => 'Fleet, assets, production, stock, projects and safety in one operational view.',
        'widgets' => [
            Tpl::kpi('Fleet availability', 'flt_vehicles', ['availability_pct'], 0, 0, 'gauge', 'all'),
            Tpl::kpi('Fuel spend', 'flt_fuel', ['cost', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Production yield', 'mfg_orders', ['yield_pct'], 6, 0, 'gauge'),
            Tpl::kpi('Stock value', 'wms_stock', ['value'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('line', 'Fuel cost by month', 'flt_fuel', ['measures' => ['cost'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 6, 8),
            Tpl::w('column_clustered', 'Production: planned vs completed', 'mfg_orders', ['measures' => ['planned_qty', 'completed_qty'], 'dimension' => 'product', 'top' => 8], 6, 4, 6, 8),
            Tpl::w('donut', 'Assets by condition', 'ast_register', ['measures' => ['assets'], 'dimension' => 'condition', 'period' => 'all'], 0, 12, 4, 8),
            Tpl::w('bar_ranked', 'Projects behind (overdue tasks)', 'pm_projects', ['measures' => ['tasks_late'], 'dimension' => 'project', 'top' => 8, 'period' => 'all'], 4, 12, 4, 8),
            Tpl::w('bar_ranked', 'SHEQ incidents by site', 'sheq_incidents', ['measures' => ['incidents'], 'dimension' => 'site', 'top' => 8], 8, 12, 4, 8),
            Tpl::w('bar_ranked', 'Stock value by warehouse', 'wms_stock', ['measures' => ['value'], 'dimension' => 'warehouse', 'top' => 10, 'period' => 'all'], 0, 20, 6, 8),
            Tpl::w('donut', 'Corrective actions by status', 'sheq_actions', ['measures' => ['actions'], 'dimension' => 'status', 'period' => 'all'], 6, 20, 6, 8),
        ],
    ],
    'exec_people' => [
        'name' => 'HR Director Dashboard', 'group' => 'Executive', 'icon' => 'tabler-users-group',
        'description' => 'Workforce, cost, absence, hiring, performance and conduct for the people leader.',
        'widgets' => [
            Tpl::kpi('Headcount', 'hr_people', ['headcount'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Payroll cost', 'hr_payslips', ['gross', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Absenteeism', 'hr_attendance', ['absenteeism_rate'], 6, 0, 'traffic_light'),
            Tpl::kpi('Leave liability', 'hr_leave_balances', ['liability'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('donut', 'Headcount by department', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'department', 'top' => 8, 'other' => true, 'period' => 'all'], 0, 4, 4, 9),
            Tpl::w('funnel', 'Recruitment funnel', 'hr_recruitment', ['measures' => ['applications'], 'dimension' => 'funnel_stage'], 4, 4, 4, 9),
            Tpl::w('column_clustered', 'Performance by rating band', 'hr_performance', ['measures' => ['appraisals'], 'dimension' => 'rating_band'], 8, 4, 4, 9),
            Tpl::w('line_multi', 'Leave days taken by month', 'hr_leave', ['measures' => ['days', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('bar_ranked', 'Training completion by course', 'hr_training', ['measures' => ['completed'], 'dimension' => 'course', 'top' => 10], 6, 13, 6, 8),
            Tpl::kpi('Leavers', 'hr_leavers', ['leavers'], 0, 21),
            Tpl::kpi('Open vacancies', 'hr_vacancies', ['open'], 3, 21, 'kpi_card', 'all'),
            Tpl::kpi('Disciplinary cases open', 'hr_disciplinary', ['open'], 6, 21, 'kpi_card', 'all'),
            Tpl::kpi('Compliance training', 'hr_compliance_training', ['compliance_rate'], 9, 21, 'progress', 'all'),
        ],
    ],
    'exec_growth' => [
        'name' => 'Growth & Year-on-Year', 'group' => 'Executive', 'icon' => 'tabler-chart-arrows-vertical',
        'description' => 'What changed on last year — revenue, customers, spend, people and pipeline.',
        'widgets' => [
            Tpl::kpi('Revenue vs last year', 'fin_sales', ['growth_pct', 'last_year'], 0, 0, 'variance_card'),
            Tpl::kpi('Spend vs last year', 'fin_purchases', ['growth_pct', 'last_year'], 3, 0, 'variance_card'),
            Tpl::kpi('New hires vs last year', 'hr_people', ['joiners', 'growth_pct'], 6, 0, 'kpi_card'),
            Tpl::kpi('Leads vs last year', 'crm_leads', ['leads', 'growth_pct'], 9, 0, 'kpi_card'),
            Tpl::w('waterfall', 'What moved revenue (by customer)', 'fin_sales', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 8], 0, 4, 12, 9),
            Tpl::w('line_multi', 'Revenue: 24 months', 'fin_sales', ['measures' => ['revenue', 'last_year'], 'grain' => 'month', 'period' => 'last_24_months'], 0, 13, 6, 8),
            Tpl::w('waterfall', 'What moved spend (by supplier)', 'fin_purchases', ['measures' => ['revenue'], 'dimension' => 'party', 'top' => 8], 6, 13, 6, 8),
        ],
    ],
];
