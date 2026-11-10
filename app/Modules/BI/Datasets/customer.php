<?php

// Customer, service and marketing — CRM, Help Desk, Surveys, Conferences, Content Management,
// Content Marketing, AI Campaign Manager, AI Studio and E-Signature. See DatasetRegistry for the format.

// A person's readable name from a user id: HR name, else e-mail, else the fallback.
$person = fn (string $col, string $none = 'Unassigned') => "coalesce((select nullif(trim(coalesce(hp.first_name, '') || ' ' || coalesce(hp.surname, '')), '') from hr_people hp where hp.user_id = {$col} order by hp.id limit 1), (select uu.email from users uu where uu.id = {$col}), '{$none}')";
$label = fn (string $sql, string $none = 'Unknown') => "coalesce(nullif(initcap(replace({$sql}, '_', ' ')), ''), '{$none}')";
$hours = fn (string $end, string $start) => "extract(epoch from ({$end} - {$start})) / 3600.0";

$dealDims = [
    'stage' => ['label' => 'Stage', 'sql' => "coalesce(st.name, 'No stage')"],
    'pipeline' => ['label' => 'Pipeline', 'sql' => "coalesce(pl.name, 'No pipeline')"],
    'status' => ['label' => 'Status', 'sql' => $label('d.status')],
    'owner' => ['label' => 'Owner', 'sql' => $person('d.owner_id')],
    'account' => ['label' => 'Account', 'sql' => "coalesce(a.name, 'No account')"],
    'industry' => ['label' => 'Industry', 'sql' => "coalesce(nullif(a.industry, ''), 'Unknown')"],
    'source' => ['label' => 'Source', 'sql' => "coalesce(nullif(d.source, ''), 'Unknown')"],
    'risk' => ['label' => 'Risk level', 'sql' => $label('d.risk_level', 'Not assessed')],
];

$ticketFrom = 'hd_tickets t left join hd_statuses s on s.id = t.status_id left join hd_priorities p on p.id = t.priority_id '
    . 'left join hd_categories c on c.id = t.category_id left join hd_inboxes ib on ib.id = t.inbox_id';
$ticketOpen = 't.resolved_at is null and t.closed_at is null and not coalesce(s.is_closed, false)';

return [
    // ── CRM ─────────────────────────────────────────────────────────────────────────────────────
    'crm_leads' => [
        'module' => 'crm',
        'label' => 'CRM leads',
        'from' => 'crm_leads l',
        'company' => 'l.company_id',
        'date' => 'l.created_at',
        'where' => 'l.deleted_at is null',
        'measures' => [
            'leads' => ['label' => 'Leads', 'sql' => 'count(*)', 'format' => 'number'],
            'qualified' => ['label' => 'Qualified', 'sql' => "count(*) filter (where l.status in ('qualified', 'converted'))", 'format' => 'number'],
            'converted' => ['label' => 'Converted', 'sql' => "count(*) filter (where l.status = 'converted' or l.converted_deal_id is not null or l.converted_account_id is not null)", 'format' => 'number'],
            'unqualified' => ['label' => 'Unqualified', 'sql' => "count(*) filter (where l.status = 'unqualified')", 'format' => 'number'],
            'untouched' => ['label' => 'Not yet contacted', 'sql' => "count(*) filter (where l.status = 'new')", 'format' => 'number'],
            'score_total' => ['label' => 'Total lead score', 'sql' => 'coalesce(sum(l.score), 0)', 'format' => 'number'],
            'scored' => ['label' => 'Scored leads', 'sql' => 'count(l.score)', 'format' => 'number'],
            'avg_score' => ['label' => 'Average lead score', 'ratio' => ['score_total', 'scored'], 'format' => 'number'],
            'conversion_rate' => ['label' => 'Conversion rate %', 'ratio' => ['converted', 'leads'], 'scale' => 100, 'format' => 'percent'],
            'qualify_rate' => ['label' => 'Qualified %', 'ratio' => ['qualified', 'leads'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $label('l.status')],
            'source' => ['label' => 'Source', 'sql' => "coalesce(nullif(l.source, ''), 'Unknown')"],
            'owner' => ['label' => 'Owner', 'sql' => $person('l.owner_id')],
            'from_campaign' => ['label' => 'From an ad campaign?', 'sql' => "case when l.marketing_campaign_id is not null then 'Ad campaign' else 'Other' end"],
        ],
    ],
    'crm_deals' => [
        'module' => 'crm',
        'label' => 'CRM deals (by date created)',
        'from' => 'crm_deals d left join crm_stages st on st.id = d.stage_id left join crm_pipelines pl on pl.id = d.pipeline_id left join crm_accounts a on a.id = d.account_id',
        'company' => 'd.company_id',
        'date' => 'd.created_at',
        'where' => 'd.deleted_at is null',
        'measures' => [
            'deals' => ['label' => 'Deals', 'sql' => 'count(*)', 'format' => 'number'],
            'value' => ['label' => 'Deal value', 'sql' => 'coalesce(sum(d.amount), 0)', 'format' => 'currency'],
            'open_deals' => ['label' => 'Open deals', 'sql' => "count(*) filter (where d.status in ('open', 'on_hold'))", 'format' => 'number'],
            'open_value' => ['label' => 'Open pipeline value', 'sql' => "coalesce(sum(d.amount) filter (where d.status in ('open', 'on_hold')), 0)", 'format' => 'currency'],
            'weighted_value' => ['label' => 'Weighted pipeline', 'sql' => "coalesce(sum(d.amount * coalesce(d.probability, st.probability, 0) / 100.0) filter (where d.status in ('open', 'on_hold')), 0)", 'format' => 'currency'],
            'on_hold' => ['label' => 'On hold', 'sql' => "count(*) filter (where d.status = 'on_hold')", 'format' => 'number'],
            'won_deals' => ['label' => 'Won deals', 'sql' => "count(*) filter (where d.status = 'won')", 'format' => 'number'],
            'won_value' => ['label' => 'Won value', 'sql' => "coalesce(sum(d.amount) filter (where d.status = 'won'), 0)", 'format' => 'currency'],
            'high_risk' => ['label' => 'High-risk open deals', 'sql' => "count(*) filter (where d.status in ('open', 'on_hold') and d.risk_level = 'high')", 'format' => 'number'],
            'avg_deal' => ['label' => 'Average deal size', 'ratio' => ['value', 'deals'], 'format' => 'currency'],
        ],
        'dimensions' => $dealDims,
    ],
    'crm_closed_deals' => [
        'module' => 'crm',
        'label' => 'Closed deals (won / lost)',
        'from' => 'crm_deals d left join crm_stages st on st.id = d.stage_id left join crm_pipelines pl on pl.id = d.pipeline_id left join crm_accounts a on a.id = d.account_id',
        'company' => 'd.company_id',
        'date' => 'coalesce(d.closed_at, d.updated_at)',
        'where' => "d.deleted_at is null and d.status in ('won', 'lost')",
        'measures' => [
            'closed' => ['label' => 'Closed deals', 'sql' => 'count(*)', 'format' => 'number'],
            'won' => ['label' => 'Won', 'sql' => "count(*) filter (where d.status = 'won')", 'format' => 'number'],
            'lost' => ['label' => 'Lost', 'sql' => "count(*) filter (where d.status = 'lost')", 'format' => 'number'],
            'won_value' => ['label' => 'Won revenue', 'sql' => "coalesce(sum(d.amount) filter (where d.status = 'won'), 0)", 'format' => 'currency'],
            'lost_value' => ['label' => 'Lost value', 'sql' => "coalesce(sum(d.amount) filter (where d.status = 'lost'), 0)", 'format' => 'currency'],
            'cycle_days' => ['label' => 'Total days to close', 'sql' => 'coalesce(sum(extract(epoch from (coalesce(d.closed_at, d.updated_at) - d.created_at)) / 86400.0), 0)', 'format' => 'number'],
            'win_rate' => ['label' => 'Win rate %', 'ratio' => ['won', 'closed'], 'scale' => 100, 'format' => 'percent'],
            'avg_won' => ['label' => 'Average won deal', 'ratio' => ['won_value', 'won'], 'format' => 'currency'],
            'avg_cycle' => ['label' => 'Average days to close', 'ratio' => ['cycle_days', 'closed'], 'format' => 'number'],
        ],
        'dimensions' => $dealDims + [
            'outcome' => ['label' => 'Outcome', 'sql' => "case when d.status = 'won' then 'Won' else 'Lost' end"],
            'lost_reason' => ['label' => 'Lost reason', 'sql' => "case when d.status = 'won' then 'Won' else coalesce(nullif(d.lost_category, ''), nullif(d.lost_reason, ''), 'Not given') end"],
        ],
    ],
    'crm_forecast' => [
        'module' => 'crm',
        'label' => 'Open deals by expected close',
        'from' => 'crm_deals d left join crm_stages st on st.id = d.stage_id left join crm_pipelines pl on pl.id = d.pipeline_id left join crm_accounts a on a.id = d.account_id',
        'company' => 'd.company_id',
        'date' => 'coalesce(d.expected_close_date, d.created_at::date)',
        'where' => "d.deleted_at is null and d.status in ('open', 'on_hold')",
        'measures' => [
            'deals' => ['label' => 'Open deals', 'sql' => 'count(*)', 'format' => 'number'],
            'value' => ['label' => 'Pipeline value', 'sql' => 'coalesce(sum(d.amount), 0)', 'format' => 'currency'],
            'weighted' => ['label' => 'Weighted forecast', 'sql' => 'coalesce(sum(d.amount * coalesce(d.probability, st.probability, 0) / 100.0), 0)', 'format' => 'currency'],
            'ai_weighted' => ['label' => 'AI-weighted forecast', 'sql' => 'coalesce(sum(d.amount * coalesce(d.ai_win_probability, d.probability, st.probability, 0) / 100.0), 0)', 'format' => 'currency'],
            'overdue' => ['label' => 'Past expected close', 'sql' => 'count(*) filter (where d.expected_close_date < current_date)', 'format' => 'number'],
        ],
        'dimensions' => $dealDims,
    ],
    'crm_activities' => [
        'module' => 'crm',
        'label' => 'CRM activities',
        'from' => 'crm_activities ac',
        'company' => 'ac.company_id',
        'date' => 'coalesce(ac.due_at, ac.created_at)',
        'measures' => [
            'activities' => ['label' => 'Activities', 'sql' => 'count(*)', 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where ac.completed_at is not null or ac.status in ('done', 'completed'))", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where ac.completed_at is null and ac.status not in ('done', 'completed', 'cancelled') and ac.due_at < now())", 'format' => 'number'],
            'open' => ['label' => 'Still to do', 'sql' => "count(*) filter (where ac.completed_at is null and ac.status not in ('done', 'completed', 'cancelled'))", 'format' => 'number'],
            'completion_rate' => ['label' => 'Completion rate %', 'ratio' => ['completed', 'activities'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'type' => ['label' => 'Type', 'sql' => $label('ac.type')],
            'status' => ['label' => 'Status', 'sql' => $label('ac.status')],
            'owner' => ['label' => 'Rep', 'sql' => $person('coalesce(ac.owner_id, ac.created_by)')],
            'related' => ['label' => 'Linked to', 'sql' => "coalesce(nullif(regexp_replace(coalesce(ac.related_type, ''), '^.*\\\\', ''), ''), 'Nothing')"],
        ],
    ],
    'crm_email_campaigns' => [
        'module' => 'crm',
        'label' => 'CRM e-mail / WhatsApp campaigns',
        'from' => 'crm_campaigns cp',
        'company' => 'cp.company_id',
        'date' => 'coalesce(cp.sent_at, cp.scheduled_at, cp.created_at)',
        'where' => 'cp.deleted_at is null',
        'measures' => [
            'campaigns' => ['label' => 'Campaigns', 'sql' => 'count(*)', 'format' => 'number'],
            'recipients' => ['label' => 'Recipients', 'sql' => 'coalesce(sum(cp.total_recipients), 0)', 'format' => 'number'],
            'sent' => ['label' => 'Sent', 'sql' => 'coalesce(sum(cp.sent_count), 0)', 'format' => 'number'],
            'opened' => ['label' => 'Opened', 'sql' => 'coalesce(sum(cp.opened_count), 0)', 'format' => 'number'],
            'clicked' => ['label' => 'Clicked', 'sql' => 'coalesce(sum(cp.clicked_count), 0)', 'format' => 'number'],
            'bounced' => ['label' => 'Bounced', 'sql' => 'coalesce(sum(cp.bounced_count), 0)', 'format' => 'number'],
            'unsubscribed' => ['label' => 'Unsubscribed', 'sql' => 'coalesce(sum(cp.unsubscribed_count), 0)', 'format' => 'number'],
            'open_rate' => ['label' => 'Open rate %', 'ratio' => ['opened', 'sent'], 'scale' => 100, 'format' => 'percent'],
            'click_rate' => ['label' => 'Click rate %', 'ratio' => ['clicked', 'sent'], 'scale' => 100, 'format' => 'percent'],
            'bounce_rate' => ['label' => 'Bounce rate %', 'ratio' => ['bounced', 'sent'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'campaign' => ['label' => 'Campaign', 'sql' => "coalesce(nullif(cp.name, ''), 'Untitled')"],
            'channel' => ['label' => 'Channel', 'sql' => $label('cp.channel')],
            'status' => ['label' => 'Status', 'sql' => $label('cp.status')],
        ],
    ],

    // ── Customer service ────────────────────────────────────────────────────────────────────────
    'srv_complaints' => [
        'module' => 'crm',
        'label' => 'Customer complaints',
        'from' => 'crm_complaints cm',
        'company' => 'cm.company_id',
        'date' => 'cm.created_at',
        'where' => 'cm.deleted_at is null',
        'measures' => [
            'complaints' => ['label' => 'Complaints', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where cm.status not in ('resolved', 'closed'))", 'format' => 'number'],
            'resolved' => ['label' => 'Resolved', 'sql' => "count(*) filter (where cm.status in ('resolved', 'closed'))", 'format' => 'number'],
            'escalated' => ['label' => 'Escalated', 'sql' => "count(*) filter (where cm.escalated or cm.status = 'escalated')", 'format' => 'number'],
            'late' => ['label' => 'Past resolve-by date', 'sql' => 'count(*) filter (where cm.resolve_by is not null and coalesce(cm.resolved_at, now()) > cm.resolve_by)', 'format' => 'number'],
            'resolution_hours' => ['label' => 'Total hours to resolve', 'sql' => 'coalesce(sum(' . $hours('cm.resolved_at', 'cm.created_at') . '), 0)', 'format' => 'number'],
            'resolved_timed' => ['label' => 'Resolved with a date', 'sql' => 'count(cm.resolved_at)', 'format' => 'number'],
            'avg_resolution' => ['label' => 'Avg hours to resolve', 'ratio' => ['resolution_hours', 'resolved_timed'], 'format' => 'number'],
            'resolve_rate' => ['label' => 'Resolution rate %', 'ratio' => ['resolved', 'complaints'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $label('cm.status')],
            'category' => ['label' => 'Category', 'sql' => $label('cm.category', 'Uncategorised')],
            'severity' => ['label' => 'Severity', 'sql' => $label('cm.severity')],
            'type' => ['label' => 'Type', 'sql' => $label('cm.type')],
            'source' => ['label' => 'Source', 'sql' => $label('cm.source')],
            'sentiment' => ['label' => 'Sentiment', 'sql' => $label('cm.sentiment', 'Not assessed')],
            'assignee' => ['label' => 'Handled by', 'sql' => $person('cm.assigned_to')],
        ],
    ],
    'srv_feedback' => [
        'module' => 'crm',
        'label' => 'Customer feedback (NPS / CSAT surveys)',
        'from' => 'crm_survey_responses sr left join crm_surveys sv on sv.id = sr.survey_id',
        'company' => 'sr.company_id',
        'date' => 'coalesce(sr.submitted_at, sr.created_at)',
        'measures' => [
            'responses' => ['label' => 'Responses', 'sql' => 'count(*)', 'format' => 'number'],
            'nps_answers' => ['label' => 'NPS answers', 'sql' => 'count(sr.nps)', 'format' => 'number'],
            'promoters' => ['label' => 'Promoters (9–10)', 'sql' => 'count(*) filter (where sr.nps >= 9)', 'format' => 'number'],
            'passives' => ['label' => 'Passives (7–8)', 'sql' => 'count(*) filter (where sr.nps between 7 and 8)', 'format' => 'number'],
            'detractors' => ['label' => 'Detractors (0–6)', 'sql' => 'count(*) filter (where sr.nps <= 6)', 'format' => 'number'],
            'nps' => ['label' => 'Net Promoter Score', 'sql' => 'coalesce(round(100.0 * (count(*) filter (where sr.nps >= 9) - count(*) filter (where sr.nps <= 6)) / nullif(count(sr.nps), 0), 1), 0)', 'format' => 'number'],
            'csat_total' => ['label' => 'Total CSAT', 'sql' => 'coalesce(sum(sr.csat), 0)', 'format' => 'number'],
            'csat_answers' => ['label' => 'CSAT answers', 'sql' => 'count(sr.csat)', 'format' => 'number'],
            'avg_csat' => ['label' => 'Average CSAT', 'ratio' => ['csat_total', 'csat_answers'], 'format' => 'number'],
            'promoter_pct' => ['label' => 'Promoters %', 'ratio' => ['promoters', 'nps_answers'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'survey' => ['label' => 'Survey', 'sql' => "coalesce(sv.name, 'Unknown')"],
            'category' => ['label' => 'Survey category', 'sql' => $label('sv.category', 'Uncategorised')],
            'nps_group' => ['label' => 'NPS group', 'sql' => "case when sr.nps >= 9 then 'Promoter' when sr.nps >= 7 then 'Passive' when sr.nps is not null then 'Detractor' else 'No NPS answer' end"],
        ],
    ],

    // ── Engagement ──────────────────────────────────────────────────────────────────────────────
    'esg_envelopes' => [
        'module' => 'esign',
        'label' => 'E-signature envelopes',
        'from' => 'esign_envelopes e',
        'company' => 'e.company_id',
        'date' => 'coalesce(e.sent_at, e.created_at)',
        'where' => 'e.deleted_at is null',
        'measures' => [
            'envelopes' => ['label' => 'Envelopes', 'sql' => 'count(*)', 'format' => 'number'],
            'completed' => ['label' => 'Completed', 'sql' => "count(*) filter (where e.status = 'completed')", 'format' => 'number'],
            'pending' => ['label' => 'Awaiting signatures', 'sql' => "count(*) filter (where e.status in ('sent', 'in_progress', 'viewed'))", 'format' => 'number'],
            'voided' => ['label' => 'Voided / declined', 'sql' => "count(*) filter (where e.status in ('voided', 'declined') or e.voided_at is not null)", 'format' => 'number'],
            'expired' => ['label' => 'Expired', 'sql' => "count(*) filter (where e.status = 'expired' or (e.status not in ('completed', 'voided') and e.expires_at < now()))", 'format' => 'number'],
            'turnaround_hours' => ['label' => 'Total hours to complete', 'sql' => 'coalesce(sum(' . $hours('e.completed_at', 'coalesce(e.sent_at, e.created_at)') . ') filter (where e.completed_at is not null), 0)', 'format' => 'number'],
            'completed_timed' => ['label' => 'Completed with dates', 'sql' => 'count(e.completed_at)', 'format' => 'number'],
            'avg_turnaround' => ['label' => 'Avg hours to complete', 'ratio' => ['turnaround_hours', 'completed_timed'], 'format' => 'number'],
            'completion_rate' => ['label' => 'Completion rate %', 'ratio' => ['completed', 'envelopes'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $label('e.status')],
            'document_type' => ['label' => 'Document type', 'sql' => $label('e.document_type', 'General')],
            'sender' => ['label' => 'Sent by', 'sql' => $person('e.created_by', 'Unknown')],
            'source' => ['label' => 'Raised from', 'sql' => "coalesce(nullif(regexp_replace(coalesce(e.related_type, ''), '^.*\\\\', ''), ''), 'Direct')"],
        ],
    ],
    'esg_signers' => [
        'module' => 'esign',
        'label' => 'E-signature signers',
        'from' => 'esign_signers sg left join esign_envelopes e on e.id = sg.envelope_id',
        'company' => 'sg.company_id',
        'date' => 'coalesce(e.sent_at, sg.created_at)',
        'measures' => [
            'signers' => ['label' => 'Signers', 'sql' => 'count(*)', 'format' => 'number'],
            'signed' => ['label' => 'Signed', 'sql' => 'count(sg.signed_at)', 'format' => 'number'],
            'declined' => ['label' => 'Declined', 'sql' => 'count(sg.declined_at)', 'format' => 'number'],
            'waiting' => ['label' => 'Still to sign', 'sql' => 'count(*) filter (where sg.signed_at is null and sg.declined_at is null)', 'format' => 'number'],
            'sign_hours' => ['label' => 'Total hours to sign', 'sql' => 'coalesce(sum(' . $hours('sg.signed_at', 'coalesce(e.sent_at, sg.created_at)') . ') filter (where sg.signed_at is not null), 0)', 'format' => 'number'],
            'avg_sign_hours' => ['label' => 'Avg hours to sign', 'ratio' => ['sign_hours', 'signed'], 'format' => 'number'],
            'sign_rate' => ['label' => 'Signed %', 'ratio' => ['signed', 'signers'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $label('sg.status')],
            'role' => ['label' => 'Role', 'sql' => $label('sg.role')],
            'method' => ['label' => 'Signature type', 'sql' => $label('sg.signature_type', 'Not signed')],
            'document_type' => ['label' => 'Document type', 'sql' => $label('e.document_type', 'General')],
        ],
    ],
    'cms_contents' => [
        'module' => 'content_management',
        'label' => 'Content library',
        'from' => 'contents ct',
        'company' => 'ct.company_id',
        'date' => 'coalesce(ct.event_date, ct.created_at)',
        'where' => 'ct.deleted_at is null',
        'measures' => [
            'items' => ['label' => 'Content items', 'sql' => 'count(*)', 'format' => 'number'],
            'active' => ['label' => 'Active', 'sql' => 'count(*) filter (where ct.is_active)', 'format' => 'number'],
            'expired' => ['label' => 'Expired', 'sql' => 'count(*) filter (where ct.expired_at < now())', 'format' => 'number'],
            'authors' => ['label' => 'Authors', 'sql' => 'count(distinct ct.user_id)', 'format' => 'number'],
            'active_pct' => ['label' => 'Active %', 'ratio' => ['active', 'items'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'type' => ['label' => 'Type', 'sql' => "coalesce('Type ' || ct.type::text, 'Unknown')"],
            'author' => ['label' => 'Author', 'sql' => $person('ct.user_id', 'Unknown')],
            'state' => ['label' => 'Active?', 'sql' => "case when ct.is_active then 'Active' else 'Inactive' end"],
        ],
    ],

    // ── Marketing ───────────────────────────────────────────────────────────────────────────────
];
