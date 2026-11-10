<?php

use App\Modules\BI\Support\Tpl;

// Mkhaya Agri farm templates. Datasets: app/Modules/BI/Datasets/farm.php.

return [
    'farm_overview' => [
        'name' => 'Farm at a Glance', 'group' => 'Farm', 'icon' => 'tabler-plant-2',
        'description' => 'The owner\'s view: income, costs and margin, what is in the ground, harvest and the herd.',
        'widgets' => [
            Tpl::kpi('Farm income this year', 'farm_money', ['income', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Farm expenses', 'farm_money', ['expenses', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Margin', 'farm_money', ['margin_pct'], 6, 0, 'gauge'),
            Tpl::kpi('Animals on hand', 'livestock_herd', ['on_hand'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('combo_column_line', 'Income vs expenses by month', 'farm_money', ['measures' => ['income', 'expenses'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Expenses by category', 'farm_money', ['measures' => ['expenses'], 'dimension' => 'category'], 8, 4, 4, 9),
            Tpl::w('bar_clustered', 'Margin by farm', 'farm_money', ['measures' => ['income', 'expenses', 'margin'], 'dimension' => 'farm'], 0, 13, 6, 9),
            Tpl::w('treemap', 'Planted area by crop and farm', 'crop_plans', ['measures' => ['hectares'], 'dimension' => 'crop', 'series' => 'farm'], 6, 13, 6, 9),
            Tpl::w('smart_narrative', 'In short', 'farm_money', ['measures' => ['income', 'expenses', 'margin'], 'dimension' => 'farm'], 0, 22, 12, 5),
        ],
    ],
    'farm_enterprise' => [
        'name' => 'Enterprise Profitability', 'group' => 'Farm', 'icon' => 'tabler-report-money',
        'description' => 'Which enterprise and which field makes money: income, cost and margin per enterprise and field.',
        'widgets' => [
            Tpl::kpi('Income', 'farm_money', ['income'], 0),
            Tpl::kpi('Expenses', 'farm_money', ['expenses'], 3),
            Tpl::kpi('Margin', 'farm_money', ['margin'], 6, 0, 'variance_card'),
            Tpl::kpi('Transactions', 'farm_money', ['transactions'], 9),
            Tpl::w('waterfall', 'Margin change vs last year by enterprise', 'farm_money', ['measures' => ['margin'], 'dimension' => 'enterprise'], 0, 4, 6, 9),
            Tpl::w('bar_stacked', 'Costs by enterprise and category', 'farm_money', ['measures' => ['expenses'], 'dimension' => 'enterprise', 'series' => 'category'], 6, 4, 6, 9),
            Tpl::w('table', 'Field profitability', 'farm_money', ['measures' => ['income', 'expenses', 'margin', 'margin_pct'], 'dimension' => 'field', 'top' => 40], 0, 13, 12, 9),
        ],
    ],
    'crop_season' => [
        'name' => 'Crop Season', 'group' => 'Crops', 'icon' => 'tabler-seeding',
        'description' => 'What is planted where, the expected yield and revenue, and the plans still to go in.',
        'widgets' => [
            Tpl::kpi('Crop plans', 'crop_plans', ['plans'], 0),
            Tpl::kpi('Planned area', 'crop_plans', ['hectares'], 3),
            Tpl::kpi('Expected revenue', 'crop_plans', ['expected_revenue'], 6),
            Tpl::kpi('Crop budget', 'crop_plans', ['budget'], 9),
            Tpl::w('bar_100', 'Plan status by crop', 'crop_plans', ['measures' => ['plans'], 'dimension' => 'crop', 'series' => 'status'], 0, 4, 7, 9),
            Tpl::w('funnel', 'Growth stage', 'crop_plans', ['measures' => ['hectares'], 'dimension' => 'stage'], 7, 4, 5, 9),
            Tpl::w('bar_clustered', 'Expected revenue vs budget by crop', 'crop_plans', ['measures' => ['expected_revenue', 'budget'], 'dimension' => 'crop'], 0, 13, 12, 9),
        ],
    ],
    'crop_harvest' => [
        'name' => 'Harvest & Yield', 'group' => 'Crops', 'icon' => 'tabler-wheat',
        'description' => 'Harvested quantity and value, yield per hectare by field, rejects and losses.',
        'widgets' => [
            Tpl::kpi('Harvested', 'harvest', ['quantity', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Harvest value', 'harvest', ['value'], 3),
            Tpl::kpi('Yield per hectare', 'harvest', ['yield_per_ha'], 6),
            Tpl::kpi('Rejected', 'harvest', ['reject_rate'], 9, 0, 'traffic_light'),
            Tpl::w('column_stacked', 'Harvest by month and crop', 'harvest', ['measures' => ['quantity'], 'series' => 'crop', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'By grade', 'harvest', ['measures' => ['quantity'], 'dimension' => 'grade'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Yield per hectare by field', 'harvest', ['measures' => ['yield_per_ha'], 'dimension' => 'field', 'top' => 15], 0, 13, 6, 9),
            Tpl::w('bar_clustered', 'Rejects and losses by crop', 'harvest', ['measures' => ['rejected', 'losses'], 'dimension' => 'crop'], 6, 13, 6, 9),
        ],
    ],
    'crop_scouting' => [
        'name' => 'Scouting & Crop Health', 'group' => 'Crops', 'icon' => 'tabler-bug',
        'description' => 'Pests, disease and damage found in the fields, how serious, how much area, and how fast it is dealt with.',
        'widgets' => [
            Tpl::kpi('Observations', 'crop_scouting', ['observations', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Open', 'crop_scouting', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('High & critical', 'crop_scouting', ['serious'], 6, 0, 'traffic_light'),
            Tpl::kpi('Affected area (ha)', 'crop_scouting', ['affected_ha'], 9),
            Tpl::w('heatmap_matrix', 'Problems by field', 'crop_scouting', ['measures' => ['observations'], 'dimension' => 'field', 'series' => 'kind', 'top' => 12], 0, 4, 8, 10),
            Tpl::w('donut', 'By severity', 'crop_scouting', ['measures' => ['observations'], 'dimension' => 'severity'], 8, 4, 4, 10),
        ],
    ],
    'farm_operations' => [
        'name' => 'Field Work & Labour', 'group' => 'Operations', 'icon' => 'tabler-tractor',
        'description' => 'Work orders on the farm: open and late jobs, budget against actual, labour, material and machine cost.',
        'widgets' => [
            Tpl::kpi('Open work orders', 'farm_work', ['open'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Late', 'farm_work', ['late'], 3, 0, 'traffic_light', 'all'),
            Tpl::kpi('Actual cost', 'farm_work', ['actual_cost', 'growth_pct'], 6, 0, 'kpi_sparkline'),
            Tpl::kpi('Labour hours', 'farm_work', ['labour_hours'], 9),
            Tpl::w('bar_clustered', 'Budget vs actual by work type', 'farm_work', ['measures' => ['budget', 'actual_cost'], 'dimension' => 'category'], 0, 4, 7, 9),
            Tpl::w('donut', 'Cost split', 'farm_work', ['measures' => ['labour_cost'], 'dimension' => 'category'], 7, 4, 5, 9),
            Tpl::w('column_stacked', 'Work orders by month and status', 'farm_work', ['measures' => ['orders'], 'series' => 'status', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 12, 8),
        ],
    ],
    'farm_water' => [
        'name' => 'Water & Weather', 'group' => 'Operations', 'icon' => 'tabler-droplet',
        'description' => 'Rain, evapotranspiration and irrigation water applied, with the pumping energy and cost.',
        'widgets' => [
            Tpl::kpi('Rainfall (mm)', 'weather', ['rain_mm'], 0),
            Tpl::kpi('Water applied (m³)', 'irrigation', ['water_m3', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Pump energy (kWh)', 'irrigation', ['energy_kwh'], 6),
            Tpl::kpi('Cost per m³', 'irrigation', ['cost_per_m3'], 9),
            Tpl::w('column_simple', 'Rainfall by month', 'weather', ['measures' => ['rain_mm'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 6, 8),
            Tpl::w('line', 'Irrigation water by month', 'irrigation', ['measures' => ['water_m3', 'planned_m3'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 8),
            Tpl::w('bar_ranked', 'Water applied by field', 'irrigation', ['measures' => ['water_m3'], 'dimension' => 'field', 'top' => 15], 0, 12, 12, 8),
        ],
    ],
    'livestock_herd' => [
        'name' => 'Livestock Herd', 'group' => 'Livestock', 'icon' => 'tabler-paw',
        'description' => 'Herd size by species and farm, births, purchases, deaths and sales, and average weight.',
        'widgets' => [
            Tpl::kpi('On hand', 'livestock_herd', ['on_hand'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Born this year', 'livestock_herd', ['born'], 3),
            Tpl::kpi('Mortality', 'livestock_herd', ['mortality_pct'], 6, 0, 'traffic_light'),
            Tpl::kpi('Sale value', 'livestock_herd', ['sale_value'], 9),
            Tpl::w('bar_stacked', 'Herd by farm and species', 'livestock_herd', ['measures' => ['on_hand'], 'dimension' => 'farm', 'series' => 'species', 'period' => 'all'], 0, 4, 7, 9),
            Tpl::w('donut', 'Why animals left', 'livestock_herd', ['measures' => ['animals'], 'dimension' => 'exit_cause'], 7, 4, 5, 9),
            Tpl::w('bar_clustered', 'Births, purchases, deaths and sales by species', 'livestock_herd', ['measures' => ['born', 'bought', 'died', 'sold'], 'dimension' => 'species'], 0, 13, 12, 9),
        ],
    ],
    'livestock_health' => [
        'name' => 'Animal Health & Production', 'group' => 'Livestock', 'icon' => 'tabler-vaccine',
        'description' => 'Treatments and their cost, animals still in withdrawal, notifiable diseases and milk, egg and wool output.',
        'widgets' => [
            Tpl::kpi('Treatments', 'livestock_health', ['treatments', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Health cost', 'livestock_health', ['cost'], 3),
            Tpl::kpi('Still in withdrawal', 'livestock_health', ['in_withdrawal'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Production value', 'livestock_output', ['value'], 9),
            Tpl::w('bar_ranked', 'Treatments by condition', 'livestock_health', ['measures' => ['treatments'], 'dimension' => 'condition', 'top' => 12], 0, 4, 6, 9),
            Tpl::w('line_multi', 'Production by month', 'livestock_output', ['measures' => ['quantity', 'withheld'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 9),
        ],
    ],
    'packhouse_grain' => [
        'name' => 'Packhouse & Grain', 'group' => 'Market', 'icon' => 'tabler-package',
        'description' => 'Produce packed and still in store, short-life stock, and grain delivered with its deductions.',
        'widgets' => [
            Tpl::kpi('Value in store', 'packhouse_lots', ['stock_value'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Best before in 14 days', 'packhouse_lots', ['expiring_14'], 3, 0, 'traffic_light', 'all'),
            Tpl::kpi('Grain net (t)', 'grain_intake', ['net_t', 'growth_pct'], 6, 0, 'kpi_sparkline'),
            Tpl::kpi('Average moisture', 'grain_intake', ['avg_moisture'], 9),
            Tpl::w('bar_ranked', 'Stock in store by product', 'packhouse_lots', ['measures' => ['in_stock'], 'dimension' => 'product', 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('bar_clustered', 'Grain net vs settled by crop', 'grain_intake', ['measures' => ['net_t', 'settled_t', 'deductions_t'], 'dimension' => 'crop'], 6, 4, 6, 9),
        ],
    ],
    'outgrowers' => [
        'name' => 'Outgrowers', 'group' => 'Market', 'icon' => 'tabler-users-group',
        'description' => 'Inputs and cash advanced to outgrowers, how much has been recovered, and who still owes.',
        'widgets' => [
            Tpl::kpi('Advanced', 'outgrower_advances', ['advanced'], 0),
            Tpl::kpi('Recovered', 'outgrower_advances', ['recovery_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Still to recover', 'outgrower_advances', ['outstanding'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Growers', 'outgrower_advances', ['growers'], 9),
            Tpl::w('ranking_table', 'Growers still owing', 'outgrower_advances', ['measures' => ['outstanding', 'advanced', 'recovered'], 'dimension' => 'grower', 'top' => 30, 'period' => 'all'], 0, 4, 8, 10),
            Tpl::w('donut', 'Advances by type', 'outgrower_advances', ['measures' => ['advanced'], 'dimension' => 'kind'], 8, 4, 4, 10),
        ],
    ],
];
