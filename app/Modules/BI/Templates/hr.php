<?php

use App\Modules\BI\Support\Tpl;

// Human Resources templates. Datasets: app/Modules/BI/Datasets/hr.php.
// Layout: KPI row at y=0 (h=4, four cards of w=3), then charts below on a 12-column grid.
// Ratio measures are only used where the engine computes them (KPIs, categories, plain time series, tables).

$g = 'Human Resources';

return [

    // ── People ──────────────────────────────────────────────────────────────────────────────────
    'hr_overview' => [
        'name' => 'HR Overview', 'group' => $g, 'icon' => 'tabler-users-group',
        'description' => 'Headcount, hiring, leave, attendance and payroll cost — the people side of the business on one page.',
        'widgets' => [
            Tpl::kpi('Active headcount', 'hr_people', ['headcount'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Payroll cost this year', 'hr_payslips', ['gross', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Leave days taken', 'hr_leave', ['days', 'growth_pct'], 6, 0, 'kpi_sparkline'),
            Tpl::kpi('Absenteeism', 'hr_attendance', ['absenteeism_rate'], 9, 0, 'gauge'),
            Tpl::w('bar_ranked', 'Headcount by department', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'department', 'top' => 12, 'period' => 'all'], 0, 4, 8, 9),
            Tpl::w('donut', 'Gender split', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'gender', 'period' => 'all'], 8, 4, 4, 9),
            Tpl::w('combo_column_line', 'Payroll cost by month vs last year', 'hr_payslips', ['measures' => ['gross', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('funnel', 'Recruitment funnel', 'hr_recruitment', ['measures' => ['applications'], 'dimension' => 'funnel_stage', 'sort' => 'chrono'], 6, 13, 6, 8),
            Tpl::w('line_multi', 'Attendance vs absence by month', 'hr_attendance', ['measures' => ['present', 'absent'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 21, 6, 8),
            Tpl::w('donut', 'Leave taken by type', 'hr_leave', ['measures' => ['days'], 'dimension' => 'leave_type', 'top' => 6, 'other' => true], 6, 21, 6, 8),
            Tpl::w('smart_narrative', 'In short', 'hr_payslips', ['measures' => ['gross', 'net', 'employees'], 'dimension' => 'department'], 0, 29, 12, 5),
        ],
    ],

    'hr_headcount' => [
        'name' => 'Headcount & Demographics', 'group' => $g, 'icon' => 'tabler-users',
        'description' => 'Who works here: headcount by department and position, gender, age and length of service.',
        'widgets' => [
            Tpl::kpi('Active headcount', 'hr_people', ['headcount'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Women in the workforce', 'hr_people', ['female_pct'], 3, 0, 'gauge', 'all'),
            Tpl::kpi('Average age', 'hr_people', ['avg_age'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average years of service', 'hr_people', ['avg_tenure'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_ranked', 'Headcount by department', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'department', 'top' => 12, 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('treemap', 'Department and position', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'department', 'series' => 'position', 'top' => 8, 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('column_clustered', 'Age profile', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'age_band', 'sort' => 'chrono', 'period' => 'all'], 0, 13, 4, 8),
            Tpl::w('column_clustered', 'Length of service', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'tenure_band', 'sort' => 'chrono', 'period' => 'all'], 4, 13, 4, 8),
            Tpl::w('donut', 'Gender', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'gender', 'period' => 'all'], 8, 13, 4, 8),
            Tpl::w('bar_ranked', 'Where our people live', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'province', 'top' => 9, 'period' => 'all'], 0, 21, 6, 8),
            Tpl::w('table', 'Department profile', 'hr_people', ['measures' => ['headcount', 'female', 'male', 'avg_age', 'avg_tenure'], 'dimension' => 'department', 'period' => 'all'], 6, 21, 6, 8),
        ],
    ],

    'hr_employment_equity' => [
        'name' => 'Employment Equity Profile', 'group' => $g, 'icon' => 'tabler-scale',
        'description' => 'The EEA2-style workforce profile: race, gender, disability and occupational level, plus pay by group.',
        'widgets' => [
            Tpl::kpi('Active headcount', 'hr_people', ['headcount'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Women %', 'hr_people', ['female_pct'], 3, 0, 'gauge', 'all'),
            Tpl::kpi('People with disability %', 'hr_people', ['disabled_pct'], 6, 0, 'progress', 'all'),
            Tpl::kpi('Average monthly package', 'hr_salary_bill', ['avg_package'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('heatmap_matrix', 'Occupational level by race', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'occupational_level', 'series' => 'race', 'period' => 'all'], 0, 4, 12, 9),
            Tpl::w('bar_stacked', 'Occupational level by gender', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'occupational_level', 'series' => 'gender', 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('donut', 'Race', 'hr_people', ['measures' => ['headcount'], 'dimension' => 'race', 'period' => 'all'], 6, 13, 6, 9),
            Tpl::w('bar_ranked', 'Average package by race', 'hr_salary_bill', ['measures' => ['avg_package'], 'dimension' => 'race', 'period' => 'all'], 0, 22, 6, 8),
            Tpl::w('bar_ranked', 'Average package by gender', 'hr_salary_bill', ['measures' => ['avg_package'], 'dimension' => 'gender', 'period' => 'all'], 6, 22, 6, 8),
            Tpl::w('table', 'Profile by occupational level', 'hr_people', ['measures' => ['headcount', 'female', 'male', 'disabled', 'female_pct'], 'dimension' => 'occupational_level', 'period' => 'all'], 0, 30, 12, 9),
        ],
    ],

    'hr_turnover' => [
        'name' => 'Hires & Turnover', 'group' => $g, 'icon' => 'tabler-arrows-left-right',
        'description' => 'Who joined, who left, from which departments, and how many leave inside their first year.',
        'widgets' => [
            Tpl::kpi('Joined this year', 'hr_people', ['joiners', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Left this year', 'hr_leavers', ['leavers', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('First-year exits', 'hr_leavers', ['early_exit_pct'], 6, 0, 'gauge'),
            Tpl::kpi('Service at exit (years)', 'hr_leavers', ['avg_tenure'], 9),
            Tpl::w('column_simple', 'Joiners by month', 'hr_people', ['measures' => ['joiners'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 6, 8),
            Tpl::w('column_simple', 'Leavers by month', 'hr_leavers', ['measures' => ['leavers'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 8),
            Tpl::w('bar_ranked', 'Leavers by department', 'hr_leavers', ['measures' => ['leavers'], 'dimension' => 'department', 'top' => 10, 'period' => 'last_12_months'], 0, 12, 6, 9),
            Tpl::w('donut', 'Leavers by length of service', 'hr_leavers', ['measures' => ['leavers'], 'dimension' => 'tenure_band', 'period' => 'last_12_months'], 6, 12, 6, 9),
            Tpl::w('bar_ranked', 'Joiners by department', 'hr_people', ['measures' => ['joiners'], 'dimension' => 'department', 'top' => 10, 'period' => 'last_12_months'], 0, 21, 6, 8),
            Tpl::w('waterfall', 'Leavers vs last year, by department', 'hr_leavers', ['measures' => ['leavers'], 'dimension' => 'department', 'top' => 8], 6, 21, 6, 8),
        ],
    ],

    'hr_exits' => [
        'name' => 'Exits & Exit Interviews', 'group' => $g, 'icon' => 'tabler-door-exit',
        'description' => 'Why people leave, how they felt about working here, and which exits are still being processed.',
        'widgets' => [
            Tpl::kpi('Exits this year', 'hr_offboarding', ['exits', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Voluntary exits', 'hr_offboarding', ['resignation_pct'], 3, 0, 'gauge'),
            Tpl::kpi('Exit satisfaction (1–5)', 'hr_offboarding', ['satisfaction'], 6),
            Tpl::kpi('Would recommend us', 'hr_offboarding', ['recommend_pct'], 9, 0, 'progress'),
            Tpl::w('donut', 'Reason for exit', 'hr_offboarding', ['measures' => ['exits'], 'dimension' => 'termination_type'], 0, 4, 4, 9),
            Tpl::w('bar_ranked', 'Reasons given at exit interviews', 'hr_offboarding', ['measures' => ['exits'], 'dimension' => 'reason', 'top' => 10], 4, 4, 8, 9),
            Tpl::w('line_multi', 'Exits by month', 'hr_offboarding', ['measures' => ['exits', 'resignations'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('bar_ranked', 'Exits by department', 'hr_offboarding', ['measures' => ['exits'], 'dimension' => 'department', 'top' => 10], 6, 13, 6, 8),
            Tpl::w('table', 'Exits still being processed', 'hr_offboarding', ['measures' => ['open', 'exits', 'interviewed'], 'dimension' => 'employee', 'period' => 'all'], 0, 21, 6, 9),
            Tpl::w('donut', 'Rehire eligibility', 'hr_offboarding', ['measures' => ['exits'], 'dimension' => 'rehire'], 6, 21, 6, 9),
        ],
    ],

    // ── Leave ───────────────────────────────────────────────────────────────────────────────────
    'hr_leave_usage' => [
        'name' => 'Leave Usage', 'group' => $g, 'icon' => 'tabler-beach',
        'description' => 'Leave taken by type, department and month, and how quickly applications are dealt with.',
        'widgets' => [
            Tpl::kpi('Leave days taken', 'hr_leave', ['days', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Applications', 'hr_leave', ['applications'], 3),
            Tpl::kpi('Awaiting approval', 'hr_leave', ['pending'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Approval rate', 'hr_leave', ['approval_rate'], 9, 0, 'gauge'),
            Tpl::w('column_stacked', 'Leave days by month and type', 'hr_leave', ['measures' => ['days'], 'series' => 'leave_type', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Days by leave type', 'hr_leave', ['measures' => ['days'], 'dimension' => 'leave_type', 'top' => 6, 'other' => true], 8, 4, 4, 9),
            Tpl::w('heatmap_matrix', 'Department by leave type (days)', 'hr_leave', ['measures' => ['days'], 'dimension' => 'department', 'series' => 'leave_type', 'top' => 12], 0, 13, 8, 9),
            Tpl::w('donut', 'Applications by status', 'hr_leave', ['measures' => ['applications'], 'dimension' => 'status'], 8, 13, 4, 9),
            Tpl::w('calendar_heatmap', 'Leave calendar (days starting)', 'hr_leave', ['measures' => ['days'], 'period' => 'last_12_months'], 0, 22, 12, 7),
            Tpl::w('table', 'Leave by employee', 'hr_leave', ['measures' => ['days', 'applications', 'approved', 'pending', 'rejected'], 'dimension' => 'employee'], 0, 29, 12, 10),
        ],
    ],

    'hr_leave_liability' => [
        'name' => 'Leave Balances & Liability', 'group' => $g, 'icon' => 'tabler-calendar-dollar',
        'description' => 'What leave is owed to staff right now and what it would cost to pay out, by department and person.',
        'widgets' => [
            Tpl::kpi('Leave liability', 'hr_leave_balances', ['liability'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Days owed', 'hr_leave_balances', ['balance_days'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average days per person', 'hr_leave_balances', ['avg_days'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Balances over 15 days', 'hr_leave_balances', ['high_balances', 'negative'], 9, 0, 'multi_kpi', 'all'),
            Tpl::w('bar_ranked', 'Liability by department', 'hr_leave_balances', ['measures' => ['liability'], 'dimension' => 'department', 'top' => 12, 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('donut', 'Days owed by leave type', 'hr_leave_balances', ['measures' => ['balance_days'], 'dimension' => 'leave_type', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('pareto', '80/20: who holds the liability', 'hr_leave_balances', ['measures' => ['liability'], 'dimension' => 'employee', 'top' => 20, 'period' => 'all'], 0, 13, 12, 9),
            Tpl::w('treemap', 'Days owed by department and person', 'hr_leave_balances', ['measures' => ['balance_days'], 'dimension' => 'department', 'series' => 'employee', 'top' => 8, 'period' => 'all'], 0, 22, 6, 10),
            Tpl::w('table', 'Balances by employee', 'hr_leave_balances', ['measures' => ['balance_days', 'balance_hours', 'liability'], 'dimension' => 'employee', 'period' => 'all'], 6, 22, 6, 10),
        ],
    ],

    'hr_sick_leave' => [
        'name' => 'Sick Leave & Absence Patterns', 'group' => $g, 'icon' => 'tabler-stethoscope',
        'description' => 'Sick and unpaid leave, the days of the week it starts on, and the people and teams with the most.',
        'widgets' => [
            Tpl::kpi('Sick days', 'hr_leave', ['sick_days', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Unpaid leave days', 'hr_leave', ['unpaid_days'], 3),
            Tpl::kpi('People who took leave', 'hr_leave', ['people'], 6),
            Tpl::kpi('Days per person on leave', 'hr_leave', ['days_per_person'], 9),
            Tpl::w('column_clustered', 'Sick days by start weekday', 'hr_leave', ['measures' => ['sick_days'], 'dimension' => 'weekday', 'sort' => 'chrono'], 0, 4, 6, 8),
            Tpl::w('line', 'Sick days by month', 'hr_leave', ['measures' => ['sick_days', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 4, 6, 8),
            Tpl::w('bar_ranked', 'Most sick days by employee', 'hr_leave', ['measures' => ['sick_days'], 'dimension' => 'employee', 'top' => 10], 0, 12, 6, 9),
            Tpl::w('bar_ranked', 'Sick days by department', 'hr_leave', ['measures' => ['sick_days'], 'dimension' => 'department', 'top' => 10], 6, 12, 6, 9),
            Tpl::w('donut', 'Length of absences', 'hr_leave', ['measures' => ['applications'], 'dimension' => 'length', 'sort' => 'chrono'], 0, 21, 6, 8),
            Tpl::w('donut', 'Paid vs unpaid leave days', 'hr_leave', ['measures' => ['days'], 'dimension' => 'paid'], 6, 21, 6, 8),
        ],
    ],

    // ── Time & attendance ───────────────────────────────────────────────────────────────────────
    'hr_attendance' => [
        'name' => 'Time & Attendance', 'group' => $g, 'icon' => 'tabler-clock-check',
        'description' => 'Attendance, absenteeism and lateness by month, department and person.',
        'widgets' => [
            Tpl::kpi('Attendance rate', 'hr_attendance', ['attendance_rate'], 0, 0, 'gauge'),
            Tpl::kpi('Absenteeism', 'hr_attendance', ['absenteeism_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Late arrivals', 'hr_attendance', ['late', 'growth_pct'], 6, 0, 'kpi_sparkline'),
            Tpl::kpi('Hours worked', 'hr_attendance', ['hours_worked', 'growth_pct'], 9, 0, 'kpi_sparkline'),
            Tpl::w('area_stacked', 'Attendance days by status', 'hr_attendance', ['measures' => ['records'], 'series' => 'status', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Days by attendance status', 'hr_attendance', ['measures' => ['records'], 'dimension' => 'status'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Absenteeism by department', 'hr_attendance', ['measures' => ['absenteeism_rate'], 'dimension' => 'department', 'top' => 12], 0, 13, 6, 9),
            Tpl::w('bar_ranked', 'Days absent by employee', 'hr_attendance', ['measures' => ['absent'], 'dimension' => 'employee', 'top' => 12], 6, 13, 6, 9),
            Tpl::w('table', 'Attendance by department', 'hr_attendance', ['measures' => ['records', 'present', 'absent', 'late', 'attendance_rate'], 'dimension' => 'department'], 0, 22, 12, 9),
        ],
    ],

    'hr_punctuality' => [
        'name' => 'Punctuality & Clocking Compliance', 'group' => $g, 'icon' => 'tabler-alarm',
        'description' => 'Late arrivals, early departures, missing clock-outs and records still waiting for a manager.',
        'widgets' => [
            Tpl::kpi('Late arrivals %', 'hr_attendance', ['late_rate'], 0, 0, 'gauge'),
            Tpl::kpi('Minutes late', 'hr_attendance', ['late_minutes', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Missing clock-outs', 'hr_attendance', ['missing_clock_out'], 6),
            Tpl::kpi('Awaiting manager approval', 'hr_attendance', ['unapproved'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('heatmap_matrix', 'Late arrivals: employee by weekday', 'hr_attendance', ['measures' => ['late'], 'dimension' => 'employee', 'series' => 'weekday', 'top' => 15], 0, 4, 8, 10),
            Tpl::w('donut', 'How people clock in', 'hr_attendance', ['measures' => ['records'], 'dimension' => 'source'], 8, 4, 4, 10),
            Tpl::w('line_multi', 'Late arrivals and early departures by week', 'hr_attendance', ['measures' => ['late', 'early_departures'], 'grain' => 'week', 'period' => 'last_12_months'], 0, 14, 12, 8),
            Tpl::w('bar_ranked', 'Most minutes late', 'hr_attendance', ['measures' => ['late_minutes'], 'dimension' => 'employee', 'top' => 10], 0, 22, 6, 9),
            Tpl::w('bar_ranked', 'Late arrivals by shift', 'hr_attendance', ['measures' => ['late'], 'dimension' => 'shift', 'top' => 10], 6, 22, 6, 9),
        ],
    ],

    'hr_overtime' => [
        'name' => 'Overtime', 'group' => $g, 'icon' => 'tabler-clock-plus',
        'description' => 'Overtime hours by month, department and person — where it concentrates and whether it is growing.',
        'widgets' => [
            Tpl::kpi('Overtime hours', 'hr_attendance', ['overtime_hours', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Overtime per employee', 'hr_attendance', ['overtime_per_person'], 3),
            Tpl::kpi('Hours worked', 'hr_attendance', ['hours_worked'], 6),
            Tpl::kpi('Average hours per day', 'hr_attendance', ['avg_hours'], 9),
            Tpl::w('combo_column_line', 'Overtime vs last year', 'hr_attendance', ['measures' => ['overtime_hours', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 12, 8),
            Tpl::w('pareto', '80/20: overtime by employee', 'hr_attendance', ['measures' => ['overtime_hours'], 'dimension' => 'employee', 'top' => 20], 0, 12, 8, 9),
            Tpl::w('donut', 'Overtime by shift', 'hr_attendance', ['measures' => ['overtime_hours'], 'dimension' => 'shift'], 8, 12, 4, 9),
            Tpl::w('heatmap_matrix', 'Overtime by department and month', 'hr_attendance', ['measures' => ['overtime_hours'], 'dimension' => 'department', 'series' => 'month', 'period' => 'last_12_months', 'top' => 12], 0, 21, 12, 9),
        ],
    ],

    // ── Payroll ─────────────────────────────────────────────────────────────────────────────────
    'hr_payroll_cost' => [
        'name' => 'Payroll Cost', 'group' => $g, 'icon' => 'tabler-cash',
        'description' => 'Gross and net pay month by month, against last year, and what moved it.',
        'widgets' => [
            Tpl::kpi('Gross pay', 'hr_payslips', ['gross', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Net pay', 'hr_payslips', ['net', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Employees paid', 'hr_payslips', ['employees'], 6),
            Tpl::kpi('Average gross per payslip', 'hr_payslips', ['avg_gross'], 9),
            Tpl::w('combo_column_line', 'Gross pay by month vs last year', 'hr_payslips', ['measures' => ['gross', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Gross pay by department', 'hr_payslips', ['measures' => ['gross'], 'dimension' => 'department', 'top' => 6, 'other' => true], 8, 4, 4, 9),
            Tpl::w('line_multi', 'Gross, deductions and net', 'hr_payslips', ['measures' => ['gross', 'deductions', 'net'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('waterfall', 'What moved payroll vs last year', 'hr_payslips', ['measures' => ['gross'], 'dimension' => 'department', 'top' => 8], 6, 13, 6, 8),
            Tpl::w('running_total', 'Cumulative payroll this year', 'hr_payslips', ['measures' => ['gross'], 'grain' => 'month'], 0, 21, 12, 7),
            Tpl::w('smart_narrative', 'In short', 'hr_payslips', ['measures' => ['gross', 'net', 'paye'], 'dimension' => 'department'], 0, 28, 12, 5),
        ],
    ],

    'hr_payroll_department' => [
        'name' => 'Payroll by Department & Role', 'group' => $g, 'icon' => 'tabler-building',
        'description' => 'Which departments, positions and people the payroll goes to.',
        'widgets' => [
            Tpl::kpi('Gross pay', 'hr_payslips', ['gross'], 0),
            Tpl::kpi('Basic salary', 'hr_payslips', ['basic'], 3),
            Tpl::kpi('Earnings above basic', 'hr_payslips', ['variable'], 6),
            Tpl::kpi('Payslips', 'hr_payslips', ['payslips'], 9),
            Tpl::w('column_stacked', 'Gross pay by month and department', 'hr_payslips', ['measures' => ['gross'], 'series' => 'department', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 12, 9),
            Tpl::w('treemap', 'Gross pay by department and employee', 'hr_payslips', ['measures' => ['gross'], 'dimension' => 'department', 'series' => 'employee', 'top' => 8], 0, 13, 6, 10),
            Tpl::w('bar_ranked', 'Average gross by position', 'hr_payslips', ['measures' => ['avg_gross'], 'dimension' => 'position', 'top' => 12], 6, 13, 6, 10),
            Tpl::w('table', 'Department payroll', 'hr_payslips', ['measures' => ['gross', 'net', 'paye', 'uif', 'pension', 'employees'], 'dimension' => 'department'], 0, 23, 12, 9),
        ],
    ],

    'hr_payroll_statutory' => [
        'name' => 'PAYE, UIF & ETI', 'group' => $g, 'icon' => 'tabler-receipt-tax',
        'description' => 'Statutory deductions and Employment Tax Incentive, by month and by payroll run.',
        'widgets' => [
            Tpl::kpi('PAYE', 'hr_payslips', ['paye', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('UIF', 'hr_payslips', ['uif', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('ETI', 'hr_payslips', ['eti'], 6),
            Tpl::kpi('PAYE % of gross', 'hr_payslips', ['tax_rate'], 9, 0, 'gauge'),
            Tpl::w('line_multi', 'PAYE, UIF and pension by month', 'hr_payslips', ['measures' => ['paye', 'uif', 'pension'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('bar_ranked', 'PAYE by department', 'hr_payslips', ['measures' => ['paye'], 'dimension' => 'department', 'top' => 10], 8, 4, 4, 8),
            Tpl::w('column_simple', 'ETI by month', 'hr_payslips', ['measures' => ['eti'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 12, 6, 8),
            Tpl::w('donut', 'Payroll runs by status', 'hr_payroll_runs', ['measures' => ['runs'], 'dimension' => 'status', 'period' => 'last_12_months'], 6, 12, 6, 8),
            Tpl::w('table', 'Payroll runs', 'hr_payroll_runs', ['measures' => ['gross', 'deductions', 'net', 'eti', 'employees_paid'], 'dimension' => 'run', 'period' => 'last_12_months'], 0, 20, 12, 9),
        ],
    ],

    'hr_remuneration' => [
        'name' => 'Remuneration & Pay Equity', 'group' => $g, 'icon' => 'tabler-coins',
        'description' => 'The monthly package bill from people records, and how pay compares across levels, gender and race.',
        'widgets' => [
            Tpl::kpi('Monthly package bill', 'hr_salary_bill', ['package_total'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Annualised', 'hr_salary_bill', ['annual_cost'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average package', 'hr_salary_bill', ['avg_package'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Highest package', 'hr_salary_bill', ['max_package'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_ranked', 'Average package by occupational level', 'hr_salary_bill', ['measures' => ['avg_package'], 'dimension' => 'occupational_level', 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('column_clustered', 'Average package by gender', 'hr_salary_bill', ['measures' => ['avg_package'], 'dimension' => 'gender', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('heatmap_matrix', 'Package bill: level by gender', 'hr_salary_bill', ['measures' => ['package_total'], 'dimension' => 'occupational_level', 'series' => 'gender', 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('treemap', 'Package bill by department and position', 'hr_salary_bill', ['measures' => ['package_total'], 'dimension' => 'department', 'series' => 'position', 'top' => 8, 'period' => 'all'], 6, 13, 6, 9),
            Tpl::w('table', 'Package bill by department', 'hr_salary_bill', ['measures' => ['package_total', 'headcount', 'avg_package', 'max_package', 'medical_aid'], 'dimension' => 'department', 'period' => 'all'], 0, 22, 12, 9),
        ],
    ],

    // ── Recruitment & onboarding ────────────────────────────────────────────────────────────────
    'hr_recruitment' => [
        'name' => 'Recruitment Pipeline', 'group' => $g, 'icon' => 'tabler-user-search',
        'description' => 'Applications coming in, where they are in the pipeline, and which jobs and sources bring them.',
        'widgets' => [
            Tpl::kpi('Applications', 'hr_recruitment', ['applications', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('In the pipeline', 'hr_recruitment', ['in_progress'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Hired', 'hr_recruitment', ['hired'], 6),
            Tpl::kpi('Hire rate', 'hr_recruitment', ['hire_rate'], 9, 0, 'gauge'),
            Tpl::w('funnel', 'Where applications are now', 'hr_recruitment', ['measures' => ['applications'], 'dimension' => 'funnel_stage', 'sort' => 'chrono'], 0, 4, 6, 9),
            Tpl::w('donut', 'Applications by source', 'hr_recruitment', ['measures' => ['applications'], 'dimension' => 'source'], 6, 4, 6, 9),
            Tpl::w('column_stacked', 'Applications by month and status', 'hr_recruitment', ['measures' => ['applications'], 'series' => 'status', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 12, 9),
            Tpl::w('bar_ranked', 'Applications by job', 'hr_recruitment', ['measures' => ['applications'], 'dimension' => 'job', 'top' => 12], 0, 22, 6, 9),
            Tpl::w('table', 'Pipeline by job', 'hr_recruitment', ['measures' => ['applications', 'interviewed', 'offers', 'hired', 'rejected'], 'dimension' => 'job'], 6, 22, 6, 9),
        ],
    ],

    'hr_time_to_hire' => [
        'name' => 'Time to Hire & Sourcing', 'group' => $g, 'icon' => 'tabler-hourglass',
        'description' => 'How long hiring takes, how many offers are accepted, and which sources produce hires.',
        'widgets' => [
            Tpl::kpi('Average days to hire', 'hr_recruitment', ['days_to_hire'], 0),
            Tpl::kpi('Offer acceptance', 'hr_recruitment', ['offer_acceptance'], 3, 0, 'gauge'),
            Tpl::kpi('Interview rate', 'hr_recruitment', ['interview_rate'], 6, 0, 'progress'),
            Tpl::kpi('Candidates', 'hr_recruitment', ['candidates', 'growth_pct'], 9, 0, 'kpi_sparkline'),
            Tpl::w('bar_ranked', 'Days to hire by job', 'hr_recruitment', ['measures' => ['days_to_hire'], 'dimension' => 'job', 'top' => 12], 0, 4, 6, 9),
            Tpl::w('column_clustered', 'Applications and hires by source', 'hr_recruitment', ['measures' => ['applications', 'hired'], 'dimension' => 'source'], 6, 4, 6, 9),
            Tpl::w('line_multi', 'Hires and offers by month', 'hr_recruitment', ['measures' => ['hired', 'offers'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Candidates by gender', 'hr_recruitment', ['measures' => ['candidates'], 'dimension' => 'candidate_gender'], 8, 13, 4, 8),
            Tpl::w('bar_ranked', 'Hire rate by source', 'hr_recruitment', ['measures' => ['hire_rate'], 'dimension' => 'source'], 0, 21, 6, 8),
            Tpl::w('bar_ranked', 'Days to hire by employment type', 'hr_recruitment', ['measures' => ['days_to_hire'], 'dimension' => 'employment_type'], 6, 21, 6, 8),
        ],
    ],

    'hr_vacancies' => [
        'name' => 'Vacancies', 'group' => $g, 'icon' => 'tabler-briefcase',
        'description' => 'Open and closed vacancies, how many applications each attracts, and how many get filled.',
        'widgets' => [
            Tpl::kpi('Vacancies posted', 'hr_vacancies', ['jobs'], 0),
            Tpl::kpi('Open now', 'hr_vacancies', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Fill rate', 'hr_vacancies', ['fill_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Applications per vacancy', 'hr_vacancies', ['apps_per_job'], 9),
            Tpl::w('bar_ranked', 'Applications by vacancy', 'hr_vacancies', ['measures' => ['applications'], 'dimension' => 'job', 'top' => 12, 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Vacancies by employment type', 'hr_vacancies', ['measures' => ['jobs'], 'dimension' => 'employment_type', 'period' => 'last_12_months'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Vacancies by department', 'hr_vacancies', ['measures' => ['jobs'], 'dimension' => 'department', 'top' => 10, 'period' => 'last_12_months'], 0, 13, 6, 8),
            Tpl::w('column_simple', 'Vacancies posted by month', 'hr_vacancies', ['measures' => ['jobs'], 'grain' => 'month', 'period' => 'last_12_months'], 6, 13, 6, 8),
            Tpl::w('table', 'Vacancy overview', 'hr_vacancies', ['measures' => ['jobs', 'open', 'applications', 'filled', 'avg_salary'], 'dimension' => 'location', 'period' => 'last_12_months'], 0, 21, 12, 9),
        ],
    ],

    'hr_onboarding' => [
        'name' => 'Onboarding Progress', 'group' => $g, 'icon' => 'tabler-user-plus',
        'description' => 'New starters being onboarded, how far along their plans are and which tasks are overdue.',
        'widgets' => [
            Tpl::kpi('New starters onboarding', 'hr_onboarding', ['plans'], 0),
            Tpl::kpi('Still in progress', 'hr_onboarding', ['active'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Task progress', 'hr_onboarding', ['task_progress'], 6, 0, 'gauge'),
            Tpl::kpi('Overdue tasks', 'hr_onboarding_tasks', ['overdue'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('donut', 'Plans by status', 'hr_onboarding', ['measures' => ['plans'], 'dimension' => 'status'], 0, 4, 4, 8),
            Tpl::w('column_clustered', 'Tasks done vs open by type', 'hr_onboarding_tasks', ['measures' => ['done', 'open'], 'dimension' => 'type'], 4, 4, 8, 8),
            Tpl::w('bar_ranked', 'New starters by department', 'hr_onboarding', ['measures' => ['plans'], 'dimension' => 'department', 'top' => 10], 0, 12, 6, 8),
            Tpl::w('bar_ranked', 'Overdue tasks by type', 'hr_onboarding_tasks', ['measures' => ['overdue'], 'dimension' => 'type', 'period' => 'all'], 6, 12, 6, 8),
            Tpl::w('table', 'New starters', 'hr_onboarding', ['measures' => ['tasks', 'tasks_done', 'task_progress', 'overdue'], 'dimension' => 'employee'], 0, 20, 12, 9),
        ],
    ],

    // ── Performance, learning & recognition ─────────────────────────────────────────────────────
    'hr_performance' => [
        'name' => 'Performance Ratings', 'group' => $g, 'icon' => 'tabler-star',
        'description' => 'How ratings are spread, how departments compare, and how self, manager and 360 ratings line up.',
        'widgets' => [
            Tpl::kpi('Appraisals', 'hr_performance', ['appraisals'], 0),
            Tpl::kpi('Average rating', 'hr_performance', ['avg_score'], 3, 0, 'gauge'),
            Tpl::kpi('High performers', 'hr_performance', ['high_pct'], 6, 0, 'progress'),
            Tpl::kpi('Appraisals approved', 'hr_performance', ['approved_pct'], 9, 0, 'progress'),
            Tpl::w('column_clustered', 'Rating distribution', 'hr_performance', ['measures' => ['appraisals'], 'dimension' => 'rating_band', 'sort' => 'chrono'], 0, 4, 6, 9),
            Tpl::w('bar_ranked', 'Average rating by department', 'hr_performance', ['measures' => ['avg_score'], 'dimension' => 'department', 'top' => 12], 6, 4, 6, 9),
            Tpl::w('heatmap_matrix', 'Department by rating band', 'hr_performance', ['measures' => ['appraisals'], 'dimension' => 'department', 'series' => 'rating_band', 'top' => 12], 0, 13, 12, 9),
            Tpl::w('column_clustered', 'Self vs manager vs 360 by department', 'hr_performance', ['measures' => ['avg_self', 'avg_manager', 'avg_360'], 'dimension' => 'department'], 0, 22, 8, 9),
            Tpl::w('donut', 'Appraisals by status', 'hr_performance', ['measures' => ['appraisals'], 'dimension' => 'status'], 8, 22, 4, 9),
            Tpl::w('table', 'Ratings by employee', 'hr_performance', ['measures' => ['avg_score', 'avg_self', 'avg_manager', 'kpi_completion', 'appraisals'], 'dimension' => 'employee'], 0, 31, 12, 10),
        ],
    ],

    'hr_training' => [
        'name' => 'Learning & Development', 'group' => $g, 'icon' => 'tabler-school',
        'description' => 'Enrolments, completions, pass rates and learning hours by course, category and department.',
        'widgets' => [
            Tpl::kpi('Enrolments', 'hr_training', ['enrolments', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Completion rate', 'hr_training', ['completion_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Pass rate', 'hr_training', ['pass_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Learning hours', 'hr_training', ['hours'], 9),
            Tpl::w('bar_ranked', 'Enrolments by course', 'hr_training', ['measures' => ['enrolments'], 'dimension' => 'course', 'top' => 12], 0, 4, 6, 9),
            Tpl::w('bar_stacked', 'Course outcomes', 'hr_training', ['measures' => ['enrolments'], 'dimension' => 'course', 'series' => 'status', 'top' => 10], 6, 4, 6, 9),
            Tpl::w('line_multi', 'Enrolments and completions by month', 'hr_training', ['measures' => ['enrolments', 'completed'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Enrolments by category', 'hr_training', ['measures' => ['enrolments'], 'dimension' => 'category', 'top' => 6, 'other' => true], 8, 13, 4, 8),
            Tpl::w('table', 'Training by department', 'hr_training', ['measures' => ['enrolments', 'completed', 'failed', 'overdue', 'hours', 'completion_rate'], 'dimension' => 'department'], 0, 21, 12, 9),
        ],
    ],

    'hr_training_compliance' => [
        'name' => 'Training Compliance', 'group' => $g, 'icon' => 'tabler-certificate',
        'description' => 'Mandatory training completed, overdue and waived, plus certificates about to expire.',
        'widgets' => [
            Tpl::kpi('Compliance', 'hr_compliance_training', ['compliance_rate'], 0, 0, 'gauge', 'all'),
            Tpl::kpi('Overdue', 'hr_compliance_training', ['overdue'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Certificates expiring in 90 days', 'hr_certificates', ['expiring'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Certificates valid', 'hr_certificates', ['valid_pct'], 9, 0, 'progress', 'all'),
            Tpl::w('bar_ranked', 'Overdue by department', 'hr_compliance_training', ['measures' => ['overdue'], 'dimension' => 'department', 'top' => 12, 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('bar_ranked', 'Compliance by course', 'hr_compliance_training', ['measures' => ['compliance_rate'], 'dimension' => 'course', 'top' => 12, 'sort' => 'asc', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('donut', 'Assignments by status', 'hr_compliance_training', ['measures' => ['assigned'], 'dimension' => 'status', 'period' => 'all'], 0, 13, 4, 8),
            Tpl::w('donut', 'Certificate validity', 'hr_certificates', ['measures' => ['issued'], 'dimension' => 'validity', 'period' => 'all'], 4, 13, 4, 8),
            Tpl::w('bar_ranked', 'Certificates by course', 'hr_certificates', ['measures' => ['issued'], 'dimension' => 'course', 'top' => 8, 'period' => 'all'], 8, 13, 4, 8),
            Tpl::w('table', 'Who is behind', 'hr_compliance_training', ['measures' => ['overdue', 'pending', 'completed', 'compliance_rate'], 'dimension' => 'employee', 'period' => 'all'], 0, 21, 12, 10),
        ],
    ],


    // ── Employee relations & risk ───────────────────────────────────────────────────────────────
    'hr_disciplinary' => [
        'name' => 'Disciplinary Cases & Warnings', 'group' => $g, 'icon' => 'tabler-gavel',
        'description' => 'Cases opened and closed, offence categories, outcomes, and warnings still on file.',
        'widgets' => [
            Tpl::kpi('Cases this year', 'hr_disciplinary', ['cases', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Open cases', 'hr_disciplinary', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average days to close', 'hr_disciplinary', ['days_to_close'], 6),
            Tpl::kpi('Active warnings', 'hr_warnings', ['active'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_ranked', 'Cases by offence category', 'hr_disciplinary', ['measures' => ['cases'], 'dimension' => 'category', 'top' => 10], 0, 4, 6, 9),
            Tpl::w('donut', 'Outcomes', 'hr_disciplinary', ['measures' => ['cases'], 'dimension' => 'outcome'], 6, 4, 6, 9),
            Tpl::w('line_multi', 'Cases opened by month', 'hr_disciplinary', ['measures' => ['cases', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Warnings by type', 'hr_warnings', ['measures' => ['warnings'], 'dimension' => 'warning_type', 'period' => 'last_12_months'], 8, 13, 4, 8),
            Tpl::w('bar_stacked', 'Cases by department and status', 'hr_disciplinary', ['measures' => ['cases'], 'dimension' => 'department', 'series' => 'status', 'top' => 10], 0, 21, 6, 9),
            Tpl::w('table', 'Open cases by department', 'hr_disciplinary', ['measures' => ['open', 'cases', 'open_age', 'hearings'], 'dimension' => 'department', 'period' => 'all'], 6, 21, 6, 9),
        ],
    ],


    // ── Claims & staff loans ────────────────────────────────────────────────────────────────────
    'hr_claims' => [
        'name' => 'Claims & Expenses', 'group' => $g, 'icon' => 'tabler-receipt-2',
        'description' => 'Employee expense claims: what is claimed, approved and paid, by category and person, and risky claims.',
        'widgets' => [
            Tpl::kpi('Claimed this year', 'hr_claims', ['claimed', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Awaiting approval', 'hr_claims', ['pending_amount'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Approved, not yet paid', 'hr_claims', ['awaiting_payment'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('High-risk claims', 'hr_claims', ['high_risk'], 9),
            Tpl::w('combo_column_line', 'Claimed vs paid by month', 'hr_claims', ['measures' => ['claimed', 'paid'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Claims by category', 'hr_claims', ['measures' => ['claimed'], 'dimension' => 'category', 'top' => 6, 'other' => true], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Top claimants', 'hr_claims', ['measures' => ['claimed'], 'dimension' => 'employee', 'top' => 10], 0, 13, 6, 9),
            Tpl::w('bar_ranked', 'Claims by department', 'hr_claims', ['measures' => ['claimed'], 'dimension' => 'department', 'top' => 10], 6, 13, 6, 9),
            Tpl::w('donut', 'Claims by status', 'hr_claims', ['measures' => ['claims'], 'dimension' => 'status'], 0, 22, 4, 8),
            Tpl::w('donut', 'Claims by risk level', 'hr_claims', ['measures' => ['claims'], 'dimension' => 'risk_level'], 4, 22, 4, 8),
            Tpl::w('table', 'Claims by category', 'hr_claims', ['measures' => ['claimed', 'approved_amount', 'paid', 'claims', 'avg_claim'], 'dimension' => 'category'], 8, 22, 4, 8),
        ],
    ],

    'hr_staff_loans' => [
        'name' => 'Staff Loans & Advances', 'group' => $g, 'icon' => 'tabler-building-bank',
        'description' => 'The staff loan book: what is outstanding, in arrears or default, repayments collected, and salary advances.',
        'widgets' => [
            Tpl::kpi('Loan book outstanding', 'hr_staff_loans', ['outstanding'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('In default', 'hr_staff_loans', ['default_rate'], 3, 0, 'gauge', 'all'),
            Tpl::kpi('Repayments collected', 'hr_loan_repayments', ['collection_rate'], 6, 0, 'progress'),
            Tpl::kpi('Advances not yet recovered', 'hr_salary_advances', ['outstanding'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('combo_column_line', 'Instalments due vs collected', 'hr_loan_repayments', ['measures' => ['due', 'collected'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Outstanding by loan type', 'hr_staff_loans', ['measures' => ['outstanding'], 'dimension' => 'loan_type', 'period' => 'all'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Largest outstanding balances', 'hr_staff_loans', ['measures' => ['outstanding'], 'dimension' => 'employee', 'top' => 10, 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('bar_ranked', 'Arrears by borrower', 'hr_loan_repayments', ['measures' => ['arrears'], 'dimension' => 'employee', 'top' => 10, 'period' => 'all'], 6, 13, 6, 9),
            Tpl::w('donut', 'Loans by status', 'hr_staff_loans', ['measures' => ['loans'], 'dimension' => 'status', 'period' => 'all'], 0, 22, 4, 8),
            Tpl::w('donut', 'Loans by risk level', 'hr_staff_loans', ['measures' => ['loans'], 'dimension' => 'risk_level', 'period' => 'all'], 4, 22, 4, 8),
            Tpl::w('line_multi', 'Salary advances by month', 'hr_salary_advances', ['measures' => ['requested', 'approved_amount'], 'grain' => 'month', 'period' => 'last_12_months'], 8, 22, 4, 8),
        ],
    ],
];
