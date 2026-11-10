<?php

// Mkhaya Agri farm datasets: farm money, crops, harvest, field work, livestock, packhouse, grain,
// irrigation, weather and outgrowers. All belong to the `agri` module. Farm-level access
// (FarmAccessScope) applies on the farm screens; BI shows company totals to people with BI access.
// Format: see App\Modules\BI\Support\DatasetRegistry. No named functions: this file may be required twice.

$words = fn (string $col, string $fallback = 'Unknown') => "coalesce(nullif(initcap(replace({$col}::text, '_', ' ')), ''), '{$fallback}')";
$days = fn (string $from, string $to) => "extract(epoch from ({$to} - {$from})) / 86400.0";
$farm = ['label' => 'Farm', 'sql' => "coalesce(fm.name, 'Unassigned')"];
$field = ['label' => 'Field', 'sql' => "coalesce(ff.name, 'No field')"];

return [
    // ── Farm money ─────────────────────────────────────────────────────────────────────────────────
    'farm_money' => [
        'module' => 'agri',
        'label' => 'Farm income & expenses',
        'from' => 'farm_transactions t left join farms fm on fm.id = t.farm_id left join farm_fields ff on ff.id = t.field_id',
        'company' => 't.company_id',
        'date' => 't.transacted_on',
        'where' => 't.reversed_at is null',
        'measures' => [
            'income' => ['label' => 'Income', 'sql' => "coalesce(sum(t.amount) filter (where t.kind = 'sale'), 0)", 'format' => 'currency'],
            'expenses' => ['label' => 'Expenses', 'sql' => "coalesce(sum(t.amount) filter (where t.kind = 'expense'), 0)", 'format' => 'currency'],
            'margin' => ['label' => 'Margin', 'sql' => "coalesce(sum(case when t.kind = 'sale' then t.amount when t.kind = 'expense' then -t.amount else 0 end), 0)", 'format' => 'currency'],
            'margin_pct' => ['label' => 'Margin %', 'ratio' => ['margin', 'income'], 'scale' => 100, 'format' => 'percent'],
            'transactions' => ['label' => 'Transactions', 'sql' => 'count(*)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'kind' => ['label' => 'Income or expense', 'sql' => $words('t.kind')],
            'category' => ['label' => 'Category', 'sql' => $words('t.category', 'Other')],
            'enterprise' => ['label' => 'Enterprise', 'sql' => $words('t.enterprise', 'General')],
            'counterparty' => ['label' => 'Counterparty', 'sql' => "coalesce(nullif(t.counterparty, ''), 'Unknown')"],
        ],
    ],

    // ── Crops & harvest ────────────────────────────────────────────────────────────────────────────
    'crop_plans' => [
        'module' => 'agri',
        'label' => 'Crop plans',
        'from' => 'crop_plans cp left join farms fm on fm.id = cp.farm_id left join farm_fields ff on ff.id = cp.field_id left join crop_types ct on ct.id = cp.crop_type_id',
        'company' => 'cp.company_id',
        'date' => 'coalesce(cp.planted_on, cp.planned_planting_on, cp.created_at::date)',
        'measures' => [
            'plans' => ['label' => 'Crop plans', 'sql' => 'count(*)', 'format' => 'number'],
            'live' => ['label' => 'In the ground / planned', 'sql' => "count(*) filter (where cp.status in ('draft', 'approved', 'planted', 'harvesting'))", 'format' => 'number'],
            'hectares' => ['label' => 'Planned area', 'sql' => 'coalesce(sum(cp.planned_area), 0)', 'format' => 'number'],
            'expected_yield' => ['label' => 'Expected yield', 'sql' => 'coalesce(sum(cp.expected_yield), 0)', 'format' => 'number'],
            'expected_revenue' => ['label' => 'Expected revenue', 'sql' => 'coalesce(sum(cp.expected_yield * cp.expected_price), 0)', 'format' => 'currency'],
            'budget' => ['label' => 'Crop budget', 'sql' => 'coalesce(sum(cp.budget_amount), 0)', 'format' => 'currency'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'crop' => ['label' => 'Crop', 'sql' => "coalesce(ct.name, 'Unknown')"],
            'crop_category' => ['label' => 'Crop category', 'sql' => $words('ct.category', 'Other')],
            'status' => ['label' => 'Status', 'sql' => $words('cp.status')],
            'stage' => ['label' => 'Growth stage', 'sql' => $words('cp.current_stage', 'Not recorded')],
            'method' => ['label' => 'Production method', 'sql' => $words('cp.production_method', 'Conventional')],
        ],
    ],
    'harvest' => [
        'module' => 'agri',
        'label' => 'Harvest',
        'from' => 'harvest_records h left join farms fm on fm.id = h.farm_id left join farm_fields ff on ff.id = h.field_id'
            . ' left join crop_plans cp on cp.id = h.crop_plan_id left join crop_types ct on ct.id = cp.crop_type_id',
        'company' => 'h.company_id',
        'date' => 'h.harvested_on',
        'measures' => [
            'quantity' => ['label' => 'Harvested', 'sql' => 'coalesce(sum(h.quantity), 0)', 'format' => 'number'],
            'value' => ['label' => 'Harvest value', 'sql' => 'coalesce(sum(h.quantity * h.unit_value), 0)', 'format' => 'currency'],
            'rejected' => ['label' => 'Rejected', 'sql' => 'coalesce(sum(h.rejected_quantity), 0)', 'format' => 'number'],
            'losses' => ['label' => 'Spoiled & field loss', 'sql' => 'coalesce(sum(coalesce(h.spoiled_quantity, 0) + coalesce(h.field_loss_quantity, 0)), 0)', 'format' => 'number'],
            'area' => ['label' => 'Area harvested', 'sql' => 'coalesce(sum(h.area_harvested), 0)', 'format' => 'number'],
            'yield_per_ha' => ['label' => 'Yield per hectare', 'ratio' => ['quantity', 'area'], 'format' => 'number'],
            'loads' => ['label' => 'Harvest loads', 'sql' => 'count(*)', 'format' => 'number'],
            'reject_rate' => ['label' => 'Rejected %', 'ratio' => ['rejected', 'quantity'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'crop' => ['label' => 'Crop', 'sql' => "coalesce(ct.name, 'Unknown')"],
            'grade' => ['label' => 'Grade', 'sql' => "coalesce(nullif(h.grade, ''), 'Ungraded')"],
            'destination' => ['label' => 'Destination', 'sql' => $words('h.destination', 'Store')],
        ],
    ],
    'crop_scouting' => [
        'module' => 'agri',
        'label' => 'Scouting observations',
        'from' => 'crop_observations o left join farms fm on fm.id = o.farm_id left join farm_fields ff on ff.id = o.field_id',
        'company' => 'o.company_id',
        'date' => 'o.observed_on',
        'measures' => [
            'observations' => ['label' => 'Observations', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where o.status = 'open')", 'format' => 'number'],
            'serious' => ['label' => 'High & critical', 'sql' => "count(*) filter (where o.severity in ('high', 'critical'))", 'format' => 'number'],
            'affected_ha' => ['label' => 'Affected area (ha)', 'sql' => 'coalesce(sum(o.affected_area_ha), 0)', 'format' => 'number'],
            'avg_days_to_close' => ['label' => 'Average days to close', 'sql' => 'coalesce(avg(' . $days('o.created_at', 'o.closed_at') . '), 0)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'kind' => ['label' => 'Problem', 'sql' => $words('o.kind')],
            'severity' => ['label' => 'Severity', 'sql' => $words('o.severity')],
            'status' => ['label' => 'Status', 'sql' => $words('o.status')],
        ],
    ],

    // ── Field work ─────────────────────────────────────────────────────────────────────────────────
    'farm_work' => [
        'module' => 'agri',
        'label' => 'Farm work orders',
        'from' => 'farm_work_orders w left join farms fm on fm.id = w.farm_id left join farm_fields ff on ff.id = w.field_id',
        'company' => 'w.company_id',
        'date' => 'coalesce(w.planned_start, w.created_at)',
        'measures' => [
            'orders' => ['label' => 'Work orders', 'sql' => 'count(*)', 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where w.status = 'completed')", 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where w.status not in ('completed', 'cancelled'))", 'format' => 'number'],
            'late' => ['label' => 'Late', 'sql' => "count(*) filter (where w.status not in ('completed', 'cancelled') and w.planned_end < now())", 'format' => 'number'],
            'budget' => ['label' => 'Budget', 'sql' => 'coalesce(sum(w.budget_amount), 0)', 'format' => 'currency'],
            'actual_cost' => ['label' => 'Actual cost', 'sql' => 'coalesce(sum(coalesce(w.actual_labour_cost, 0) + coalesce(w.actual_material_cost, 0) + coalesce(w.actual_machine_cost, 0)), 0)', 'format' => 'currency'],
            'labour_cost' => ['label' => 'Labour cost', 'sql' => 'coalesce(sum(w.actual_labour_cost), 0)', 'format' => 'currency'],
            'material_cost' => ['label' => 'Material cost', 'sql' => 'coalesce(sum(w.actual_material_cost), 0)', 'format' => 'currency'],
            'machine_cost' => ['label' => 'Machine cost', 'sql' => 'coalesce(sum(w.actual_machine_cost), 0)', 'format' => 'currency'],
            'labour_hours' => ['label' => 'Labour hours', 'sql' => 'coalesce(sum(w.actual_labour_minutes), 0) / 60.0', 'format' => 'number'],
            'completion_rate' => ['label' => 'Completed %', 'ratio' => ['completed', 'orders'], 'scale' => 100, 'format' => 'percent'],
            'reopened' => ['label' => 'Reopened', 'sql' => 'count(*) filter (where w.reopen_count > 0)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'category' => ['label' => 'Work type', 'sql' => $words('w.category')],
            'status' => ['label' => 'Status', 'sql' => $words('w.status')],
            'priority' => ['label' => 'Priority', 'sql' => $words('w.priority', 'Normal')],
        ],
    ],
    'irrigation' => [
        'module' => 'agri',
        'label' => 'Irrigation',
        'from' => 'irrigation_events ie left join farms fm on fm.id = ie.farm_id left join farm_fields ff on ff.id = ie.field_id',
        'company' => 'ie.company_id',
        'date' => 'coalesce(ie.actual_start, ie.planned_start)',
        'measures' => [
            'water_m3' => ['label' => 'Water applied (m³)', 'sql' => 'coalesce(sum(ie.actual_m3), 0)', 'format' => 'number'],
            'planned_m3' => ['label' => 'Water planned (m³)', 'sql' => 'coalesce(sum(ie.planned_m3), 0)', 'format' => 'number'],
            'energy_kwh' => ['label' => 'Pump energy (kWh)', 'sql' => 'coalesce(sum(ie.energy_kwh), 0)', 'format' => 'number'],
            'cost' => ['label' => 'Water & energy cost', 'sql' => 'coalesce(sum(coalesce(ie.water_cost, 0) + coalesce(ie.energy_cost, 0)), 0)', 'format' => 'currency'],
            'events' => ['label' => 'Irrigation runs', 'sql' => 'count(*)', 'format' => 'number'],
            'cost_per_m3' => ['label' => 'Cost per m³', 'ratio' => ['cost', 'water_m3'], 'format' => 'currency'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'source' => ['label' => 'Recorded by', 'sql' => $words('ie.source', 'Manual')],
            'status' => ['label' => 'Status', 'sql' => $words('ie.status')],
        ],
    ],
    'weather' => [
        'module' => 'agri',
        'label' => 'Weather',
        'from' => 'agri_weather wx left join farms fm on fm.id = wx.farm_id left join farm_fields ff on ff.id = wx.field_id',
        'company' => 'wx.company_id',
        'date' => 'wx.period_start',
        'where' => "wx.kind = 'observed'",
        'measures' => [
            'rain_mm' => ['label' => 'Rainfall (mm)', 'sql' => 'coalesce(sum(wx.rain_mm), 0)', 'format' => 'number'],
            'avg_temp' => ['label' => 'Average temperature (°C)', 'sql' => 'coalesce(avg(wx.temperature), 0)', 'format' => 'number'],
            'max_temp' => ['label' => 'Highest temperature (°C)', 'sql' => 'coalesce(max(wx.temp_max), 0)', 'format' => 'number'],
            'et0_mm' => ['label' => 'Evapotranspiration (mm)', 'sql' => 'coalesce(sum(wx.et0_mm), 0)', 'format' => 'number'],
        ],
        'dimensions' => ['farm' => $farm, 'conditions' => ['label' => 'Conditions', 'sql' => $words('wx.conditions', 'Not recorded')]],
    ],

    // ── Livestock ──────────────────────────────────────────────────────────────────────────────────
    'livestock_herd' => [
        'module' => 'agri',
        'label' => 'Livestock herd',
        'from' => 'animals a left join farms fm on fm.id = a.farm_id left join livestock_species sp on sp.id = a.species_id left join livestock_breeds br on br.id = a.breed_id',
        'company' => 'a.company_id',
        'date' => 'coalesce(a.acquired_on, a.birth_date, a.created_at::date)',
        'measures' => [
            'animals' => ['label' => 'Animals', 'sql' => 'count(*)', 'format' => 'number'],
            'on_hand' => ['label' => 'On hand', 'sql' => "count(*) filter (where a.status = 'active')", 'format' => 'number'],
            'born' => ['label' => 'Born', 'sql' => "count(*) filter (where a.acquisition = 'born')", 'format' => 'number'],
            'bought' => ['label' => 'Bought', 'sql' => "count(*) filter (where a.acquisition = 'purchased')", 'format' => 'number'],
            'died' => ['label' => 'Died', 'sql' => "count(*) filter (where a.status = 'died')", 'format' => 'number'],
            'sold' => ['label' => 'Sold', 'sql' => "count(*) filter (where a.status = 'sold')", 'format' => 'number'],
            'mortality_pct' => ['label' => 'Mortality %', 'ratio' => ['died', 'animals'], 'scale' => 100, 'format' => 'percent'],
            'sale_value' => ['label' => 'Sale value', 'sql' => "coalesce(sum(a.exit_value) filter (where a.status = 'sold'), 0)", 'format' => 'currency'],
            'avg_weight' => ['label' => 'Average weight (kg)', 'sql' => 'coalesce(avg(a.last_weight_kg), 0)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm,
            'species' => ['label' => 'Species', 'sql' => "coalesce(sp.name, 'Unknown')"],
            'breed' => ['label' => 'Breed', 'sql' => "coalesce(br.name, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => $words('a.status')],
            'sex' => ['label' => 'Sex', 'sql' => $words('a.sex')],
            'exit_cause' => ['label' => 'Exit cause', 'sql' => $words('coalesce(a.exit_cause, a.exit_reason)', 'Still on farm')],
        ],
    ],
    'livestock_output' => [
        'module' => 'agri',
        'label' => 'Livestock production (milk, eggs, wool)',
        'from' => 'livestock_production lp left join farms fm on fm.id = lp.farm_id',
        'company' => 'lp.company_id',
        'date' => 'lp.recorded_on',
        'measures' => [
            'quantity' => ['label' => 'Produced', 'sql' => 'coalesce(sum(lp.quantity), 0)', 'format' => 'number'],
            'value' => ['label' => 'Value', 'sql' => 'coalesce(sum(lp.value), 0)', 'format' => 'currency'],
            'withheld' => ['label' => 'Withheld (withdrawal)', 'sql' => 'coalesce(sum(lp.quantity) filter (where lp.withheld), 0)', 'format' => 'number'],
            'records' => ['label' => 'Records', 'sql' => 'count(*)', 'format' => 'number'],
        ],
        'dimensions' => ['farm' => $farm, 'product' => ['label' => 'Product', 'sql' => $words('lp.product')], 'grade' => ['label' => 'Grade', 'sql' => "coalesce(nullif(lp.grade, ''), 'Ungraded')"]],
    ],
    'livestock_health' => [
        'module' => 'agri',
        'label' => 'Animal health',
        'from' => 'animal_health_records hr left join farms fm on fm.id = hr.farm_id',
        'company' => 'hr.company_id',
        'date' => 'hr.treated_on',
        'measures' => [
            'treatments' => ['label' => 'Treatments', 'sql' => 'count(*)', 'format' => 'number'],
            'head_treated' => ['label' => 'Head treated', 'sql' => 'coalesce(sum(coalesce(hr.head_count, 1)), 0)', 'format' => 'number'],
            'cost' => ['label' => 'Health cost', 'sql' => 'coalesce(sum(hr.cost), 0)', 'format' => 'currency'],
            'notifiable' => ['label' => 'Notifiable diseases', 'sql' => 'count(*) filter (where hr.notifiable)', 'format' => 'number'],
            'in_withdrawal' => ['label' => 'Still in withdrawal', 'sql' => 'count(*) filter (where hr.withdrawal_until >= current_date)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm,
            'event' => ['label' => 'Treatment type', 'sql' => $words('hr.event_type')],
            'condition' => ['label' => 'Condition', 'sql' => "coalesce(nullif(hr.condition, ''), 'Routine')"],
            'product' => ['label' => 'Product', 'sql' => "coalesce(nullif(hr.product_name, ''), 'None')"],
        ],
    ],

    // ── Packhouse, grain & outgrowers ──────────────────────────────────────────────────────────────
    'packhouse_lots' => [
        'module' => 'agri',
        'label' => 'Produce lots',
        'from' => 'produce_lots pl left join farms fm on fm.id = pl.farm_id left join farm_fields ff on ff.id = pl.field_id',
        'company' => 'pl.company_id',
        'date' => 'pl.produced_on',
        'measures' => [
            'lots' => ['label' => 'Lots', 'sql' => 'count(*)', 'format' => 'number'],
            'quantity' => ['label' => 'Packed quantity', 'sql' => 'coalesce(sum(pl.quantity), 0)', 'format' => 'number'],
            'in_stock' => ['label' => 'Still in store', 'sql' => 'coalesce(sum(pl.remaining), 0)', 'format' => 'number'],
            'stock_value' => ['label' => 'Value in store', 'sql' => 'coalesce(sum(pl.remaining * pl.unit_cost), 0)', 'format' => 'currency'],
            'expiring_14' => ['label' => 'Best before in 14 days', 'sql' => 'count(*) filter (where pl.remaining > 0 and pl.best_before between current_date and current_date + 14)', 'format' => 'number'],
        ],
        'dimensions' => [
            'farm' => $farm,
            'product' => ['label' => 'Product', 'sql' => "coalesce(nullif(pl.product, ''), 'Unknown')"],
            'kind' => ['label' => 'Lot type', 'sql' => $words('pl.kind')],
            'grade' => ['label' => 'Grade', 'sql' => "coalesce(nullif(pl.grade, ''), 'Ungraded')"],
            'status' => ['label' => 'Status', 'sql' => $words('pl.status')],
        ],
    ],
    'grain_intake' => [
        'module' => 'agri',
        'label' => 'Grain tickets',
        'from' => 'agri_grain_tickets g left join farms fm on fm.id = g.farm_id left join farm_fields ff on ff.id = g.field_id left join crop_types ct on ct.id = g.crop_type_id',
        'company' => 'g.company_id',
        'date' => 'coalesce(g.second_weighed_at, g.first_weighed_at, g.created_at)',
        'where' => "g.status <> 'void'",
        'measures' => [
            'tickets' => ['label' => 'Tickets', 'sql' => 'count(*)', 'format' => 'number'],
            'net_t' => ['label' => 'Net mass (t)', 'sql' => 'coalesce(sum(g.net_kg), 0) / 1000.0', 'format' => 'number'],
            'settled_t' => ['label' => 'Settled mass (t)', 'sql' => 'coalesce(sum(g.settled_kg), 0) / 1000.0', 'format' => 'number'],
            'deductions_t' => ['label' => 'Deductions (t)', 'sql' => 'coalesce(sum(coalesce(g.moisture_deduction_kg, 0) + coalesce(g.handling_deduction_kg, 0) + coalesce(g.screenings_deduction_kg, 0)), 0) / 1000.0', 'format' => 'number'],
            'avg_moisture' => ['label' => 'Average moisture %', 'sql' => 'coalesce(avg(g.moisture_pct), 0)', 'format' => 'percent'],
            'value' => ['label' => 'Value', 'sql' => 'coalesce(sum(g.settled_kg * g.unit_value), 0)', 'format' => 'currency'],
        ],
        'dimensions' => [
            'farm' => $farm, 'field' => $field,
            'crop' => ['label' => 'Crop', 'sql' => "coalesce(ct.name, 'Unknown')"],
            'grade' => ['label' => 'Grade', 'sql' => "coalesce(nullif(g.grade, ''), 'Ungraded')"],
            'movement' => ['label' => 'Movement', 'sql' => $words('g.movement')],
            'destination' => ['label' => 'Destination', 'sql' => "coalesce(nullif(g.destination_name, ''), 'Own store')"],
        ],
    ],
    'outgrower_advances' => [
        'module' => 'agri',
        'label' => 'Outgrower advances',
        'from' => 'agri_grower_advances ad left join agri_growers gr on gr.id = ad.grower_id left join fin_parties pa on pa.id = gr.party_id left join farms fm on fm.id = gr.farm_id',
        'company' => 'ad.company_id',
        'date' => 'ad.issued_on',
        'measures' => [
            'advanced' => ['label' => 'Advanced', 'sql' => 'coalesce(sum(ad.value), 0)', 'format' => 'currency'],
            'recovered' => ['label' => 'Recovered', 'sql' => 'coalesce(sum(ad.recovered), 0)', 'format' => 'currency'],
            'outstanding' => ['label' => 'Still to recover', 'sql' => 'coalesce(sum(greatest(ad.value - coalesce(ad.recovered, 0), 0)), 0)', 'format' => 'currency'],
            'recovery_rate' => ['label' => 'Recovered %', 'ratio' => ['recovered', 'advanced'], 'scale' => 100, 'format' => 'percent'],
            'growers' => ['label' => 'Growers', 'sql' => 'count(distinct ad.grower_id)', 'format' => 'number'],
        ],
        'dimensions' => [
            'grower' => ['label' => 'Grower', 'sql' => "coalesce(pa.name, gr.code, 'Unknown')"],
            'kind' => ['label' => 'Advance type', 'sql' => $words('ad.kind')],
            'farm' => $farm,
        ],
    ],
];
