<?php

use App\Modules\BI\Support\Tpl;

// Governance, compliance, documents, workflow and tax / finance-executive templates.
// Datasets: app/Modules/BI/Datasets/governance.php. Layout: KPI row at y=0 (four w=3 h=4), charts below.

return [
    // ── Compliance: policy enforcement ─────────────────────────────────────────────────────────────
    'cmp_policy_compliance' => [
        'name' => 'Policy Compliance Overview', 'group' => 'Compliance', 'icon' => 'tabler-shield-check',
        'description' => 'How well policies are followed: violations, sign-offs and where the risk sits.',
        'widgets' => [
            Tpl::kpi('Violations this year', 'pol_violations', ['violations', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Open violations', 'pol_violations', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Policies signed', 'pol_attestations', ['completion_rate'], 6, 0, 'gauge', 'all'),
            Tpl::kpi('Closed', 'pol_violations', ['close_rate'], 9, 0, 'progress'),
            Tpl::w('combo_column_line', 'Violations by month vs last year', 'pol_violations', ['measures' => ['violations', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Violations by severity', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'severity'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Policies broken most often', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'policy', 'top' => 10], 0, 13, 6, 9),
            Tpl::w('heatmap_matrix', 'Department by severity', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'department', 'series' => 'severity', 'top' => 12], 6, 13, 6, 9),
            Tpl::w('smart_narrative', 'In short', 'pol_violations', ['measures' => ['violations', 'open', 'serious'], 'dimension' => 'policy'], 0, 22, 12, 5),
        ],
    ],
    'cmp_policy_violations' => [
        'name' => 'Policy Violations & Risk', 'group' => 'Compliance', 'icon' => 'tabler-alert-triangle',
        'description' => 'Which rules are breached, by whom, how serious it is and how fast it is closed.',
        'widgets' => [
            Tpl::kpi('High & critical', 'pol_violations', ['serious', 'growth_pct'], 0),
            Tpl::kpi('Under investigation', 'pol_violations', ['investigating'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Blocked actions', 'pol_violations', ['blocked'], 6),
            Tpl::kpi('Average days to close', 'pol_violations', ['avg_days_to_close'], 9, 0, 'traffic_light'),
            Tpl::w('bar_ranked', 'Rules breached most', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'rule', 'top' => 12], 0, 4, 6, 10),
            Tpl::w('pareto', '80/20: people behind the violations', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'person', 'top' => 20], 6, 4, 6, 10),
            Tpl::w('bar_stacked', 'Outcome by policy category', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'category', 'series' => 'outcome'], 0, 14, 6, 9),
            Tpl::w('funnel', 'Where violations stand', 'pol_violations', ['measures' => ['violations'], 'dimension' => 'status', 'period' => 'all'], 6, 14, 6, 9),
            Tpl::w('table', 'Violations by person', 'pol_violations', ['measures' => ['violations', 'serious', 'open', 'closed'], 'dimension' => 'person', 'top' => 50], 0, 23, 12, 10),
        ],
    ],
    'cmp_policy_attestations' => [
        'name' => 'Policy Attestations', 'group' => 'Compliance', 'icon' => 'tabler-signature',
        'description' => 'Who has read and signed each policy, who is late and how they did on the quiz.',
        'widgets' => [
            Tpl::kpi('Attestations', 'pol_attestations', ['assigned'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Signed', 'pol_attestations', ['completion_rate'], 3, 0, 'gauge', 'all'),
            Tpl::kpi('Overdue', 'pol_attestations', ['overdue'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average quiz score', 'pol_attestations', ['avg_quiz'], 9, 0, 'linear_gauge', 'all'),
            Tpl::w('bar_100', 'Sign-off status by policy', 'pol_attestations', ['measures' => ['assigned'], 'dimension' => 'policy', 'series' => 'status', 'period' => 'all'], 0, 4, 8, 9),
            Tpl::w('pie', 'Attestations by status', 'pol_attestations', ['measures' => ['assigned'], 'dimension' => 'status', 'period' => 'all'], 8, 4, 4, 9),
            Tpl::w('bar_clustered', 'Campaign progress', 'pol_attestations', ['measures' => ['completed', 'overdue'], 'dimension' => 'campaign', 'top' => 10, 'period' => 'all'], 0, 13, 6, 9),
            Tpl::w('ranking_table', 'People still to sign', 'pol_attestations', ['measures' => ['overdue', 'pending'], 'dimension' => 'person', 'top' => 25, 'period' => 'all'], 6, 13, 6, 9),
        ],
    ],
    'cmp_policy_exceptions' => [
        'name' => 'Policy Exceptions', 'group' => 'Compliance', 'icon' => 'tabler-file-alert',
        'description' => 'Requests to step outside a policy: how many, how many were allowed and which are live.',
        'widgets' => [
            Tpl::kpi('Exception requests', 'pol_exceptions', ['requests', 'growth_pct'], 0),
            Tpl::kpi('Approved', 'pol_exceptions', ['approval_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Awaiting a decision', 'pol_exceptions', ['pending'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('In force now', 'pol_exceptions', ['in_force'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('column_stacked', 'Requests by month and status', 'pol_exceptions', ['measures' => ['requests'], 'series' => 'status', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Requests by status', 'pol_exceptions', ['measures' => ['requests'], 'dimension' => 'status'], 8, 4, 4, 9),
            Tpl::w('bar_ranked', 'Policies with the most exceptions', 'pol_exceptions', ['measures' => ['requests'], 'dimension' => 'policy', 'top' => 10], 0, 13, 6, 9),
            Tpl::w('lollipop', 'Who asks for exceptions', 'pol_exceptions', ['measures' => ['requests'], 'dimension' => 'requester', 'top' => 10], 6, 13, 6, 9),
        ],
    ],

    // ── Compliance: POPIA ──────────────────────────────────────────────────────────────────────────
    'cmp_popia_overview' => [
        'name' => 'POPIA Compliance', 'group' => 'Compliance', 'icon' => 'tabler-lock-access',
        'description' => 'Data breaches, data-subject requests and consent in one place for the Information Officer.',
        'widgets' => [
            Tpl::kpi('Open breaches', 'popia_breaches', ['open'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Records affected', 'popia_breaches', ['records', 'growth_pct'], 3),
            Tpl::kpi('Requests answered on time', 'popia_dsr', ['on_time_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Consent granted', 'popia_consents', ['grant_rate'], 9, 0, 'progress', 'all'),
            Tpl::w('column_clustered', 'Breaches by severity', 'popia_breaches', ['measures' => ['incidents', 'open'], 'dimension' => 'severity'], 0, 4, 6, 9),
            Tpl::w('bar_stacked', 'Consent by purpose', 'popia_consents', ['measures' => ['consents'], 'dimension' => 'purpose', 'series' => 'status', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('line_multi', 'Data-subject requests received vs fulfilled', 'popia_dsr', ['measures' => ['requests', 'fulfilled'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Requests by type', 'popia_dsr', ['measures' => ['requests'], 'dimension' => 'type'], 8, 13, 4, 8),
            Tpl::w('ai_insight', 'What needs attention', 'popia_breaches', ['measures' => ['incidents', 'open', 'records'], 'dimension' => 'severity'], 0, 21, 12, 5),
        ],
    ],
    'cmp_popia_breaches' => [
        'name' => 'Data Breach Register', 'group' => 'Compliance', 'icon' => 'tabler-shield-x',
        'description' => 'Every incident, how serious, how quickly it was contained and whether the Regulator was told.',
        'widgets' => [
            Tpl::kpi('Incidents', 'popia_breaches', ['incidents'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('High & critical', 'popia_breaches', ['serious'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Regulator notified', 'popia_breaches', ['notified_rate'], 6, 0, 'traffic_light', 'all'),
            Tpl::kpi('Average hours to contain', 'popia_breaches', ['avg_hours_to_contain'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('area', 'Records affected by month', 'popia_breaches', ['measures' => ['records'], 'grain' => 'month', 'period' => 'last_24_months'], 0, 4, 8, 8),
            Tpl::w('funnel', 'Where incidents stand', 'popia_breaches', ['measures' => ['incidents'], 'dimension' => 'status', 'period' => 'all'], 8, 4, 4, 8),
            Tpl::w('matrix_table', 'Severity by status', 'popia_breaches', ['measures' => ['incidents'], 'dimension' => 'severity', 'series' => 'status', 'period' => 'all'], 0, 12, 6, 8),
            Tpl::w('table', 'Incidents by owner', 'popia_breaches', ['measures' => ['incidents', 'open', 'records', 'regulator_notified'], 'dimension' => 'owner', 'period' => 'all'], 6, 12, 6, 8),
        ],
    ],
    'cmp_popia_requests' => [
        'name' => 'Data-Subject Requests', 'group' => 'Compliance', 'icon' => 'tabler-user-search',
        'description' => 'Access, correction and erasure requests: what came in, what is late and what was erased.',
        'widgets' => [
            Tpl::kpi('Requests', 'popia_dsr', ['requests', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Open', 'popia_dsr', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Overdue', 'popia_dsr', ['overdue'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average days to respond', 'popia_dsr', ['avg_days'], 9),
            Tpl::w('column_stacked', 'Requests by month and type', 'popia_dsr', ['measures' => ['requests'], 'series' => 'type', 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('pie', 'Requests by status', 'popia_dsr', ['measures' => ['requests'], 'dimension' => 'status', 'period' => 'all'], 8, 4, 4, 9),
            Tpl::w('bar_clustered', 'Records found vs erased', 'popia_dsr', ['measures' => ['records_found', 'records_erased'], 'dimension' => 'type'], 0, 13, 6, 8),
            Tpl::w('ranking_table', 'Workload by handler', 'popia_dsr', ['measures' => ['requests', 'open', 'overdue'], 'dimension' => 'handler', 'period' => 'all'], 6, 13, 6, 8),
        ],
    ],

    // ── Compliance: whistleblower ──────────────────────────────────────────────────────────────────

    // ── Governance: board & committees ─────────────────────────────────────────────────────────────

    // ── Documents ──────────────────────────────────────────────────────────────────────────────────
    'doc_overview' => [
        'name' => 'Document Management Overview', 'group' => 'Documents', 'icon' => 'tabler-files',
        'description' => 'How many documents there are, where they live, how much space they take and who owns them.',
        'widgets' => [
            Tpl::kpi('Documents', 'dm_documents', ['documents'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Added this year', 'dm_documents', ['documents', 'growth_pct'], 3, 0, 'kpi_sparkline'),
            Tpl::kpi('Storage (MB)', 'dm_documents', ['storage_mb'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Confidential', 'dm_documents', ['confidential_share'], 9, 0, 'progress', 'all'),
            Tpl::w('area', 'Documents added by month', 'dm_documents', ['measures' => ['documents'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'By confidentiality', 'dm_documents', ['measures' => ['documents'], 'dimension' => 'confidentiality', 'period' => 'all'], 8, 4, 4, 8),
            Tpl::w('treemap', 'Documents by folder and category', 'dm_documents', ['measures' => ['documents'], 'dimension' => 'folder', 'series' => 'category', 'period' => 'all'], 0, 12, 6, 9),
            Tpl::w('bar_ranked', 'Storage by file type (MB)', 'dm_documents', ['measures' => ['storage_mb'], 'dimension' => 'file_type', 'top' => 10, 'period' => 'all'], 6, 12, 6, 9),
            Tpl::w('ranking_table', 'Document owners', 'dm_documents', ['measures' => ['documents', 'versions', 'storage_mb'], 'dimension' => 'owner', 'top' => 20, 'period' => 'all'], 0, 21, 12, 9),
        ],
    ],
    'doc_retention' => [
        'name' => 'Document Expiry & Retention', 'group' => 'Documents', 'icon' => 'tabler-calendar-due',
        'description' => 'Documents about to expire, already expired, due for disposal or held for legal reasons.',
        'widgets' => [
            Tpl::kpi('Expiring in 90 days', 'dm_documents', ['expiring'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Expired', 'dm_documents', ['expired'], 3, 0, 'traffic_light', 'all'),
            Tpl::kpi('Due for disposal', 'dm_documents', ['disposal_due'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('On legal hold', 'dm_documents', ['legal_hold'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('pie', 'Expiry state', 'dm_documents', ['measures' => ['documents'], 'dimension' => 'expiry', 'period' => 'all'], 0, 4, 4, 9),
            Tpl::w('bar_stacked', 'Expiry by category', 'dm_documents', ['measures' => ['documents'], 'dimension' => 'category', 'series' => 'expiry', 'period' => 'all'], 4, 4, 8, 9),
            Tpl::w('column_clustered', 'Documents by retention rule', 'dm_documents', ['measures' => ['documents', 'archived'], 'dimension' => 'retention', 'period' => 'all'], 0, 13, 6, 8),
            Tpl::w('table', 'Department exposure', 'dm_documents', ['measures' => ['documents', 'expiring', 'expired', 'disposal_due'], 'dimension' => 'department', 'period' => 'all'], 6, 13, 6, 8),
        ],
    ],
    'doc_activity' => [
        'name' => 'Document Usage', 'group' => 'Documents', 'icon' => 'tabler-activity',
        'description' => 'Uploads, views and downloads: which documents are used and by whom.',
        'widgets' => [
            Tpl::kpi('Events this year', 'dm_activity', ['events', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Uploads', 'dm_activity', ['uploads'], 3),
            Tpl::kpi('Downloads', 'dm_activity', ['downloads'], 6),
            Tpl::kpi('Active users', 'dm_activity', ['users'], 9),
            Tpl::w('line_multi', 'Uploads vs views by week', 'dm_activity', ['measures' => ['uploads', 'views'], 'grain' => 'week', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'Activity by action', 'dm_activity', ['measures' => ['events'], 'dimension' => 'action'], 8, 4, 4, 8),
            Tpl::w('calendar_heatmap', 'Activity calendar', 'dm_activity', ['measures' => ['events'], 'period' => 'last_12_months'], 0, 12, 12, 7),
            Tpl::w('bar_ranked', 'Most active users', 'dm_activity', ['measures' => ['events'], 'dimension' => 'user', 'top' => 10], 0, 19, 6, 9),
            Tpl::w('bar_ranked', 'Busiest folders', 'dm_activity', ['measures' => ['events'], 'dimension' => 'folder', 'top' => 10], 6, 19, 6, 9),
        ],
    ],
    'doc_resources' => [
        'name' => 'Resource Library', 'group' => 'Documents', 'icon' => 'tabler-books',
        'description' => 'Forms, handbooks, SOPs and templates published for staff: what is live and what is expiring.',
        'widgets' => [
            Tpl::kpi('Resources', 'res_library', ['resources'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Live', 'res_library', ['live_rate'], 3, 0, 'gauge', 'all'),
            Tpl::kpi('Internal only', 'res_library', ['internal'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Expiring in 30 days', 'res_library', ['expiring'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_ranked', 'Resources by type', 'res_library', ['measures' => ['resources'], 'dimension' => 'type', 'period' => 'all'], 0, 4, 6, 9),
            Tpl::w('sunburst', 'Type by audience', 'res_library', ['measures' => ['resources'], 'dimension' => 'audience', 'series' => 'type', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('column_simple', 'Published by month', 'res_library', ['measures' => ['resources'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('pie', 'Live, hidden and expired', 'res_library', ['measures' => ['resources'], 'dimension' => 'state', 'period' => 'all'], 8, 13, 4, 8),
        ],
    ],

    // ── Workflow ───────────────────────────────────────────────────────────────────────────────────
    'wf_throughput' => [
        'name' => 'Workflow Throughput', 'group' => 'Workflow', 'icon' => 'tabler-git-merge',
        'description' => 'Cases started and finished by process, how long they take and how many are approved.',
        'widgets' => [
            Tpl::kpi('Cases started', 'wf_instances', ['instances', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('In progress', 'wf_instances', ['running'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Approved', 'wf_instances', ['approval_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Average hours start to finish', 'wf_instances', ['avg_hours'], 9),
            Tpl::w('stacked_column_line', 'Started and finished by week', 'wf_instances', ['measures' => ['instances', 'completed'], 'grain' => 'week', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Cases by outcome', 'wf_instances', ['measures' => ['instances'], 'dimension' => 'outcome'], 8, 4, 4, 9),
            Tpl::w('bar_stacked', 'Status by process', 'wf_instances', ['measures' => ['instances'], 'dimension' => 'process', 'series' => 'status', 'top' => 12], 0, 13, 7, 10),
            Tpl::w('bar_ranked', 'Slowest processes (hours)', 'wf_instances', ['measures' => ['avg_hours'], 'dimension' => 'process', 'top' => 10], 7, 13, 5, 10),
            Tpl::w('table', 'Process scorecard', 'wf_instances', ['measures' => ['instances', 'running', 'approved', 'rejected', 'avg_hours', 'approval_rate'], 'dimension' => 'process', 'top' => 50], 0, 23, 12, 10),
        ],
    ],
    'wf_bottlenecks' => [
        'name' => 'Workflow Bottlenecks', 'group' => 'Workflow', 'icon' => 'tabler-hourglass',
        'description' => 'Where work waits: steps and people with the longest queues, overdue tasks and SLA breaches.',
        'widgets' => [
            Tpl::kpi('Tasks waiting', 'wf_tasks', ['open'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Overdue', 'wf_tasks', ['overdue'], 3, 0, 'traffic_light', 'all'),
            Tpl::kpi('SLA breached', 'wf_tasks', ['breached'], 6, 0, 'kpi_card', 'all'),
            Tpl::kpi('Average hours waiting', 'wf_tasks', ['avg_open_hours'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('bar_ranked', 'Steps with the longest queue', 'wf_tasks', ['measures' => ['open'], 'dimension' => 'step', 'top' => 12, 'period' => 'all'], 0, 4, 6, 10),
            Tpl::w('pareto', '80/20: who holds the waiting work', 'wf_tasks', ['measures' => ['open'], 'dimension' => 'assignee', 'top' => 20, 'period' => 'all'], 6, 4, 6, 10),
            Tpl::w('heatmap_matrix', 'Waiting work: process by task type', 'wf_tasks', ['measures' => ['open'], 'dimension' => 'process', 'series' => 'type', 'period' => 'all'], 0, 14, 6, 9),
            Tpl::w('lollipop', 'Average hours to act by step', 'wf_tasks', ['measures' => ['avg_hours'], 'dimension' => 'step', 'top' => 12], 6, 14, 6, 9),
            Tpl::w('ranking_table', 'Assignee queue', 'wf_tasks', ['measures' => ['open', 'overdue', 'avg_open_hours'], 'dimension' => 'assignee', 'top' => 30, 'period' => 'all'], 0, 23, 12, 9),
        ],
    ],
    'wf_approvals' => [
        'name' => 'Approvals Turnaround', 'group' => 'Workflow', 'icon' => 'tabler-checks',
        'description' => 'How quickly approvers act, how often they approve and which approvers slow things down.',
        'widgets' => [
            Tpl::kpi('Decisions made', 'wf_tasks', ['completed', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Approved', 'wf_tasks', ['approval_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Rejected or returned', 'wf_tasks', ['rejected'], 6),
            Tpl::kpi('Average hours to act', 'wf_tasks', ['avg_hours'], 9, 0, 'traffic_light'),
            Tpl::w('moving_average', 'Hours to act, trend', 'wf_tasks', ['measures' => ['avg_hours'], 'grain' => 'week', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('pie', 'Decisions', 'wf_tasks', ['measures' => ['completed'], 'dimension' => 'decision'], 8, 4, 4, 8),
            Tpl::w('bar_clustered', 'Approved vs rejected by process', 'wf_tasks', ['measures' => ['approved', 'rejected'], 'dimension' => 'process', 'top' => 10], 0, 12, 7, 9),
            Tpl::w('diverging_bar', 'Approvers: hours to act', 'wf_tasks', ['measures' => ['avg_hours'], 'dimension' => 'assignee', 'top' => 12], 7, 12, 5, 9),
            Tpl::w('table', 'Approver scorecard', 'wf_tasks', ['measures' => ['tasks', 'completed', 'approved', 'rejected', 'avg_hours'], 'dimension' => 'assignee', 'top' => 50], 0, 21, 12, 10),
        ],
    ],

    // ── Tax & finance executive ────────────────────────────────────────────────────────────────────
    'tax_finance_exec' => [
        'name' => 'Finance Executive Scorecard', 'group' => 'Tax', 'icon' => 'tabler-chart-arrows-vertical',
        'description' => 'Revenue, margins and profit against budget, with what tax the business still owes.',
        'widgets' => [
            Tpl::kpi('Revenue', 'fx_pnl', ['revenue', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Gross margin', 'fx_pnl', ['gross_margin'], 3, 0, 'gauge'),
            Tpl::kpi('Net profit', 'fx_pnl', ['net_profit', 'growth_pct'], 6, 0, 'variance_card'),
            Tpl::kpi('Revenue vs budget', 'fx_budget', ['revenue_achieved'], 9, 0, 'progress'),
            Tpl::w('combo_column_line', 'Revenue vs net profit by month', 'fx_pnl', ['measures' => ['revenue', 'net_profit'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 9),
            Tpl::w('donut', 'Where the money goes', 'fx_pnl', ['measures' => ['expenses'], 'dimension' => 'section'], 8, 4, 4, 9),
            Tpl::w('line_multi', 'Actual vs budget by month', 'fx_budget', ['measures' => ['actual_income', 'budget_income', 'actual_expense', 'budget_expense'], 'grain' => 'month'], 0, 13, 8, 9),
            Tpl::w('multi_kpi', 'Margins', 'fx_pnl', ['measures' => ['gross_margin', 'net_margin', 'cost_ratio']], 8, 13, 4, 9),
            Tpl::w('diverging_bar', 'Over / under budget by account', 'fx_budget', ['measures' => ['variance'], 'dimension' => 'account', 'top' => 12], 0, 22, 6, 9),
            Tpl::w('variance_table', 'P&L this year vs last year', 'fx_pnl', ['measures' => ['revenue', 'last_year', 'growth_pct'], 'grain' => 'month'], 6, 22, 6, 9),
            Tpl::w('ai_insight', 'What the numbers say', 'fx_pnl', ['measures' => ['revenue', 'gross_profit', 'net_profit'], 'dimension' => 'account'], 0, 31, 12, 5),
        ],
    ],
    'tax_budget' => [
        'name' => 'Budget vs Actual', 'group' => 'Tax', 'icon' => 'tabler-scale',
        'description' => 'How actual income and spend compare with the approved budget, account by account.',
        'widgets' => [
            Tpl::kpi('Budget', 'fx_budget', ['budget'], 0),
            Tpl::kpi('Actual', 'fx_budget', ['actual'], 3),
            Tpl::kpi('Expense budget used', 'fx_budget', ['spend_used'], 6, 0, 'gauge'),
            Tpl::kpi('Revenue vs budget', 'fx_budget', ['revenue_achieved'], 9, 0, 'linear_gauge'),
            Tpl::w('dual_axis', 'Actual vs budget by month', 'fx_budget', ['measures' => ['actual', 'budget'], 'grain' => 'month'], 0, 4, 12, 8),
            Tpl::w('bar_clustered', 'Budget vs actual by account', 'fx_budget', ['measures' => ['budget', 'actual'], 'dimension' => 'account', 'top' => 12], 0, 12, 7, 10),
            Tpl::w('column_clustered', 'By account group', 'fx_budget', ['measures' => ['budget', 'actual'], 'dimension' => 'group'], 7, 12, 5, 10),
            Tpl::w('table', 'Variance by account', 'fx_budget', ['measures' => ['budget', 'actual', 'variance', 'achieved'], 'dimension' => 'account', 'top' => 50], 0, 22, 12, 10),
        ],
    ],
];
