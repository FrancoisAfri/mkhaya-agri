<?php

use App\Modules\BI\Support\Tpl;

// Customer, service and marketing templates — CRM, Help Desk, Surveys, Conferences, Content,
// AI Campaign Manager, AI Studio and E-Signature. Datasets live in Datasets/customer.php.

return [
    // ── Sales & CRM ─────────────────────────────────────────────────────────────────────────────
    'crm_pipeline' => [
        'name' => 'Sales Pipeline', 'group' => 'Sales & CRM', 'icon' => 'tabler-filter',
        'description' => 'Every open deal by stage, its value and who owns it — the pipeline as it stands today.',
        'widgets' => [
            Tpl::kpi('Open pipeline', 'crm_deals', ['open_value'], 0, 0, 'kpi_card', 'all'),
            Tpl::kpi('Weighted pipeline', 'crm_deals', ['weighted_value'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Open deals', 'crm_deals', ['open_deals', 'on_hold'], 6, 0, 'multi_kpi', 'all'),
            Tpl::kpi('Win rate this year', 'crm_closed_deals', ['win_rate'], 9, 0, 'gauge'),
            Tpl::w('funnel', 'Open value by stage', 'crm_forecast', ['measures' => ['value'], 'dimension' => 'stage', 'period' => 'all', 'sort' => 'desc'], 0, 4, 6, 9),
            Tpl::w('bar_ranked', 'Open pipeline by owner', 'crm_forecast', ['measures' => ['value'], 'dimension' => 'owner', 'top' => 10, 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('bar_stacked', 'Open deals by owner and stage', 'crm_forecast', ['measures' => ['deals'], 'dimension' => 'owner', 'series' => 'stage', 'period' => 'all'], 0, 13, 6, 8),
            Tpl::w('donut', 'Open deals by risk', 'crm_forecast', ['measures' => ['value'], 'dimension' => 'risk', 'period' => 'all'], 6, 13, 6, 8),
            Tpl::w('table', 'Open pipeline by account', 'crm_forecast', ['measures' => ['deals', 'value', 'weighted', 'overdue'], 'dimension' => 'account', 'top' => 50, 'period' => 'all'], 0, 21, 12, 10),
        ],
    ],
    'crm_forecast' => [
        'name' => 'Sales Forecast & Won Revenue', 'group' => 'Sales & CRM', 'icon' => 'tabler-chart-arrows-vertical',
        'description' => 'What has been won, how it compares with last year and what the pipeline says is coming.',
        'widgets' => [
            Tpl::kpi('Won revenue', 'crm_closed_deals', ['won_value', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Deals won', 'crm_closed_deals', ['won'], 3),
            Tpl::kpi('Average won deal', 'crm_closed_deals', ['avg_won'], 6),
            Tpl::kpi('Weighted forecast', 'crm_forecast', ['weighted'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('combo_column_line', 'Won revenue by month vs last year', 'crm_closed_deals', ['measures' => ['won_value', 'last_year'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 12, 8),
            Tpl::w('column_stacked', 'Forecast by expected close month', 'crm_forecast', ['measures' => ['weighted'], 'series' => 'stage', 'grain' => 'month', 'period' => 'all'], 0, 12, 6, 9),
            Tpl::w('line_multi', 'Pipeline vs weighted vs AI forecast', 'crm_forecast', ['measures' => ['value', 'weighted', 'ai_weighted'], 'grain' => 'quarter', 'period' => 'all'], 6, 12, 6, 9),
            Tpl::w('bar_ranked', 'Won revenue by owner', 'crm_closed_deals', ['measures' => ['won_value'], 'dimension' => 'owner', 'top' => 10], 0, 21, 6, 8),
            Tpl::w('running_total', 'Won revenue so far this year', 'crm_closed_deals', ['measures' => ['won_value'], 'grain' => 'month'], 6, 21, 6, 8),
            Tpl::w('smart_narrative', 'In short', 'crm_closed_deals', ['measures' => ['won_value', 'win_rate', 'avg_cycle'], 'dimension' => 'owner'], 0, 29, 12, 5),
        ],
    ],
    'crm_win_loss' => [
        'name' => 'Win / Loss Analysis', 'group' => 'Sales & CRM', 'icon' => 'tabler-trophy',
        'description' => 'Why deals are won or lost, how long they take to close and which reps convert best.',
        'widgets' => [
            Tpl::kpi('Win rate', 'crm_closed_deals', ['win_rate'], 0, 0, 'gauge'),
            Tpl::kpi('Deals won / lost', 'crm_closed_deals', ['won', 'lost'], 3, 0, 'multi_kpi'),
            Tpl::kpi('Value lost', 'crm_closed_deals', ['lost_value'], 6),
            Tpl::kpi('Average days to close', 'crm_closed_deals', ['avg_cycle'], 9),
            Tpl::w('donut', 'Won vs lost', 'crm_closed_deals', ['measures' => ['closed'], 'dimension' => 'outcome'], 0, 4, 4, 9),
            Tpl::w('bar_ranked', 'Why deals are lost', 'crm_closed_deals', ['measures' => ['lost'], 'dimension' => 'lost_reason', 'top' => 10], 4, 4, 8, 9),
            Tpl::w('bar_100', 'Outcome by owner', 'crm_closed_deals', ['measures' => ['closed'], 'dimension' => 'owner', 'series' => 'outcome'], 0, 13, 6, 9),
            Tpl::w('bar_100', 'Outcome by source', 'crm_closed_deals', ['measures' => ['closed'], 'dimension' => 'source', 'series' => 'outcome'], 6, 13, 6, 9),
            Tpl::w('column_stacked', 'Won and lost by month', 'crm_closed_deals', ['measures' => ['closed'], 'series' => 'outcome', 'grain' => 'month', 'period' => 'last_12_months'], 0, 22, 12, 8),
        ],
    ],
    'crm_leads' => [
        'name' => 'Lead Sources & Conversion', 'group' => 'Sales & CRM', 'icon' => 'tabler-user-plus',
        'description' => 'Where leads come from, how many get qualified and how many turn into customers.',
        'widgets' => [
            Tpl::kpi('New leads', 'crm_leads', ['leads', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Qualified', 'crm_leads', ['qualified', 'qualify_rate'], 3, 0, 'multi_kpi'),
            Tpl::kpi('Conversion rate', 'crm_leads', ['conversion_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Not yet contacted', 'crm_leads', ['untouched'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('funnel', 'Lead funnel', 'crm_leads', ['measures' => ['leads'], 'dimension' => 'status'], 0, 4, 4, 9),
            Tpl::w('donut', 'Leads by source', 'crm_leads', ['measures' => ['leads'], 'dimension' => 'source', 'top' => 8, 'other' => true], 4, 4, 4, 9),
            Tpl::w('bar_ranked', 'Conversion rate by source', 'crm_leads', ['measures' => ['conversion_rate'], 'dimension' => 'source', 'top' => 10], 8, 4, 4, 9),
            Tpl::w('column_stacked', 'Leads by month and source', 'crm_leads', ['measures' => ['leads'], 'series' => 'source', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('heatmap_matrix', 'Source by status', 'crm_leads', ['measures' => ['leads'], 'dimension' => 'source', 'series' => 'status'], 8, 13, 4, 8),
            Tpl::w('table', 'Leads by owner', 'crm_leads', ['measures' => ['leads', 'qualified', 'converted', 'conversion_rate', 'avg_score'], 'dimension' => 'owner', 'top' => 30], 0, 21, 12, 9),
        ],
    ],
    'crm_activities' => [
        'name' => 'Sales Activities by Rep', 'group' => 'Sales & CRM', 'icon' => 'tabler-phone-call',
        'description' => 'Calls, meetings, tasks and notes per rep — what got done and what is overdue.',
        'widgets' => [
            Tpl::kpi('Activities', 'crm_activities', ['activities', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Completed', 'crm_activities', ['completed'], 3),
            Tpl::kpi('Completion rate', 'crm_activities', ['completion_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Overdue', 'crm_activities', ['overdue'], 9, 0, 'traffic_light', 'all'),
            Tpl::w('bar_stacked', 'Activities by rep and type', 'crm_activities', ['measures' => ['activities'], 'dimension' => 'owner', 'series' => 'type'], 0, 4, 8, 9),
            Tpl::w('donut', 'Activity mix', 'crm_activities', ['measures' => ['activities'], 'dimension' => 'type'], 8, 4, 4, 9),
            Tpl::w('column_stacked', 'Activities by week', 'crm_activities', ['measures' => ['activities'], 'series' => 'type', 'grain' => 'week', 'period' => 'last_12_months'], 0, 13, 12, 8),
            Tpl::w('ranking_table', 'Rep scorecard', 'crm_activities', ['measures' => ['activities', 'completed', 'overdue', 'completion_rate'], 'dimension' => 'owner', 'top' => 30], 0, 21, 12, 9),
        ],
    ],

    // ── Customer Service ────────────────────────────────────────────────────────────────────────
    'srv_complaints' => [
        'name' => 'Complaints Management', 'group' => 'Customer Service', 'icon' => 'tabler-message-report',
        'description' => 'Customer complaints by category and severity, how fast they are resolved and what escalates.',
        'widgets' => [
            Tpl::kpi('Complaints', 'srv_complaints', ['complaints', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Still open', 'srv_complaints', ['open'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Escalated', 'srv_complaints', ['escalated'], 6, 0, 'traffic_light'),
            Tpl::kpi('Avg hours to resolve', 'srv_complaints', ['avg_resolution'], 9),
            Tpl::w('bar_ranked', 'Complaints by category', 'srv_complaints', ['measures' => ['complaints'], 'dimension' => 'category', 'top' => 10], 0, 4, 6, 9),
            Tpl::w('funnel', 'Complaints by status', 'srv_complaints', ['measures' => ['complaints'], 'dimension' => 'status', 'period' => 'all'], 6, 4, 6, 9),
            Tpl::w('column_stacked', 'Complaints by month and severity', 'srv_complaints', ['measures' => ['complaints'], 'series' => 'severity', 'grain' => 'month', 'period' => 'last_12_months'], 0, 13, 8, 8),
            Tpl::w('donut', 'Complaint sentiment', 'srv_complaints', ['measures' => ['complaints'], 'dimension' => 'sentiment'], 8, 13, 4, 8),
            Tpl::w('table', 'Complaints by handler', 'srv_complaints', ['measures' => ['complaints', 'resolved', 'late', 'avg_resolution'], 'dimension' => 'assignee', 'top' => 30], 0, 21, 12, 9),
        ],
    ],

    // ── Engagement ──────────────────────────────────────────────────────────────────────────────
    'esg_turnaround' => [
        'name' => 'E-Signature Turnaround', 'group' => 'Engagement', 'icon' => 'tabler-signature',
        'description' => 'How many documents go out for signature, how fast they come back and what is still waiting.',
        'widgets' => [
            Tpl::kpi('Envelopes sent', 'esg_envelopes', ['envelopes', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Completion rate', 'esg_envelopes', ['completion_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Avg hours to complete', 'esg_envelopes', ['avg_turnaround'], 6),
            Tpl::kpi('Awaiting signatures', 'esg_envelopes', ['pending'], 9, 0, 'kpi_card', 'all'),
            Tpl::w('combo_column_line', 'Envelopes and completions by month', 'esg_envelopes', ['measures' => ['envelopes', 'completed'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'Envelopes by status', 'esg_envelopes', ['measures' => ['envelopes'], 'dimension' => 'status', 'period' => 'all'], 8, 4, 4, 8),
            Tpl::w('bar_ranked', 'Avg hours to complete by document type', 'esg_envelopes', ['measures' => ['avg_turnaround'], 'dimension' => 'document_type', 'top' => 10], 0, 12, 6, 8),
            Tpl::w('bar_100', 'Signer status by role', 'esg_signers', ['measures' => ['signers'], 'dimension' => 'role', 'series' => 'status'], 6, 12, 6, 8),
            Tpl::w('table', 'Envelopes by sender', 'esg_envelopes', ['measures' => ['envelopes', 'completed', 'pending', 'avg_turnaround'], 'dimension' => 'sender', 'top' => 30], 0, 20, 12, 9),
        ],
    ],
    'cms_library' => [
        'name' => 'Content Library', 'group' => 'Engagement', 'icon' => 'tabler-files',
        'description' => 'Intranet content published by type and author, and how much of it is still current.',
        'widgets' => [
            Tpl::kpi('Content items', 'cms_contents', ['items', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Active', 'cms_contents', ['active'], 3, 0, 'kpi_card', 'all'),
            Tpl::kpi('Active share', 'cms_contents', ['active_pct'], 6, 0, 'progress', 'all'),
            Tpl::kpi('Authors', 'cms_contents', ['authors'], 9),
            Tpl::w('column_simple', 'Content published by month', 'cms_contents', ['measures' => ['items'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'Content by type', 'cms_contents', ['measures' => ['items'], 'dimension' => 'type', 'period' => 'all'], 8, 4, 4, 8),
            Tpl::w('bar_ranked', 'Top authors', 'cms_contents', ['measures' => ['items'], 'dimension' => 'author', 'top' => 10], 0, 12, 12, 8),
        ],
    ],

    // ── Marketing ───────────────────────────────────────────────────────────────────────────────
    'mkt_email_campaigns' => [
        'name' => 'E-mail & WhatsApp Campaigns', 'group' => 'Marketing', 'icon' => 'tabler-mail-opened',
        'description' => 'Messages sent, opened and clicked across CRM campaigns, and which campaigns land best.',
        'widgets' => [
            Tpl::kpi('Messages sent', 'crm_email_campaigns', ['sent', 'growth_pct'], 0, 0, 'kpi_sparkline'),
            Tpl::kpi('Open rate', 'crm_email_campaigns', ['open_rate'], 3, 0, 'gauge'),
            Tpl::kpi('Click rate', 'crm_email_campaigns', ['click_rate'], 6, 0, 'gauge'),
            Tpl::kpi('Bounce rate', 'crm_email_campaigns', ['bounce_rate'], 9, 0, 'traffic_light'),
            Tpl::w('combo_column_line', 'Sent vs opened by month', 'crm_email_campaigns', ['measures' => ['sent', 'opened'], 'grain' => 'month', 'period' => 'last_12_months'], 0, 4, 8, 8),
            Tpl::w('donut', 'Messages by channel', 'crm_email_campaigns', ['measures' => ['sent'], 'dimension' => 'channel'], 8, 4, 4, 8),
            Tpl::w('funnel', 'Recipients to clicks', 'crm_email_campaigns', ['measures' => ['recipients', 'sent', 'opened', 'clicked'], 'dimension' => 'channel'], 0, 12, 4, 9),
            Tpl::w('table', 'Campaign results', 'crm_email_campaigns', ['measures' => ['sent', 'opened', 'clicked', 'open_rate', 'click_rate', 'unsubscribed'], 'dimension' => 'campaign', 'top' => 30], 4, 12, 8, 9),
        ],
    ],
];
