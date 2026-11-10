<?php

// Human Resources — people, leave, time & attendance, payroll, recruitment, on/offboarding,
// performance, learning, discipline, reputation, claims, staff loans and recognition.
// See DatasetRegistry for the format. No named functions: this file may be required twice.
//
// How people are linked:
//   hr_people.id      ← leave_applications.hr_id, attendance_records.employee_id, on/offboarding_plans.hr_person_id,
//                       lms_certificates.hr_person_id, employee_reputation_profiles.hr_person_id
//   hr_people.user_id ← payslips, claims, staff loans, salary advances, disciplinary, LMS enrolments,
//                       performance snapshots and recognitions (all keyed on users.id)
// A department is hr_people.division_level_1, falling back to the person's company-unit pivot.
// Unix-time columns (hr_people.date_joined/date_left/date_of_birth, leave_applications.start_date) are converted.

// Joins hr_people (alias $h) to its department (alias "{$h}_dep").
$deptJoin = fn (string $h) => "left join (select user_id, min(company_id) as unit_id from company_unit_user_pivots group by user_id) {$h}_cu on {$h}_cu.user_id = {$h}.user_id"
    . " left join company_units {$h}_dep on {$h}_dep.id = coalesce({$h}.division_level_1::bigint, {$h}_cu.unit_id)";

// Common "who" dimensions for any dataset joined to hr_people as $h.
$personDims = fn (string $h, string $label = 'Employee') => [
    'department' => ['label' => 'Department', 'sql' => "coalesce({$h}_dep.name, 'Unassigned')"],
    'employee' => ['label' => $label, 'sql' => "coalesce(nullif(trim(coalesce({$h}.first_name, '') || ' ' || coalesce({$h}.surname, '')), ''), 'Unknown')"],
    'gender' => ['label' => 'Gender', 'sql' => "case {$h}.gender when 1 then 'Male' when 2 then 'Female' else 'Not captured' end"],
    'occupational_level' => ['label' => 'Occupational level', 'sql' => "case {$h}.occupational_level when 1 then 'Senior management' when 2 then 'Middle management' when 3 then 'Junior management' when 4 then 'Semi-skilled' when 5 then 'Unskilled' when 6 then 'Professional' when 7 then 'Skilled' else 'Not captured' end"],
];

$joined = 'coalesce(to_timestamp(h.date_joined)::date, h.created_at::date)';
$ageYears = 'extract(year from age(current_date, to_timestamp(h.date_of_birth)::date))';
$tenureYears = "extract(epoch from age(coalesce(to_timestamp(h.date_left)::date, current_date), {$joined})) / 31557600.0";

$peopleDims = $personDims('h') + [
    'race' => ['label' => 'Race', 'sql' => "coalesce(eth.value, 'Not captured')"],
    'position' => ['label' => 'Position', 'sql' => "coalesce(pos.name, 'Unassigned')"],
    'employment_category' => ['label' => 'Employment category', 'sql' => "coalesce(initcap(replace(nullif(h.employment_category, ''), '_', ' ')), 'Not captured')"],
    'status' => ['label' => 'Status', 'sql' => "case when h.date_left is not null or h.status <> 1 then 'Left / inactive' else 'Active' end"],
    'age_band' => ['label' => 'Age band', 'sql' => "case when h.date_of_birth is null then 'Not captured' when {$ageYears} < 25 then 'Under 25' when {$ageYears} < 35 then '25–34' when {$ageYears} < 45 then '35–44' when {$ageYears} < 55 then '45–54' else '55+' end"],
    'tenure_band' => ['label' => 'Length of service', 'sql' => "case when {$tenureYears} < 1 then 'Under 1 year' when {$tenureYears} < 3 then '1–3 years' when {$tenureYears} < 5 then '3–5 years' when {$tenureYears} < 10 then '5–10 years' else '10+ years' end"],
    'disability' => ['label' => 'Disability', 'sql' => "case when coalesce(h.disabled, 0) = 1 then 'With disability' else 'No disability declared' end"],
    'city' => ['label' => 'City', 'sql' => "coalesce(nullif(initcap(trim(h.res_city)), ''), 'Unknown')"],
    'province' => ['label' => 'Province', 'sql' => "coalesce(prov.name, 'Unknown')"],
];

$peopleFrom = 'hr_people h ' . $deptJoin('h')
    . ' left join ethnicities eth on eth.id = h.ethnicity left join hr_positions pos on pos.id = h.position_id left join provinces prov on prov.id = h.res_province_id';

$active = 'h.status = 1 and h.date_left is null';

return [

    // ── People (core records) ───────────────────────────────────────────────────────────────────
    // Filtered by join date: period "all" = everyone on record; "ytd" = people who joined this year.
    // Dates before 2000 are lifted to 2000-01-01 so long-serving staff still count under "all".
    'hr_people' => [
        'module' => 'boarding',
        'label' => 'People & headcount',
        'from' => $peopleFrom,
        'company' => 'h.company_id',
        'date' => "greatest({$joined}, date '2000-01-01')",
        'measures' => [
            'headcount' => ['label' => 'Active headcount', 'sql' => "count(*) filter (where {$active})", 'format' => 'number'],
            'people' => ['label' => 'People on record', 'sql' => 'count(*)', 'format' => 'number'],
            'joiners' => ['label' => 'Joined in period', 'sql' => 'count(*)', 'format' => 'number'],
            'leavers' => ['label' => 'Left', 'sql' => 'count(*) filter (where h.date_left is not null)', 'format' => 'number'],
            'female' => ['label' => 'Women (active)', 'sql' => "count(*) filter (where {$active} and h.gender = 2)", 'format' => 'number'],
            'male' => ['label' => 'Men (active)', 'sql' => "count(*) filter (where {$active} and h.gender = 1)", 'format' => 'number'],
            'disabled' => ['label' => 'People with disability (active)', 'sql' => "count(*) filter (where {$active} and coalesce(h.disabled, 0) = 1)", 'format' => 'number'],
            'female_pct' => ['label' => 'Women %', 'ratio' => ['female', 'headcount'], 'scale' => 100, 'format' => 'percent'],
            'disabled_pct' => ['label' => 'Disability %', 'ratio' => ['disabled', 'headcount'], 'scale' => 100, 'format' => 'percent'],
            'avg_age' => ['label' => 'Average age', 'sql' => "coalesce(avg({$ageYears}) filter (where {$active} and h.date_of_birth is not null), 0)", 'format' => 'number'],
            'avg_tenure' => ['label' => 'Average years of service', 'sql' => "coalesce(avg({$tenureYears}) filter (where {$active}), 0)", 'format' => 'number'],
            'with_manager' => ['label' => 'Active with a manager', 'sql' => "count(*) filter (where {$active} and h.manager_id is not null)", 'format' => 'number'],
        ],
        'dimensions' => $peopleDims,
    ],

    // Exits, dated by the day the person left.
    'hr_leavers' => [
        'module' => 'offboarding',
        'label' => 'Leavers (staff turnover)',
        'from' => $peopleFrom,
        'company' => 'h.company_id',
        'date' => 'to_timestamp(h.date_left)::date',
        'where' => 'h.date_left is not null',
        'measures' => [
            'leavers' => ['label' => 'Leavers', 'sql' => 'count(*)', 'format' => 'number'],
            'avg_tenure' => ['label' => 'Average years of service at exit', 'sql' => "coalesce(avg({$tenureYears}), 0)", 'format' => 'number'],
            'early_exits' => ['label' => 'Left within first year', 'sql' => "count(*) filter (where {$tenureYears} < 1)", 'format' => 'number'],
            'early_exit_pct' => ['label' => 'First-year exits %', 'ratio' => ['early_exits', 'leavers'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $peopleDims,
    ],

    // The monthly salary bill as captured on the people record (cost-to-company package).
    'hr_salary_bill' => [
        'module' => 'payroll',
        'label' => 'Salary bill (people packages)',
        'from' => $peopleFrom,
        'company' => 'h.company_id',
        'date' => "greatest({$joined}, date '2000-01-01')",
        'where' => $active,
        'measures' => [
            'package_total' => ['label' => 'Monthly package bill', 'sql' => 'coalesce(sum(h.monthly_package), 0)', 'format' => 'currency'],
            'annual_cost' => ['label' => 'Annualised package bill', 'sql' => 'coalesce(sum(h.monthly_package) * 12, 0)', 'format' => 'currency'],
            'headcount' => ['label' => 'Active headcount', 'sql' => 'count(*)', 'format' => 'number'],
            'paid_people' => ['label' => 'People with a package', 'sql' => 'count(*) filter (where coalesce(h.monthly_package, 0) > 0)', 'format' => 'number'],
            'avg_package' => ['label' => 'Average monthly package', 'ratio' => ['package_total', 'paid_people'], 'format' => 'currency'],
            'max_package' => ['label' => 'Highest monthly package', 'sql' => 'coalesce(max(h.monthly_package), 0)', 'format' => 'currency'],
            'medical_aid' => ['label' => 'Medical aid contributions', 'sql' => 'coalesce(sum(h.package_med_aid), 0)', 'format' => 'currency'],
        ],
        'dimensions' => $peopleDims,
    ],

    // ── Leave ───────────────────────────────────────────────────────────────────────────────────
    // leave_applications.status: 0 pending, 1 approved, 2 rejected, 3 cancelled.
    'hr_leave' => [
        'module' => 'leave_management',
        'label' => 'Leave applications',
        'from' => 'leave_applications la join hr_people h on h.id = la.hr_id ' . $deptJoin('h') . ' left join leave_types lt on lt.id = la.leave_type_id',
        'company' => 'la.company_id',
        'date' => 'to_timestamp(la.start_date)::date',
        'where' => 'la.deleted_at is null',
        'measures' => [
            'applications' => ['label' => 'Applications', 'sql' => 'count(*)', 'format' => 'number'],
            'approved' => ['label' => 'Approved', 'sql' => 'count(*) filter (where la.status = 1)', 'format' => 'number'],
            'pending' => ['label' => 'Awaiting approval', 'sql' => 'count(*) filter (where la.status = 0)', 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => 'count(*) filter (where la.status = 2)', 'format' => 'number'],
            'cancelled' => ['label' => 'Cancelled', 'sql' => 'count(*) filter (where la.status = 3)', 'format' => 'number'],
            'days' => ['label' => 'Leave days (approved)', 'sql' => 'coalesce(sum(la.leave_days) filter (where la.status = 1), 0)', 'format' => 'number'],
            'hours' => ['label' => 'Leave hours (approved)', 'sql' => 'coalesce(sum(la.leave_hours) filter (where la.status = 1), 0)', 'format' => 'number'],
            'days_requested' => ['label' => 'Leave days requested', 'sql' => 'coalesce(sum(la.leave_days), 0)', 'format' => 'number'],
            'sick_days' => ['label' => 'Sick days (approved)', 'sql' => "coalesce(sum(la.leave_days) filter (where la.status = 1 and lt.name ilike '%sick%'), 0)", 'format' => 'number'],
            'annual_days' => ['label' => 'Annual leave days (approved)', 'sql' => "coalesce(sum(la.leave_days) filter (where la.status = 1 and lt.name ilike '%annual%'), 0)", 'format' => 'number'],
            'unpaid_days' => ['label' => 'Unpaid leave days (approved)', 'sql' => 'coalesce(sum(la.leave_days) filter (where la.status = 1 and lt.is_paid is false), 0)', 'format' => 'number'],
            'people' => ['label' => 'People who took leave', 'sql' => 'count(distinct la.hr_id) filter (where la.status = 1)', 'format' => 'number'],
            'approval_rate' => ['label' => 'Approval rate %', 'ratio' => ['approved', 'applications'], 'scale' => 100, 'format' => 'percent'],
            'days_per_person' => ['label' => 'Days per person on leave', 'ratio' => ['days', 'people'], 'format' => 'number'],
        ],
        'dimensions' => $personDims('h') + [
            'leave_type' => ['label' => 'Leave type', 'sql' => "coalesce(lt.name, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => "case la.status when 0 then 'Pending' when 1 then 'Approved' when 2 then 'Rejected' when 3 then 'Cancelled' else 'Unknown' end"],
            'paid' => ['label' => 'Paid / unpaid', 'sql' => "case when lt.is_paid is false then 'Unpaid' else 'Paid' end"],
            'weekday' => ['label' => 'Start day', 'sql' => "to_char(to_timestamp(la.start_date), 'ID Dy')"],
            'length' => ['label' => 'Length', 'sql' => "case when coalesce(la.leave_days, 0) <= 1 then '1 day' when la.leave_days <= 3 then '2–3 days' when la.leave_days <= 5 then '4–5 days' when la.leave_days <= 10 then '6–10 days' else 'Over 10 days' end"],
        ],
    ],

    // Current balances: the latest balance transaction per person and leave type (hours).
    // Liability values hours at monthly package / 173.33 (BCEA standard hours per month), paid types only.
    'hr_leave_balances' => [
        'module' => 'leave_management',
        'label' => 'Leave balances & liability',
        'from' => '(select distinct on (employee_id, leave_type_id) * from leave_balance_transactions where deleted_at is null order by employee_id, leave_type_id, id desc) lb'
            . ' join hr_people h on h.id = lb.employee_id ' . $deptJoin('h') . ' left join leave_types lt on lt.id = lb.leave_type_id',
        'company' => 'lb.company_id',
        'date' => 'lb.created_at::date',
        'where' => 'h.status = 1 and h.date_left is null',
        'measures' => [
            'balance_hours' => ['label' => 'Balance (hours)', 'sql' => 'coalesce(sum(lb.balance_after), 0)', 'format' => 'number'],
            'balance_days' => ['label' => 'Balance (days)', 'sql' => 'coalesce(sum(lb.balance_after) / 8.0, 0)', 'format' => 'number'],
            'liability' => ['label' => 'Leave liability', 'sql' => 'coalesce(sum(greatest(lb.balance_after, 0) * coalesce(h.monthly_package, 0) / 173.33) filter (where lt.is_paid is not false), 0)', 'format' => 'currency'],
            'people' => ['label' => 'People with a balance', 'sql' => 'count(distinct lb.employee_id)', 'format' => 'number'],
            'negative' => ['label' => 'Negative balances', 'sql' => 'count(*) filter (where lb.balance_after < 0)', 'format' => 'number'],
            'high_balances' => ['label' => 'Balances over 15 days', 'sql' => 'count(*) filter (where lb.balance_after > 120)', 'format' => 'number'],
            'avg_days' => ['label' => 'Average days per person', 'ratio' => ['balance_days', 'people'], 'format' => 'number'],
        ],
        'dimensions' => $personDims('h') + [
            'leave_type' => ['label' => 'Leave type', 'sql' => "coalesce(lt.name, 'Unknown')"],
        ],
    ],

    // ── Time & attendance ───────────────────────────────────────────────────────────────────────
    'hr_attendance' => [
        'module' => 'time_attendance',
        'label' => 'Attendance records',
        'from' => 'attendance_records ar join hr_people h on h.id = ar.employee_id ' . $deptJoin('h')
            . ' left join attendance_statuses ast on ast.id = ar.status_id left join attendance_shifts ash on ash.id = ar.shift_id',
        'company' => 'ar.company_id',
        'date' => 'ar.date',
        'where' => 'ar.deleted_at is null',
        'measures' => [
            'records' => ['label' => 'Attendance days', 'sql' => 'count(*)', 'format' => 'number'],
            'present' => ['label' => 'Days present', 'sql' => 'count(*) filter (where ast.is_present)', 'format' => 'number'],
            'absent' => ['label' => 'Days absent', 'sql' => 'count(*) filter (where ast.is_absent)', 'format' => 'number'],
            'on_leave' => ['label' => 'Days on leave', 'sql' => 'count(*) filter (where ast.is_leave)', 'format' => 'number'],
            'late' => ['label' => 'Late arrivals', 'sql' => 'count(*) filter (where ar.is_late)', 'format' => 'number'],
            'late_minutes' => ['label' => 'Minutes late', 'sql' => 'coalesce(sum(ar.late_minutes), 0)', 'format' => 'number'],
            'early_departures' => ['label' => 'Early departures', 'sql' => 'count(*) filter (where ar.is_early_departure)', 'format' => 'number'],
            'hours_worked' => ['label' => 'Hours worked', 'sql' => 'coalesce(sum(ar.hours_worked), 0)', 'format' => 'number'],
            'overtime_hours' => ['label' => 'Overtime hours', 'sql' => 'coalesce(sum(ar.overtime_hours), 0)', 'format' => 'number'],
            'unapproved' => ['label' => 'Awaiting manager approval', 'sql' => 'count(*) filter (where ar.manager_approved is not true)', 'format' => 'number'],
            'missing_clock_out' => ['label' => 'Missing clock-out', 'sql' => 'count(*) filter (where ar.clock_in is not null and ar.clock_out is null)', 'format' => 'number'],
            'employees' => ['label' => 'Employees', 'sql' => 'count(distinct ar.employee_id)', 'format' => 'number'],
            'absenteeism_rate' => ['label' => 'Absenteeism %', 'ratio' => ['absent', 'records'], 'scale' => 100, 'format' => 'percent'],
            'attendance_rate' => ['label' => 'Attendance %', 'ratio' => ['present', 'records'], 'scale' => 100, 'format' => 'percent'],
            'late_rate' => ['label' => 'Late arrivals %', 'ratio' => ['late', 'present'], 'scale' => 100, 'format' => 'percent'],
            'avg_hours' => ['label' => 'Average hours per day present', 'ratio' => ['hours_worked', 'present'], 'format' => 'number'],
            'overtime_per_person' => ['label' => 'Overtime hours per employee', 'ratio' => ['overtime_hours', 'employees'], 'format' => 'number'],
        ],
        'dimensions' => $personDims('h') + [
            'status' => ['label' => 'Attendance status', 'sql' => "coalesce(ast.name, 'Unknown')"],
            'shift' => ['label' => 'Shift', 'sql' => "coalesce(ash.name, 'No shift')"],
            'source' => ['label' => 'Clock source', 'sql' => "coalesce(initcap(nullif(ar.source, '')), 'Unknown')"],
            'weekday' => ['label' => 'Weekday', 'sql' => "to_char(ar.date, 'ID Dy')"],
        ],
    ],

    // ── Payroll ─────────────────────────────────────────────────────────────────────────────────
    'hr_payroll_runs' => [
        'module' => 'payroll',
        'label' => 'Payroll runs',
        'from' => 'payroll_runs pr',
        'company' => 'pr.company_id',
        'date' => 'pr.period_end',
        'where' => "pr.deleted_at is null and pr.status <> 'cancelled'",
        'measures' => [
            'gross' => ['label' => 'Gross pay', 'sql' => 'coalesce(sum(pr.total_gross), 0)', 'format' => 'currency'],
            'deductions' => ['label' => 'Deductions', 'sql' => 'coalesce(sum(pr.total_deductions), 0)', 'format' => 'currency'],
            'net' => ['label' => 'Net pay', 'sql' => 'coalesce(sum(pr.total_net), 0)', 'format' => 'currency'],
            'eti' => ['label' => 'ETI claimed', 'sql' => 'coalesce(sum(pr.total_eti), 0)', 'format' => 'currency'],
            'runs' => ['label' => 'Payroll runs', 'sql' => 'count(*)', 'format' => 'number'],
            'released' => ['label' => 'Released runs', 'sql' => "count(*) filter (where pr.status = 'released')", 'format' => 'number'],
            'employees_paid' => ['label' => 'Employees paid (sum of runs)', 'sql' => 'coalesce(sum(pr.employee_count), 0)', 'format' => 'number'],
            'avg_gross' => ['label' => 'Average gross per payslip', 'ratio' => ['gross', 'employees_paid'], 'format' => 'currency'],
            'deduction_rate' => ['label' => 'Deductions % of gross', 'ratio' => ['deductions', 'gross'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(pr.status, 'unknown'), '_', ' '))"],
            'frequency' => ['label' => 'Frequency', 'sql' => "coalesce(initcap(nullif(pr.frequency, '')), 'Monthly')"],
            'run' => ['label' => 'Payroll run', 'sql' => "coalesce(nullif(pr.name, ''), to_char(pr.period_end, 'Mon YYYY'))"],
            'risk' => ['label' => 'AI risk level', 'sql' => "coalesce(initcap(nullif(pr.ai_risk_level, '')), 'Not assessed')"],
        ],
    ],

    'hr_payslips' => [
        'module' => 'payroll',
        'label' => 'Payslips (cost by employee)',
        'from' => 'payslips ps left join hr_people h on h.user_id = ps.employee_id and h.company_id = ps.company_id ' . $deptJoin('h')
            . ' left join ethnicities eth on eth.id = h.ethnicity left join hr_positions pos on pos.id = h.position_id',
        'company' => 'ps.company_id',
        'date' => 'ps.period_end',
        'where' => 'ps.deleted_at is null',
        'measures' => [
            'gross' => ['label' => 'Gross pay', 'sql' => 'coalesce(sum(ps.gross_pay), 0)', 'format' => 'currency'],
            'basic' => ['label' => 'Basic salary', 'sql' => 'coalesce(sum(ps.basic_salary), 0)', 'format' => 'currency'],
            'net' => ['label' => 'Net pay', 'sql' => 'coalesce(sum(ps.net_pay), 0)', 'format' => 'currency'],
            'deductions' => ['label' => 'Deductions', 'sql' => 'coalesce(sum(ps.total_deductions), 0)', 'format' => 'currency'],
            'paye' => ['label' => 'PAYE', 'sql' => 'coalesce(sum(ps.paye), 0)', 'format' => 'currency'],
            'uif' => ['label' => 'UIF', 'sql' => 'coalesce(sum(ps.uif), 0)', 'format' => 'currency'],
            'pension' => ['label' => 'Pension / provident', 'sql' => 'coalesce(sum(ps.pension), 0)', 'format' => 'currency'],
            'eti' => ['label' => 'ETI', 'sql' => 'coalesce(sum(ps.eti), 0)', 'format' => 'currency'],
            'variable' => ['label' => 'Earnings above basic', 'sql' => 'coalesce(sum(ps.gross_pay - ps.basic_salary), 0)', 'format' => 'currency'],
            'payslips' => ['label' => 'Payslips', 'sql' => 'count(*)', 'format' => 'number'],
            'employees' => ['label' => 'Employees paid', 'sql' => 'count(distinct ps.employee_id)', 'format' => 'number'],
            'avg_gross' => ['label' => 'Average gross per payslip', 'ratio' => ['gross', 'payslips'], 'format' => 'currency'],
            'avg_net' => ['label' => 'Average net per payslip', 'ratio' => ['net', 'payslips'], 'format' => 'currency'],
            'tax_rate' => ['label' => 'PAYE % of gross', 'ratio' => ['paye', 'gross'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'race' => ['label' => 'Race', 'sql' => "coalesce(eth.value, 'Not captured')"],
            'position' => ['label' => 'Position', 'sql' => "coalesce(pos.name, 'Unassigned')"],
            'status' => ['label' => 'Payslip status', 'sql' => "initcap(replace(coalesce(ps.status, 'unknown'), '_', ' '))"],
        ],
    ],

    // ── Recruitment ─────────────────────────────────────────────────────────────────────────────
    'hr_recruitment' => [
        'module' => 'recruitment',
        'label' => 'Job applications',
        'from' => 'recruitment_applications ra left join recruitment_jobs rj on rj.id = ra.job_id left join recruitment_pipeline_stages rps on rps.id = ra.pipeline_stage_id'
            . ' left join recruitment_candidates rc on rc.id = ra.candidate_id',
        'company' => 'ra.company_id',
        'date' => 'coalesce(ra.applied_at, ra.created_at)::date',
        'where' => 'ra.deleted_at is null',
        'measures' => [
            'applications' => ['label' => 'Applications', 'sql' => 'count(*)', 'format' => 'number'],
            'candidates' => ['label' => 'Candidates', 'sql' => 'count(distinct ra.candidate_id)', 'format' => 'number'],
            'in_progress' => ['label' => 'In the pipeline', 'sql' => "count(*) filter (where ra.status in ('applied', 'screening', 'interview', 'offer_sent'))", 'format' => 'number'],
            'interviewed' => ['label' => 'Reached interview', 'sql' => "count(*) filter (where ra.status in ('interview', 'offer_sent', 'hired'))", 'format' => 'number'],
            'offers' => ['label' => 'Offers made', 'sql' => "count(*) filter (where ra.status in ('offer_sent', 'hired'))", 'format' => 'number'],
            'hired' => ['label' => 'Hired', 'sql' => "count(*) filter (where ra.status = 'hired')", 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where ra.status = 'rejected')", 'format' => 'number'],
            'withdrawn' => ['label' => 'Withdrawn', 'sql' => "count(*) filter (where ra.status = 'withdrawn')", 'format' => 'number'],
            'days_to_hire' => ['label' => 'Average days to hire', 'sql' => "coalesce(avg(extract(epoch from (coalesce(ra.stage_moved_at, ra.updated_at) - ra.applied_at)) / 86400.0) filter (where ra.status = 'hired'), 0)", 'format' => 'number'],
            'hire_rate' => ['label' => 'Hire rate %', 'ratio' => ['hired', 'applications'], 'scale' => 100, 'format' => 'percent'],
            'offer_acceptance' => ['label' => 'Offer acceptance %', 'ratio' => ['hired', 'offers'], 'scale' => 100, 'format' => 'percent'],
            'interview_rate' => ['label' => 'Interview rate %', 'ratio' => ['interviewed', 'applications'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'job' => ['label' => 'Job', 'sql' => "coalesce(rj.title, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(ra.status, 'unknown'), '_', ' '))"],
            'funnel_stage' => ['label' => 'Furthest stage reached', 'sql' => "case when ra.status = 'hired' then '5 Hired' when ra.status = 'offer_sent' then '4 Offer' when ra.status = 'interview' then '3 Interview' when ra.status = 'screening' then '2 Screening' when ra.status = 'applied' then '1 Applied' else '6 Closed (rejected / withdrawn)' end"],
            'source' => ['label' => 'Source', 'sql' => "coalesce(initcap(replace(nullif(ra.source, ''), '_', ' ')), 'Unknown')"],
            'stage' => ['label' => 'Pipeline stage', 'sql' => "coalesce(rps.name, 'Not staged')"],
            'employment_type' => ['label' => 'Employment type', 'sql' => "coalesce(initcap(replace(nullif(rj.employment_type, ''), '_', ' ')), 'Unknown')"],
            'location' => ['label' => 'Location', 'sql' => "coalesce(nullif(rj.location, ''), 'Unknown')"],
            'candidate_gender' => ['label' => 'Candidate gender', 'sql' => "coalesce(initcap(nullif(rc.gender, '')), 'Not captured')"],
        ],
    ],

    'hr_vacancies' => [
        'module' => 'recruitment',
        'label' => 'Vacancies',
        'from' => "recruitment_jobs rj left join company_units rju on rju.id = rj.company_unit_id left join (select job_id, count(*) as n, count(*) filter (where status = 'hired') as hired from recruitment_applications where deleted_at is null group by job_id) rja on rja.job_id = rj.id",
        'company' => 'rj.company_id',
        'date' => 'coalesce(rj.posted_at, rj.created_at)::date',
        'where' => 'rj.deleted_at is null',
        'measures' => [
            'jobs' => ['label' => 'Vacancies', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open vacancies', 'sql' => 'count(*) filter (where rj.is_active and (rj.closes_at is null or rj.closes_at >= current_date))', 'format' => 'number'],
            'filled' => ['label' => 'Vacancies with a hire', 'sql' => 'count(*) filter (where coalesce(rja.hired, 0) > 0)', 'format' => 'number'],
            'applications' => ['label' => 'Applications received', 'sql' => 'coalesce(sum(rja.n), 0)', 'format' => 'number'],
            'avg_salary' => ['label' => 'Average advertised salary (mid-point)', 'sql' => 'coalesce(avg((coalesce(rj.salary_min, rj.salary_max) + coalesce(rj.salary_max, rj.salary_min)) / 2.0), 0)', 'format' => 'currency'],
            'fill_rate' => ['label' => 'Fill rate %', 'ratio' => ['filled', 'jobs'], 'scale' => 100, 'format' => 'percent'],
            'apps_per_job' => ['label' => 'Applications per vacancy', 'ratio' => ['applications', 'jobs'], 'format' => 'number'],
        ],
        'dimensions' => [
            'job' => ['label' => 'Job', 'sql' => "coalesce(rj.title, 'Untitled')"],
            'department' => ['label' => 'Department', 'sql' => "coalesce(rju.name, 'Unassigned')"],
            'employment_type' => ['label' => 'Employment type', 'sql' => "coalesce(initcap(replace(nullif(rj.employment_type, ''), '_', ' ')), 'Unknown')"],
            'location' => ['label' => 'Location', 'sql' => "coalesce(nullif(rj.location, ''), 'Unknown')"],
            'status' => ['label' => 'Open / closed', 'sql' => "case when rj.is_active and (rj.closes_at is null or rj.closes_at >= current_date) then 'Open' else 'Closed' end"],
        ],
    ],

    // ── On- and offboarding ─────────────────────────────────────────────────────────────────────
    'hr_onboarding' => [
        'module' => 'boarding',
        'label' => 'Onboarding plans',
        'from' => 'onboarding_plans op join hr_people h on h.id = op.hr_person_id ' . $deptJoin('h') . ' left join onboarding_templates otp on otp.id = op.template_id'
            . " left join (select onboarding_plan_id, count(*) as n, count(*) filter (where status in ('completed', 'skipped')) as done from onboarding_tasks where deleted_at is null group by onboarding_plan_id) opt on opt.onboarding_plan_id = op.id",
        'company' => 'op.company_id',
        'date' => 'coalesce(op.start_date, op.created_at::date)',
        'where' => 'op.deleted_at is null',
        'measures' => [
            'plans' => ['label' => 'New starters onboarding', 'sql' => 'count(*)', 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where op.status = 'completed')", 'format' => 'number'],
            'active' => ['label' => 'In progress', 'sql' => "count(*) filter (where op.status in ('pending', 'active'))", 'format' => 'number'],
            'overdue' => ['label' => 'Past end date, not complete', 'sql' => "count(*) filter (where op.status in ('pending', 'active') and op.end_date < current_date)", 'format' => 'number'],
            'tasks' => ['label' => 'Tasks', 'sql' => 'coalesce(sum(opt.n), 0)', 'format' => 'number'],
            'tasks_done' => ['label' => 'Tasks done', 'sql' => 'coalesce(sum(opt.done), 0)', 'format' => 'number'],
            'days_to_complete' => ['label' => 'Average days to complete', 'sql' => "coalesce(avg(op.completed_at::date - op.start_date) filter (where op.status = 'completed'), 0)", 'format' => 'number'],
            'completion_rate' => ['label' => 'Plans completed %', 'ratio' => ['completed', 'plans'], 'scale' => 100, 'format' => 'percent'],
            'task_progress' => ['label' => 'Task progress %', 'ratio' => ['tasks_done', 'tasks'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h', 'New starter') + [
            'status' => ['label' => 'Status', 'sql' => "initcap(coalesce(op.status, 'unknown'))"],
            'template' => ['label' => 'Template', 'sql' => "coalesce(otp.name, 'No template')"],
        ],
    ],

    'hr_onboarding_tasks' => [
        'module' => 'boarding',
        'label' => 'Onboarding tasks',
        'from' => 'onboarding_tasks ot join onboarding_plans op on op.id = ot.onboarding_plan_id join hr_people h on h.id = op.hr_person_id ' . $deptJoin('h'),
        'company' => 'ot.company_id',
        'date' => 'coalesce(ot.due_date, op.start_date, ot.created_at::date)',
        'where' => 'ot.deleted_at is null and op.deleted_at is null',
        'measures' => [
            'tasks' => ['label' => 'Tasks', 'sql' => 'count(*)', 'format' => 'number'],
            'done' => ['label' => 'Done', 'sql' => "count(*) filter (where ot.status in ('completed', 'skipped'))", 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where ot.status in ('pending', 'in_progress'))", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where ot.status in ('pending', 'in_progress') and ot.due_date < current_date)", 'format' => 'number'],
            'done_pct' => ['label' => 'Done %', 'ratio' => ['done', 'tasks'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h', 'New starter') + [
            'type' => ['label' => 'Task type', 'sql' => "coalesce(initcap(replace(nullif(ot.type, ''), '_', ' ')), 'Other')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(ot.status, 'unknown'), '_', ' '))"],
        ],
    ],

    'hr_offboarding' => [
        'module' => 'offboarding',
        'label' => 'Exits & exit interviews',
        'from' => 'offboarding_plans ob join hr_people h on h.id = ob.hr_person_id ' . $deptJoin('h')
            . ' left join (select plan_id, avg(satisfaction_score) as satisfaction, bool_or(would_recommend) as recommend, max(reason_for_leaving) as reason, count(*) as n from offboarding_exit_interviews group by plan_id) oei on oei.plan_id = ob.id',
        'company' => 'ob.company_id',
        'date' => 'coalesce(ob.last_working_day, ob.resignation_date, ob.created_at::date)',
        'where' => "ob.deleted_at is null and ob.status <> 'cancelled'",
        'measures' => [
            'exits' => ['label' => 'Exits', 'sql' => 'count(*)', 'format' => 'number'],
            'resignations' => ['label' => 'Resignations', 'sql' => "count(*) filter (where ob.termination_type = 'resignation')", 'format' => 'number'],
            'involuntary' => ['label' => 'Dismissals & retrenchments', 'sql' => "count(*) filter (where ob.termination_type in ('dismissal', 'retrenchment'))", 'format' => 'number'],
            'open' => ['label' => 'Exits still being processed', 'sql' => "count(*) filter (where ob.status in ('pending', 'active'))", 'format' => 'number'],
            'rehire_eligible' => ['label' => 'Eligible for rehire', 'sql' => 'count(*) filter (where ob.rehire_eligible)', 'format' => 'number'],
            'counteroffers' => ['label' => 'Counter-offers made', 'sql' => 'count(*) filter (where ob.counteroffer_made)', 'format' => 'number'],
            'interviewed' => ['label' => 'Exit interviews held', 'sql' => 'count(*) filter (where oei.n > 0)', 'format' => 'number'],
            'would_recommend' => ['label' => 'Would recommend us', 'sql' => 'count(*) filter (where oei.recommend)', 'format' => 'number'],
            'satisfaction' => ['label' => 'Average exit satisfaction (1–5)', 'sql' => 'coalesce(avg(oei.satisfaction), 0)', 'format' => 'number'],
            'resignation_pct' => ['label' => 'Voluntary exits %', 'ratio' => ['resignations', 'exits'], 'scale' => 100, 'format' => 'percent'],
            'interview_rate' => ['label' => 'Exit interview coverage %', 'ratio' => ['interviewed', 'exits'], 'scale' => 100, 'format' => 'percent'],
            'recommend_pct' => ['label' => 'Would recommend %', 'ratio' => ['would_recommend', 'interviewed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'termination_type' => ['label' => 'Reason for exit', 'sql' => "coalesce(initcap(replace(nullif(ob.termination_type, ''), '_', ' ')), 'Unknown')"],
            'reason' => ['label' => 'Reason given at exit interview', 'sql' => "coalesce(nullif(initcap(replace(oei.reason, '_', ' ')), ''), 'No interview')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(coalesce(ob.status, 'unknown'))"],
            'rehire' => ['label' => 'Rehire?', 'sql' => "case when ob.rehire_eligible then 'Eligible' when ob.rehire_eligible is false then 'Not eligible' else 'Not decided' end"],
        ],
    ],

    // ── Performance ─────────────────────────────────────────────────────────────────────────────
    'hr_performance' => [
        'module' => 'performance_appraisal',
        'label' => 'Performance appraisals',
        'from' => 'pa_performance_snapshots pps left join hr_people h on h.user_id = pps.employee_id and h.company_id = pps.company_id ' . $deptJoin('h')
            . ' left join pa_cycles pc on pc.id = pps.cycle_id',
        'company' => 'pps.company_id',
        'date' => 'coalesce(pps.snapshot_date, pps.created_at::date)',
        'measures' => [
            'appraisals' => ['label' => 'Appraisals', 'sql' => 'count(*)', 'format' => 'number'],
            'employees' => ['label' => 'Employees appraised', 'sql' => 'count(distinct pps.employee_id)', 'format' => 'number'],
            'avg_score' => ['label' => 'Average rating', 'sql' => 'coalesce(avg(coalesce(pps.final_score, pps.overall_score)), 0)', 'format' => 'number'],
            'avg_self' => ['label' => 'Average self-rating', 'sql' => 'coalesce(avg(pps.employee_score), 0)', 'format' => 'number'],
            'avg_manager' => ['label' => 'Average manager rating', 'sql' => 'coalesce(avg(pps.manager_score), 0)', 'format' => 'number'],
            'avg_360' => ['label' => 'Average 360 score', 'sql' => 'coalesce(avg(pps.score_360), 0)', 'format' => 'number'],
            'kpi_completion' => ['label' => 'Average KPI completion %', 'sql' => 'coalesce(avg(pps.kpi_completion_rate), 0)', 'format' => 'percent'],
            'high' => ['label' => 'High performers (4+)', 'sql' => 'count(*) filter (where coalesce(pps.final_score, pps.overall_score) >= 4)', 'format' => 'number'],
            'low' => ['label' => 'Below expectations (under 2.5)', 'sql' => 'count(*) filter (where coalesce(pps.final_score, pps.overall_score) < 2.5)', 'format' => 'number'],
            'approved' => ['label' => 'Approved appraisals', 'sql' => "count(*) filter (where pps.approved_at is not null or pps.appraisal_status = 'approved')", 'format' => 'number'],
            'high_pct' => ['label' => 'High performers %', 'ratio' => ['high', 'appraisals'], 'scale' => 100, 'format' => 'percent'],
            'approved_pct' => ['label' => 'Appraisals approved %', 'ratio' => ['approved', 'appraisals'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'cycle' => ['label' => 'Review cycle', 'sql' => "coalesce(pc.name, 'No cycle')"],
            'status' => ['label' => 'Appraisal status', 'sql' => "initcap(replace(coalesce(pps.appraisal_status, 'unknown'), '_', ' '))"],
            'rating_band' => ['label' => 'Rating band', 'sql' => "case when coalesce(pps.final_score, pps.overall_score) is null then 'Not rated' when coalesce(pps.final_score, pps.overall_score) >= 4.5 then '5 Outstanding' when coalesce(pps.final_score, pps.overall_score) >= 3.5 then '4 Exceeds' when coalesce(pps.final_score, pps.overall_score) >= 2.5 then '3 Meets' when coalesce(pps.final_score, pps.overall_score) >= 1.5 then '2 Partly meets' else '1 Below' end"],
        ],
    ],

    'hr_kudos' => [
        'module' => 'performance_appraisal',
        'label' => 'Peer kudos (badges)',
        'from' => 'pa_recognitions pr2 left join hr_people h on h.user_id = pr2.receiver_id and h.company_id = pr2.company_id ' . $deptJoin('h')
            . ' left join hr_people g on g.user_id = pr2.giver_id and g.company_id = pr2.company_id',
        'company' => 'pr2.company_id',
        'date' => 'pr2.created_at::date',
        'measures' => [
            'kudos' => ['label' => 'Kudos given', 'sql' => 'count(*)', 'format' => 'number'],
            'receivers' => ['label' => 'People recognised', 'sql' => 'count(distinct pr2.receiver_id)', 'format' => 'number'],
            'givers' => ['label' => 'People giving kudos', 'sql' => 'count(distinct pr2.giver_id)', 'format' => 'number'],
            'public' => ['label' => 'Shared publicly', 'sql' => 'count(*) filter (where pr2.is_public)', 'format' => 'number'],
            'per_person' => ['label' => 'Kudos per person recognised', 'ratio' => ['kudos', 'receivers'], 'format' => 'number'],
        ],
        'dimensions' => $personDims('h', 'Recognised') + [
            'badge' => ['label' => 'Badge', 'sql' => "coalesce(initcap(replace(nullif(pr2.badge, ''), '_', ' ')), 'No badge')"],
            'giver' => ['label' => 'Given by', 'sql' => "coalesce(nullif(trim(coalesce(g.first_name, '') || ' ' || coalesce(g.surname, '')), ''), 'Unknown')"],
        ],
    ],

    // ── Learning ────────────────────────────────────────────────────────────────────────────────
    'hr_training' => [
        'module' => 'learning_management',
        'label' => 'Training enrolments',
        'from' => 'lms_enrollments le left join lms_courses lc on lc.id = le.course_id left join lms_categories lcat on lcat.id = lc.category_id'
            . ' left join hr_people h on h.user_id = le.user_id and h.company_id = le.company_id ' . $deptJoin('h'),
        'company' => 'le.company_id',
        'date' => 'coalesce(le.enrolled_at, le.created_at)::date',
        'where' => 'le.deleted_at is null',
        'measures' => [
            'enrolments' => ['label' => 'Enrolments', 'sql' => 'count(*)', 'format' => 'number'],
            'learners' => ['label' => 'Learners', 'sql' => 'count(distinct le.user_id)', 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where le.status = 'completed')", 'format' => 'number'],
            'failed' => ['label' => 'Failed', 'sql' => "count(*) filter (where le.status = 'failed')", 'format' => 'number'],
            'in_progress' => ['label' => 'In progress', 'sql' => "count(*) filter (where le.status in ('enrolled', 'in_progress'))", 'format' => 'number'],
            'finished' => ['label' => 'Finished (passed or failed)', 'sql' => "count(*) filter (where le.status in ('completed', 'failed'))", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where le.status in ('enrolled', 'in_progress') and le.due_date < current_date)", 'format' => 'number'],
            'avg_score' => ['label' => 'Average score %', 'sql' => 'coalesce(avg(le.score) filter (where le.status in (\'completed\', \'failed\')), 0)', 'format' => 'percent'],
            'avg_progress' => ['label' => 'Average progress %', 'sql' => 'coalesce(avg(le.progress_pct), 0)', 'format' => 'percent'],
            'hours' => ['label' => 'Learning hours', 'sql' => 'coalesce(sum(le.time_spent_mins) / 60.0, 0)', 'format' => 'number'],
            'completion_rate' => ['label' => 'Completion rate %', 'ratio' => ['completed', 'enrolments'], 'scale' => 100, 'format' => 'percent'],
            'pass_rate' => ['label' => 'Pass rate %', 'ratio' => ['completed', 'finished'], 'scale' => 100, 'format' => 'percent'],
            'hours_per_learner' => ['label' => 'Hours per learner', 'ratio' => ['hours', 'learners'], 'format' => 'number'],
        ],
        'dimensions' => $personDims('h', 'Learner') + [
            'course' => ['label' => 'Course', 'sql' => "coalesce(lc.title, 'Unknown')"],
            'category' => ['label' => 'Course category', 'sql' => "coalesce(lcat.name, 'Uncategorised')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(le.status, 'unknown'), '_', ' '))"],
            'mandatory' => ['label' => 'Mandatory?', 'sql' => "case when lc.is_mandatory then 'Mandatory' else 'Optional' end"],
            'course_type' => ['label' => 'Course type', 'sql' => "coalesce(initcap(replace(nullif(lc.type, ''), '_', ' ')), 'Other')"],
            'accredited' => ['label' => 'SETA accredited?', 'sql' => "case when lc.is_accredited then 'Accredited' else 'Not accredited' end"],
        ],
    ],

    'hr_compliance_training' => [
        'module' => 'learning_management',
        'label' => 'Compliance training',
        'from' => 'lms_compliance_assignments lca left join lms_courses lc on lc.id = lca.course_id'
            . ' left join hr_people h on h.user_id = lca.user_id and h.company_id = lca.company_id ' . $deptJoin('h'),
        'company' => 'lca.company_id',
        'date' => 'coalesce(lca.assigned_at, lca.created_at)::date',
        'measures' => [
            'assigned' => ['label' => 'Assignments', 'sql' => 'count(*)', 'format' => 'number'],
            'required' => ['label' => 'Required (not waived)', 'sql' => "count(*) filter (where lca.status <> 'waived')", 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where lca.status = 'completed')", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where lca.status = 'overdue' or (lca.status = 'pending' and lca.due_date < current_date))", 'format' => 'number'],
            'pending' => ['label' => 'Pending', 'sql' => "count(*) filter (where lca.status = 'pending')", 'format' => 'number'],
            'waived' => ['label' => 'Waived', 'sql' => "count(*) filter (where lca.status = 'waived')", 'format' => 'number'],
            'people' => ['label' => 'People assigned', 'sql' => 'count(distinct lca.user_id)', 'format' => 'number'],
            'compliance_rate' => ['label' => 'Compliance %', 'ratio' => ['completed', 'required'], 'scale' => 100, 'format' => 'percent'],
            'overdue_rate' => ['label' => 'Overdue %', 'ratio' => ['overdue', 'required'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'course' => ['label' => 'Course', 'sql' => "coalesce(lc.title, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(coalesce(lca.status, 'unknown'))"],
        ],
    ],

    'hr_certificates' => [
        'module' => 'learning_management',
        'label' => 'Training certificates',
        'from' => 'lms_certificates lce left join lms_enrollments le on le.id = lce.enrollment_id left join lms_courses lc on lc.id = le.course_id'
            . ' left join hr_people h on h.id = lce.hr_person_id ' . $deptJoin('h'),
        'company' => 'lce.company_id',
        'date' => 'coalesce(lce.issued_at, lce.created_at)::date',
        'where' => 'lce.deleted_at is null',
        'measures' => [
            'issued' => ['label' => 'Certificates issued', 'sql' => 'count(*)', 'format' => 'number'],
            'valid' => ['label' => 'Currently valid', 'sql' => 'count(*) filter (where lce.expires_at is null or lce.expires_at >= now())', 'format' => 'number'],
            'expiring' => ['label' => 'Expiring in 90 days', 'sql' => "count(*) filter (where lce.expires_at >= now() and lce.expires_at < now() + interval '90 days')", 'format' => 'number'],
            'expired' => ['label' => 'Expired', 'sql' => 'count(*) filter (where lce.expires_at < now())', 'format' => 'number'],
            'valid_pct' => ['label' => 'Valid %', 'ratio' => ['valid', 'issued'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'course' => ['label' => 'Course', 'sql' => "coalesce(lc.title, 'Unknown')"],
            'validity' => ['label' => 'Validity', 'sql' => "case when lce.expires_at is null then 'Does not expire' when lce.expires_at < now() then 'Expired' when lce.expires_at < now() + interval '90 days' then 'Expiring within 90 days' else 'Valid' end"],
        ],
    ],

    // ── Discipline ──────────────────────────────────────────────────────────────────────────────
    'hr_disciplinary' => [
        'module' => 'disciplinary',
        'label' => 'Disciplinary cases',
        'from' => 'disciplinary_cases dc left join disciplinary_categories dcat on dcat.id = dc.category_id left join disciplinary_statuses dst on dst.id = dc.status_id'
            . ' left join disciplinary_outcomes dout on dout.id = dc.outcome_id left join disciplinary_sanctions dsan on dsan.id = dc.sanction_id'
            . ' left join company_units dcu on dcu.id = dc.department_id left join hr_people h on h.user_id = dc.employee_id and h.company_id = dc.company_id ' . $deptJoin('h'),
        'company' => 'dc.company_id',
        'date' => 'coalesce(dc.incident_date, dc.created_at::date)',
        'where' => 'dc.deleted_at is null',
        'measures' => [
            'cases' => ['label' => 'Cases', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open cases', 'sql' => 'count(*) filter (where dc.closed_at is null and dst.is_closed is not true)', 'format' => 'number'],
            'closed' => ['label' => 'Closed cases', 'sql' => 'count(*) filter (where dc.closed_at is not null or dst.is_closed)', 'format' => 'number'],
            'hearings' => ['label' => 'Hearings held / set', 'sql' => 'count(*) filter (where dc.hearing_date is not null)', 'format' => 'number'],
            'sanctioned' => ['label' => 'Cases with a sanction', 'sql' => 'count(*) filter (where dc.sanction_id is not null)', 'format' => 'number'],
            'employees' => ['label' => 'Employees involved', 'sql' => 'count(distinct dc.employee_id)', 'format' => 'number'],
            'days_to_close' => ['label' => 'Average days to close', 'sql' => 'coalesce(avg(dc.closed_at::date - coalesce(dc.incident_date, dc.created_at::date)) filter (where dc.closed_at is not null), 0)', 'format' => 'number'],
            'open_age' => ['label' => 'Average age of open cases (days)', 'sql' => 'coalesce(avg(current_date - coalesce(dc.incident_date, dc.created_at::date)) filter (where dc.closed_at is null and dst.is_closed is not true), 0)', 'format' => 'number'],
            'closure_rate' => ['label' => 'Closed %', 'ratio' => ['closed', 'cases'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => array_replace($personDims('h'), [
            'department' => ['label' => 'Department', 'sql' => "coalesce(dcu.name, h_dep.name, 'Unassigned')"],
        ]) + [
            'category' => ['label' => 'Offence category', 'sql' => "coalesce(dcat.name, 'Uncategorised')"],
            'severity' => ['label' => 'Severity', 'sql' => "coalesce('Level ' || dcat.severity_level::text, 'Not set')"],
            'status' => ['label' => 'Status', 'sql' => "coalesce(dst.name, 'Unknown')"],
            'outcome' => ['label' => 'Outcome', 'sql' => "coalesce(dout.name, 'No outcome yet')"],
            'sanction' => ['label' => 'Sanction', 'sql' => "coalesce(dsan.name, 'None')"],
        ],
    ],

    'hr_warnings' => [
        'module' => 'disciplinary',
        'label' => 'Disciplinary warnings',
        'from' => 'disciplinary_warnings dw left join hr_people h on h.user_id = dw.employee_id and h.company_id = dw.company_id ' . $deptJoin('h'),
        'company' => 'dw.company_id',
        'date' => 'coalesce(dw.issued_date, dw.created_at::date)',
        'where' => 'dw.deleted_at is null',
        'measures' => [
            'warnings' => ['label' => 'Warnings issued', 'sql' => 'count(*)', 'format' => 'number'],
            'active' => ['label' => 'Active warnings', 'sql' => 'count(*) filter (where dw.is_expired is not true and (dw.expiry_date is null or dw.expiry_date >= current_date))', 'format' => 'number'],
            'final' => ['label' => 'Final written warnings', 'sql' => "count(*) filter (where dw.warning_type = 'final_written')", 'format' => 'number'],
            'acknowledged' => ['label' => 'Acknowledged by employee', 'sql' => 'count(*) filter (where dw.employee_acknowledged)', 'format' => 'number'],
            'employees' => ['label' => 'Employees warned', 'sql' => 'count(distinct dw.employee_id)', 'format' => 'number'],
            'ack_rate' => ['label' => 'Acknowledged %', 'ratio' => ['acknowledged', 'warnings'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h') + [
            'warning_type' => ['label' => 'Warning type', 'sql' => "case dw.warning_type when 'verbal' then 'Verbal' when 'written' then 'Written' when 'final_written' then 'Final written' else 'Other' end"],
            'state' => ['label' => 'Active / expired', 'sql' => "case when dw.is_expired is not true and (dw.expiry_date is null or dw.expiry_date >= current_date) then 'Active' else 'Expired' end"],
        ],
    ],

    // ── Employee reputation ─────────────────────────────────────────────────────────────────────


    // ── Claims ──────────────────────────────────────────────────────────────────────────────────
    'hr_claims' => [
        'module' => 'claims',
        'label' => 'Expense claims',
        'from' => 'claims cl left join claim_categories clc on clc.id = cl.category_id left join hr_people h on h.user_id = cl.employee_id and h.company_id = cl.company_id ' . $deptJoin('h'),
        'company' => 'cl.company_id',
        'date' => 'coalesce(cl.claim_date, cl.submitted_at::date, cl.created_at::date)',
        'where' => "cl.deleted_at is null and cl.status not in ('draft', 'cancelled')",
        'measures' => [
            'claimed' => ['label' => 'Claimed', 'sql' => 'coalesce(sum(cl.total_amount), 0)', 'format' => 'currency'],
            'approved_amount' => ['label' => 'Approved', 'sql' => "coalesce(sum(cl.total_amount) filter (where cl.status in ('approved', 'paid')), 0)", 'format' => 'currency'],
            'paid' => ['label' => 'Paid out', 'sql' => "coalesce(sum(cl.total_amount) filter (where cl.status = 'paid'), 0)", 'format' => 'currency'],
            'awaiting_payment' => ['label' => 'Approved, not yet paid', 'sql' => "coalesce(sum(cl.total_amount) filter (where cl.status = 'approved'), 0)", 'format' => 'currency'],
            'pending_amount' => ['label' => 'Awaiting approval', 'sql' => "coalesce(sum(cl.total_amount) filter (where cl.status in ('submitted', 'under_review')), 0)", 'format' => 'currency'],
            'rejected_amount' => ['label' => 'Rejected', 'sql' => "coalesce(sum(cl.total_amount) filter (where cl.status = 'rejected'), 0)", 'format' => 'currency'],
            'claims' => ['label' => 'Claims', 'sql' => 'count(*)', 'format' => 'number'],
            'claimants' => ['label' => 'Claimants', 'sql' => 'count(distinct cl.employee_id)', 'format' => 'number'],
            'high_risk' => ['label' => 'High-risk claims', 'sql' => "count(*) filter (where cl.risk_level in ('high', 'critical'))", 'format' => 'number'],
            'days_to_pay' => ['label' => 'Average days submit → paid', 'sql' => 'coalesce(avg(extract(epoch from (cl.paid_at - cl.submitted_at)) / 86400.0) filter (where cl.paid_at is not null and cl.submitted_at is not null), 0)', 'format' => 'number'],
            'avg_claim' => ['label' => 'Average claim', 'ratio' => ['claimed', 'claims'], 'format' => 'currency'],
            'approval_rate' => ['label' => 'Approved % of value', 'ratio' => ['approved_amount', 'claimed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h', 'Claimant') + [
            'category' => ['label' => 'Category', 'sql' => "coalesce(clc.name, 'Uncategorised')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(cl.status, 'unknown'), '_', ' '))"],
            'risk_level' => ['label' => 'Risk level', 'sql' => "coalesce(initcap(nullif(cl.risk_level, '')), 'Not assessed')"],
            'travel' => ['label' => 'Travel / other', 'sql' => "case when clc.is_travel then 'Travel' else 'Other expenses' end"],
        ],
    ],

    // ── Staff loans & salary advances ───────────────────────────────────────────────────────────
    'hr_staff_loans' => [
        'module' => 'staff_loans',
        'label' => 'Staff loans',
        'from' => 'staff_loans sl left join staff_loan_types slt on slt.id = sl.loan_type_id left join hr_people h on h.user_id = sl.employee_id and h.company_id = sl.company_id ' . $deptJoin('h'),
        'company' => 'sl.company_id',
        'date' => 'coalesce(sl.disbursed_at, sl.approved_at, sl.created_at)::date',
        'where' => "sl.deleted_at is null and sl.status <> 'draft'",
        'measures' => [
            'requested' => ['label' => 'Amount requested', 'sql' => 'coalesce(sum(sl.amount_requested), 0)', 'format' => 'currency'],
            'approved_amount' => ['label' => 'Amount approved', 'sql' => "coalesce(sum(sl.amount_approved) filter (where sl.status in ('approved', 'active', 'closed', 'defaulted')), 0)", 'format' => 'currency'],
            'outstanding' => ['label' => 'Outstanding balance', 'sql' => "coalesce(sum(sl.outstanding_balance) filter (where sl.status in ('approved', 'active', 'defaulted')), 0)", 'format' => 'currency'],
            'defaulted_amount' => ['label' => 'Balance in default', 'sql' => "coalesce(sum(sl.outstanding_balance) filter (where sl.status = 'defaulted'), 0)", 'format' => 'currency'],
            'monthly_collections' => ['label' => 'Monthly instalments due', 'sql' => "coalesce(sum(sl.monthly_installment) filter (where sl.status = 'active'), 0)", 'format' => 'currency'],
            'loans' => ['label' => 'Loans', 'sql' => 'count(*)', 'format' => 'number'],
            'active' => ['label' => 'Active loans', 'sql' => "count(*) filter (where sl.status = 'active')", 'format' => 'number'],
            'pending' => ['label' => 'Awaiting decision', 'sql' => "count(*) filter (where sl.status in ('pending', 'under_review'))", 'format' => 'number'],
            'defaulted' => ['label' => 'Defaulted loans', 'sql' => "count(*) filter (where sl.status = 'defaulted')", 'format' => 'number'],
            'borrowers' => ['label' => 'Borrowers', 'sql' => 'count(distinct sl.employee_id)', 'format' => 'number'],
            'avg_rate' => ['label' => 'Average interest rate %', 'sql' => 'coalesce(avg(sl.interest_rate), 0)', 'format' => 'percent'],
            'avg_loan' => ['label' => 'Average loan', 'ratio' => ['approved_amount', 'loans'], 'format' => 'currency'],
            'default_rate' => ['label' => 'Default % of book', 'ratio' => ['defaulted_amount', 'outstanding'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h', 'Borrower') + [
            'loan_type' => ['label' => 'Loan type', 'sql' => "coalesce(slt.name, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => "initcap(replace(coalesce(sl.status, 'unknown'), '_', ' '))"],
            'risk_level' => ['label' => 'Risk level', 'sql' => "coalesce(initcap(nullif(sl.risk_level, '')), 'Not assessed')"],
            'term' => ['label' => 'Term', 'sql' => "case when sl.term_months is null then 'Unknown' when sl.term_months <= 6 then 'Up to 6 months' when sl.term_months <= 12 then '7–12 months' when sl.term_months <= 24 then '13–24 months' else 'Over 24 months' end"],
        ],
    ],

    'hr_loan_repayments' => [
        'module' => 'staff_loans',
        'label' => 'Staff loan repayments',
        'from' => 'staff_loan_repayments slr join staff_loans sl on sl.id = slr.loan_id left join staff_loan_types slt on slt.id = sl.loan_type_id'
            . ' left join hr_people h on h.user_id = sl.employee_id and h.company_id = sl.company_id ' . $deptJoin('h'),
        'company' => 'slr.company_id',
        'date' => 'slr.due_date',
        'where' => 'sl.deleted_at is null',
        'measures' => [
            'due' => ['label' => 'Instalments due', 'sql' => 'coalesce(sum(slr.amount_due), 0)', 'format' => 'currency'],
            'collected' => ['label' => 'Collected', 'sql' => 'coalesce(sum(slr.amount_paid), 0)', 'format' => 'currency'],
            'arrears' => ['label' => 'Arrears', 'sql' => "coalesce(sum(greatest(slr.amount_due - coalesce(slr.amount_paid, 0), 0)) filter (where slr.status in ('overdue', 'partial') or (slr.status = 'pending' and slr.due_date < current_date)), 0)", 'format' => 'currency'],
            'interest' => ['label' => 'Interest earned', 'sql' => "coalesce(sum(slr.interest_component) filter (where slr.status in ('paid', 'partial')), 0)", 'format' => 'currency'],
            'instalments' => ['label' => 'Instalments', 'sql' => 'count(*)', 'format' => 'number'],
            'via_payroll' => ['label' => 'Deducted via payroll', 'sql' => 'count(*) filter (where slr.payroll_deducted)', 'format' => 'number'],
            'collection_rate' => ['label' => 'Collection rate %', 'ratio' => ['collected', 'due'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => $personDims('h', 'Borrower') + [
            'status' => ['label' => 'Status', 'sql' => "initcap(coalesce(slr.status, 'unknown'))"],
            'loan_type' => ['label' => 'Loan type', 'sql' => "coalesce(slt.name, 'Unknown')"],
        ],
    ],

    'hr_salary_advances' => [
        'module' => 'staff_loans',
        'label' => 'Salary advances',
        'from' => 'salary_advances sa left join hr_people h on h.user_id = sa.employee_id and h.company_id = sa.company_id ' . $deptJoin('h'),
        'company' => 'sa.company_id',
        'date' => 'coalesce(sa.disbursed_at, sa.approved_at, sa.created_at)::date',
        'where' => 'sa.deleted_at is null',
        'measures' => [
            'requested' => ['label' => 'Amount requested', 'sql' => 'coalesce(sum(sa.amount_requested), 0)', 'format' => 'currency'],
            'approved_amount' => ['label' => 'Amount approved', 'sql' => "coalesce(sum(sa.amount_approved) filter (where sa.status in ('approved', 'disbursed', 'settled')), 0)", 'format' => 'currency'],
            'outstanding' => ['label' => 'Disbursed, not yet recovered', 'sql' => "coalesce(sum(sa.amount_approved) filter (where sa.status = 'disbursed'), 0)", 'format' => 'currency'],
            'advances' => ['label' => 'Advances', 'sql' => 'count(*)', 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where sa.status = 'rejected')", 'format' => 'number'],
            'people' => ['label' => 'Employees', 'sql' => 'count(distinct sa.employee_id)', 'format' => 'number'],
            'avg_pct_net' => ['label' => 'Average % of net pay', 'sql' => 'coalesce(avg(sa.percentage_of_net), 0)', 'format' => 'percent'],
            'avg_advance' => ['label' => 'Average advance', 'ratio' => ['approved_amount', 'advances'], 'format' => 'currency'],
        ],
        'dimensions' => $personDims('h') + [
            'status' => ['label' => 'Status', 'sql' => "initcap(coalesce(sa.status, 'unknown'))"],
            'risk_level' => ['label' => 'Risk level', 'sql' => "coalesce(initcap(nullif(sa.risk_level, '')), 'Not assessed')"],
        ],
    ],

    // ── Recognition ─────────────────────────────────────────────────────────────────────────────
];
