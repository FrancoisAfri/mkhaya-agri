<?php

// Governance, compliance, documents, workflow, tax and the finance executive view.
// Module codes: policy_enforcement, popia_compliance, board_committee, whistleblower,
// document_management, workflow, tax_management, finance_executive, accountant_partner, resources.
// Format: see App\Modules\BI\Support\DatasetRegistry. No named functions: this file may be required twice.

// A person's display name from hr_people (alias), or $fallback when there is none.
$name = fn (string $hp, string $fallback = 'Unassigned') => "coalesce(nullif(trim(coalesce({$hp}.first_name, '') || ' ' || coalesce({$hp}.surname, '')), ''), '{$fallback}')";
// A snake_case status as words: 'under_investigation' -> 'Under investigation'.
$words = fn (string $col, string $fallback = 'Unknown') => "coalesce(nullif(initcap(replace({$col}::text, '_', ' ')), ''), '{$fallback}')";
$days = fn (string $from, string $to) => "extract(epoch from ({$to} - {$from})) / 86400.0";

return [
    // ── Policy enforcement ─────────────────────────────────────────────────────────────────────────
    'pol_violations' => [
        'module' => 'policy_enforcement',
        'label' => 'Policy violations',
        'from' => 'policy_violations v left join policy p on p.id = v.policy_id left join policy_category pc on pc.id = p.category_id'
            . ' left join policy_rules r on r.id = v.rule_id left join company_units cu on cu.id = v.department_id left join hr_people hp on hp.user_id = v.user_id',
        'company' => 'v.company_id',
        'date' => 'v.created_at',
        'where' => 'v.deleted_at is null',
        'measures' => [
            'violations' => ['label' => 'Violations', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where v.status in ('open', 'under_investigation'))", 'format' => 'number'],
            'investigating' => ['label' => 'Under investigation', 'sql' => "count(*) filter (where v.status = 'under_investigation')", 'format' => 'number'],
            'resolved' => ['label' => 'Resolved', 'sql' => "count(*) filter (where v.status = 'resolved')", 'format' => 'number'],
            'dismissed' => ['label' => 'Dismissed', 'sql' => "count(*) filter (where v.status = 'dismissed')", 'format' => 'number'],
            'serious' => ['label' => 'High & critical', 'sql' => "count(*) filter (where v.severity in ('high', 'critical'))", 'format' => 'number'],
            'blocked' => ['label' => 'Blocked actions', 'sql' => "count(*) filter (where v.outcome = 'blocked')", 'format' => 'number'],
            'people' => ['label' => 'People involved', 'sql' => 'count(distinct v.user_id)', 'format' => 'number'],
            'closed' => ['label' => 'Closed', 'sql' => "count(*) filter (where v.status in ('resolved', 'dismissed'))", 'format' => 'number'],
            'avg_days_to_close' => ['label' => 'Average days to close', 'sql' => 'coalesce(round(avg(' . $days('v.created_at', 'v.resolved_at') . ") filter (where v.resolved_at is not null)::numeric, 1), 0)", 'format' => 'number'],
            'close_rate' => ['label' => 'Closed %', 'ratio' => ['closed', 'violations'], 'scale' => 100, 'format' => 'percent'],
            'serious_share' => ['label' => 'High & critical %', 'ratio' => ['serious', 'violations'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'policy' => ['label' => 'Policy', 'sql' => "coalesce(p.name, 'Unknown')"],
            'category' => ['label' => 'Policy category', 'sql' => "coalesce(pc.name, 'Uncategorised')"],
            'rule' => ['label' => 'Rule', 'sql' => "coalesce(r.name, 'Unknown')"],
            'department' => ['label' => 'Department', 'sql' => "coalesce(cu.name, 'Unknown')"],
            'severity' => ['label' => 'Severity', 'sql' => $words('v.severity')],
            'status' => ['label' => 'Status', 'sql' => $words('v.status')],
            'outcome' => ['label' => 'Outcome', 'sql' => $words('v.outcome')],
            'person' => ['label' => 'Person', 'sql' => $name('hp', 'Unknown')],
        ],
    ],
    'pol_attestations' => [
        'module' => 'policy_enforcement',
        'label' => 'Policy attestations',
        'from' => 'policy_attestations a left join policy p on p.id = a.policy_id left join attestation_campaigns c on c.id = a.campaign_id left join hr_people hp on hp.user_id = a.user_id',
        'company' => 'a.company_id',
        'date' => 'a.created_at',
        'measures' => [
            'assigned' => ['label' => 'Attestations', 'sql' => 'count(*)', 'format' => 'number'],
            'completed' => ['label' => 'Signed', 'sql' => "count(*) filter (where a.status = 'completed')", 'format' => 'number'],
            'pending' => ['label' => 'Pending', 'sql' => "count(*) filter (where a.status = 'pending' and (a.deadline is null or a.deadline >= now()))", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where a.status = 'overdue' or (a.status = 'pending' and a.deadline < now()))", 'format' => 'number'],
            'failed' => ['label' => 'Failed the quiz', 'sql' => "count(*) filter (where a.status = 'failed')", 'format' => 'number'],
            'people' => ['label' => 'People', 'sql' => 'count(distinct a.user_id)', 'format' => 'number'],
            'avg_quiz' => ['label' => 'Average quiz score', 'sql' => 'coalesce(round(avg(a.quiz_score)::numeric, 1), 0)', 'format' => 'number'],
            'completion_rate' => ['label' => 'Signed %', 'ratio' => ['completed', 'assigned'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'policy' => ['label' => 'Policy', 'sql' => "coalesce(p.name, 'Unknown')"],
            'campaign' => ['label' => 'Campaign', 'sql' => "coalesce(c.name, 'Ad hoc')"],
            'status' => ['label' => 'Status', 'sql' => "case when a.status = 'pending' and a.deadline < now() then 'Overdue' else " . $words('a.status') . ' end'],
            'person' => ['label' => 'Person', 'sql' => $name('hp', 'Unknown')],
        ],
    ],
    'pol_exceptions' => [
        'module' => 'policy_enforcement',
        'label' => 'Policy exceptions',
        'from' => 'policy_exceptions e left join policy p on p.id = e.policy_id left join policy_rules r on r.id = e.rule_id left join hr_people hp on hp.user_id = e.requested_by',
        'company' => 'e.company_id',
        'date' => 'e.created_at',
        'where' => 'e.deleted_at is null',
        'measures' => [
            'requests' => ['label' => 'Exception requests', 'sql' => 'count(*)', 'format' => 'number'],
            'approved' => ['label' => 'Approved', 'sql' => "count(*) filter (where e.status = 'approved')", 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where e.status = 'rejected')", 'format' => 'number'],
            'pending' => ['label' => 'Awaiting a decision', 'sql' => "count(*) filter (where e.status = 'pending')", 'format' => 'number'],
            'in_force' => ['label' => 'In force now', 'sql' => "count(*) filter (where e.status = 'approved' and (e.valid_until is null or e.valid_until >= now()))", 'format' => 'number'],
            'approval_rate' => ['label' => 'Approved %', 'ratio' => ['approved', 'requests'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'policy' => ['label' => 'Policy', 'sql' => "coalesce(p.name, 'Unknown')"],
            'rule' => ['label' => 'Rule', 'sql' => "coalesce(r.name, 'Whole policy')"],
            'status' => ['label' => 'Status', 'sql' => $words('e.status')],
            'requester' => ['label' => 'Requested by', 'sql' => $name('hp', 'Unknown')],
        ],
    ],

    // ── POPIA ──────────────────────────────────────────────────────────────────────────────────────
    'popia_breaches' => [
        'module' => 'popia_compliance',
        'label' => 'Data breach incidents',
        'from' => 'data_breach_incidents d left join hr_people hp on hp.user_id = d.assigned_to',
        'company' => 'd.company_id',
        'date' => 'd.detected_at',
        'measures' => [
            'incidents' => ['label' => 'Incidents', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Still open', 'sql' => "count(*) filter (where d.status <> 'closed')", 'format' => 'number'],
            'serious' => ['label' => 'High & critical', 'sql' => "count(*) filter (where d.severity in ('high', 'critical'))", 'format' => 'number'],
            'records' => ['label' => 'Records affected', 'sql' => 'coalesce(sum(d.affected_record_count), 0)', 'format' => 'number'],
            'regulator_notified' => ['label' => 'Regulator notified', 'sql' => 'count(d.notified_regulator_at)', 'format' => 'number'],
            'subjects_notified' => ['label' => 'Data subjects notified', 'sql' => 'count(d.notified_subjects_at)', 'format' => 'number'],
            'avg_hours_to_contain' => ['label' => 'Average hours to contain', 'sql' => 'coalesce(round(avg(extract(epoch from (d.contained_at - d.detected_at)) / 3600.0) filter (where d.contained_at is not null)::numeric, 1), 0)', 'format' => 'number'],
            'notified_rate' => ['label' => 'Regulator notified %', 'ratio' => ['regulator_notified', 'incidents'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'severity' => ['label' => 'Severity', 'sql' => $words('d.severity')],
            'status' => ['label' => 'Status', 'sql' => $words('d.status')],
            'owner' => ['label' => 'Assigned to', 'sql' => $name('hp')],
            'regulator' => ['label' => 'Regulator notified?', 'sql' => "case when d.notified_regulator_at is null then 'Not notified' else 'Notified' end"],
        ],
    ],
    'popia_dsr' => [
        'module' => 'popia_compliance',
        'label' => 'Data-subject requests',
        'from' => 'sheq_data_subject_requests r left join hr_people hp on hp.user_id = r.handled_by',
        'company' => 'r.company_id',
        'date' => 'r.received_at',
        'where' => 'r.deleted_at is null',
        'measures' => [
            'requests' => ['label' => 'Requests', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where upper(r.status) in ('RECEIVED', 'IN_PROGRESS'))", 'format' => 'number'],
            'fulfilled' => ['label' => 'Fulfilled', 'sql' => "count(*) filter (where upper(r.status) like '%FULFILLED')", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where upper(r.status) in ('RECEIVED', 'IN_PROGRESS') and r.due_at < now())", 'format' => 'number'],
            'on_time' => ['label' => 'Answered on time', 'sql' => 'count(*) filter (where r.responded_at is not null and (r.due_at is null or r.responded_at <= r.due_at))', 'format' => 'number'],
            'records_found' => ['label' => 'Records found', 'sql' => 'coalesce(sum(r.records_found), 0)', 'format' => 'number'],
            'records_erased' => ['label' => 'Records erased', 'sql' => 'coalesce(sum(r.records_erased), 0)', 'format' => 'number'],
            'avg_days' => ['label' => 'Average days to respond', 'sql' => 'coalesce(round(avg(' . $days('r.received_at', 'r.responded_at') . ') filter (where r.responded_at is not null)::numeric, 1), 0)', 'format' => 'number'],
            'on_time_rate' => ['label' => 'On time %', 'ratio' => ['on_time', 'requests'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'type' => ['label' => 'Request type', 'sql' => $words('lower(r.request_type)')],
            'status' => ['label' => 'Status', 'sql' => $words('lower(r.status)')],
            'handler' => ['label' => 'Handled by', 'sql' => $name('hp')],
        ],
    ],
    'popia_consents' => [
        'module' => 'popia_compliance',
        'label' => 'POPIA consents',
        'from' => 'popia_consents c',
        'company' => 'c.company_id',
        'date' => 'coalesce(c.granted_at, c.created_at)',
        'measures' => [
            'consents' => ['label' => 'Consent records', 'sql' => 'count(*)', 'format' => 'number'],
            'granted' => ['label' => 'Granted', 'sql' => "count(*) filter (where c.status = 'granted')", 'format' => 'number'],
            'declined' => ['label' => 'Declined', 'sql' => "count(*) filter (where c.status = 'declined')", 'format' => 'number'],
            'revoked' => ['label' => 'Withdrawn', 'sql' => "count(*) filter (where c.status = 'revoked')", 'format' => 'number'],
            'expiring' => ['label' => 'Expiring in 30 days', 'sql' => "count(*) filter (where c.status = 'granted' and c.expires_at between now() and now() + interval '30 days')", 'format' => 'number'],
            'subjects' => ['label' => 'Data subjects', 'sql' => 'count(distinct c.subject_id)', 'format' => 'number'],
            'grant_rate' => ['label' => 'Granted %', 'ratio' => ['granted', 'consents'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'purpose' => ['label' => 'Purpose', 'sql' => $words('c.purpose')],
            'status' => ['label' => 'Status', 'sql' => $words('c.status')],
            'version' => ['label' => 'Notice version', 'sql' => "coalesce(nullif(c.consent_version, ''), 'Unknown')"],
        ],
    ],

    // ── Board & committees ─────────────────────────────────────────────────────────────────────────

    // ── Whistleblower ──────────────────────────────────────────────────────────────────────────────

    // ── Document management ────────────────────────────────────────────────────────────────────────
    'dm_documents' => [
        'module' => 'document_management',
        'label' => 'Documents',
        'from' => 'documents d left join document_categories c on c.id = d.category_id left join document_folders f on f.id = d.folder_id'
            . ' left join company_units cu on cu.id = d.department_unit_id left join hr_people hp on hp.user_id = coalesce(d.owner_id, d.created_by)'
            . ' left join document_versions dv on dv.id = d.current_version_id',
        'company' => 'd.company_id',
        'date' => 'd.created_at',
        'where' => 'd.deleted_at is null',
        'measures' => [
            'documents' => ['label' => 'Documents', 'sql' => 'count(*)', 'format' => 'number'],
            'storage_mb' => ['label' => 'Storage (MB)', 'sql' => 'coalesce(round(sum(dv.size) / 1048576.0, 1), 0)', 'format' => 'number'],
            'versions' => ['label' => 'Versions', 'sql' => 'coalesce(sum(greatest(d.version_count, 1)), 0)', 'format' => 'number'],
            'confidential' => ['label' => 'Confidential', 'sql' => "count(*) filter (where d.is_confidential or d.confidentiality in ('confidential', 'restricted', 'secret'))", 'format' => 'number'],
            'expiring' => ['label' => 'Expiring in 90 days', 'sql' => "count(*) filter (where d.expiry_date between current_date and current_date + 90)", 'format' => 'number'],
            'expired' => ['label' => 'Expired', 'sql' => 'count(*) filter (where d.expiry_date < current_date)', 'format' => 'number'],
            'archived' => ['label' => 'Archived', 'sql' => 'count(d.archived_at)', 'format' => 'number'],
            'awaiting_approval' => ['label' => 'Awaiting approval', 'sql' => "count(*) filter (where d.approval_status in ('pending', 'in_review', 'submitted'))", 'format' => 'number'],
            'disposal_due' => ['label' => 'Due for disposal', 'sql' => 'count(*) filter (where d.disposal_due_at <= now())', 'format' => 'number'],
            'legal_hold' => ['label' => 'On legal hold', 'sql' => 'count(*) filter (where exists (select 1 from document_legal_holds h where h.document_id = d.id and h.released_at is null))', 'format' => 'number'],
            'confidential_share' => ['label' => 'Confidential %', 'ratio' => ['confidential', 'documents'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'category' => ['label' => 'Category', 'sql' => "coalesce(c.name, 'Uncategorised')"],
            'folder' => ['label' => 'Folder', 'sql' => "coalesce(f.name, 'No folder')"],
            'department' => ['label' => 'Department', 'sql' => "coalesce(cu.name, 'No department')"],
            'owner' => ['label' => 'Owner', 'sql' => $name('hp')],
            'confidentiality' => ['label' => 'Confidentiality', 'sql' => $words('d.confidentiality')],
            'status' => ['label' => 'Status', 'sql' => $words('d.status')],
            'approval' => ['label' => 'Approval', 'sql' => $words('d.approval_status')],
            'file_type' => ['label' => 'File type', 'sql' => "coalesce(nullif(upper(dv.extension), ''), 'Unknown')"],
            'expiry' => ['label' => 'Expiry', 'sql' => "case when d.expiry_date is null then 'No expiry' when d.expiry_date < current_date then 'Expired' when d.expiry_date <= current_date + 90 then 'Expires within 90 days' else 'Current' end"],
            'retention' => ['label' => 'Retention rule', 'sql' => "case when c.retention_years is null then 'No rule' else c.retention_years || ' years' end"],
        ],
    ],
    'dm_activity' => [
        'module' => 'document_management',
        'label' => 'Document activity',
        'from' => 'document_events e left join documents d on d.id = e.document_id left join document_folders f on f.id = coalesce(e.folder_id, d.folder_id) left join hr_people hp on hp.user_id = e.user_id',
        'company' => 'e.company_id',
        'date' => 'e.created_at',
        'measures' => [
            'events' => ['label' => 'Events', 'sql' => 'count(*)', 'format' => 'number'],
            'uploads' => ['label' => 'Uploads', 'sql' => "count(*) filter (where e.action in ('uploaded', 'version_uploaded', 'generated'))", 'format' => 'number'],
            'views' => ['label' => 'Views', 'sql' => "count(*) filter (where e.action = 'viewed')", 'format' => 'number'],
            'downloads' => ['label' => 'Downloads', 'sql' => "count(*) filter (where e.action = 'downloaded')", 'format' => 'number'],
            'users' => ['label' => 'Active users', 'sql' => 'count(distinct e.user_id)', 'format' => 'number'],
            'documents' => ['label' => 'Documents touched', 'sql' => 'count(distinct e.document_id)', 'format' => 'number'],
        ],
        'dimensions' => [
            'action' => ['label' => 'Action', 'sql' => $words('e.action')],
            'user' => ['label' => 'User', 'sql' => $name('hp', 'System')],
            'folder' => ['label' => 'Folder', 'sql' => "coalesce(f.name, 'No folder')"],
            'document' => ['label' => 'Document', 'sql' => "coalesce(d.title, 'Unknown')"],
        ],
    ],

    // ── Workflow ───────────────────────────────────────────────────────────────────────────────────
    'wf_instances' => [
        'module' => 'workflow',
        'label' => 'Workflow cases',
        'from' => 'workflow_instances i left join workflow_definitions wd on wd.id = i.definition_id left join hr_people hp on hp.user_id = i.requester_id',
        'company' => 'i.company_id',
        'date' => 'coalesce(i.started_at, i.created_at)',
        'measures' => [
            'instances' => ['label' => 'Cases started', 'sql' => 'count(*)', 'format' => 'number'],
            'running' => ['label' => 'In progress', 'sql' => "count(*) filter (where i.status = 'running')", 'format' => 'number'],
            'completed' => ['label' => 'Finished', 'sql' => "count(*) filter (where i.status in ('completed', 'rejected'))", 'format' => 'number'],
            'approved' => ['label' => 'Approved', 'sql' => "count(*) filter (where i.outcome = 'approved')", 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where i.outcome = 'rejected')", 'format' => 'number'],
            'cancelled' => ['label' => 'Cancelled', 'sql' => "count(*) filter (where i.status = 'cancelled')", 'format' => 'number'],
            'avg_hours' => ['label' => 'Average hours start to finish', 'sql' => 'coalesce(round(avg(extract(epoch from (i.completed_at - coalesce(i.started_at, i.created_at))) / 3600.0) filter (where i.completed_at is not null)::numeric, 1), 0)', 'format' => 'number'],
            'requesters' => ['label' => 'Requesters', 'sql' => 'count(distinct i.requester_id)', 'format' => 'number'],
            'approval_rate' => ['label' => 'Approved %', 'ratio' => ['approved', 'completed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'process' => ['label' => 'Process', 'sql' => "coalesce(wd.name, 'Unknown')"],
            'category' => ['label' => 'Category', 'sql' => "coalesce(nullif(wd.category, ''), 'Uncategorised')"],
            'status' => ['label' => 'Status', 'sql' => $words('i.status')],
            'outcome' => ['label' => 'Outcome', 'sql' => $words('i.outcome', 'In progress')],
            'requester' => ['label' => 'Requester', 'sql' => $name('hp', 'Unknown')],
            'priority' => ['label' => 'Priority', 'sql' => $words('i.priority')],
        ],
    ],
    'wf_tasks' => [
        'module' => 'workflow',
        'label' => 'Workflow tasks & approvals',
        'from' => 'workflow_tasks t join workflow_instances i on i.id = t.instance_id left join workflow_definitions wd on wd.id = i.definition_id left join hr_people hp on hp.user_id = t.assignee_id',
        'company' => 't.company_id',
        'date' => 't.created_at',
        'measures' => [
            'tasks' => ['label' => 'Tasks', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Waiting', 'sql' => "count(*) filter (where t.status = 'open')", 'format' => 'number'],
            'completed' => ['label' => 'Done', 'sql' => "count(*) filter (where t.status = 'completed')", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where t.status = 'open' and t.due_at < now())", 'format' => 'number'],
            'breached' => ['label' => 'SLA breached', 'sql' => "count(*) filter (where t.sla_state = 'breached' or t.breached_at is not null)", 'format' => 'number'],
            'approved' => ['label' => 'Approved', 'sql' => "count(*) filter (where t.decision = 'approved')", 'format' => 'number'],
            'rejected' => ['label' => 'Rejected or returned', 'sql' => "count(*) filter (where t.decision in ('rejected', 'returned'))", 'format' => 'number'],
            'avg_hours' => ['label' => 'Average hours to act', 'sql' => "coalesce(round(avg(extract(epoch from (t.completed_at - t.created_at)) / 3600.0) filter (where t.status = 'completed' and t.completed_at is not null)::numeric, 1), 0)", 'format' => 'number'],
            'avg_open_hours' => ['label' => 'Average hours waiting', 'sql' => "coalesce(round(avg(extract(epoch from (now() - t.created_at)) / 3600.0) filter (where t.status = 'open')::numeric, 1), 0)", 'format' => 'number'],
            'approval_rate' => ['label' => 'Approved %', 'ratio' => ['approved', 'completed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'process' => ['label' => 'Process', 'sql' => "coalesce(wd.name, 'Unknown')"],
            'step' => ['label' => 'Step', 'sql' => "coalesce(nullif(t.name, ''), t.node_key, 'Unknown')"],
            'type' => ['label' => 'Task type', 'sql' => $words('t.type')],
            'assignee' => ['label' => 'Assignee', 'sql' => $name('hp')],
            'status' => ['label' => 'Status', 'sql' => $words('t.status')],
            'decision' => ['label' => 'Decision', 'sql' => $words('t.decision', 'Not decided')],
            'sla' => ['label' => 'SLA', 'sql' => $words('t.sla_state', 'None')],
        ],
    ],

    // ── Tax management ─────────────────────────────────────────────────────────────────────────────
    'tax_returns' => [
        'module' => 'tax_management',
        'label' => 'Tax returns',
        'from' => 'tax_returns r left join tax_types tt on tt.id = r.tax_type_id left join tax_periods tp on tp.id = r.period_id',
        'company' => 'r.company_id',
        'date' => 'coalesce(r.due_date, r.created_at::date)',
        'where' => "r.status <> 'superseded'",
        'measures' => [
            'returns' => ['label' => 'Returns', 'sql' => 'count(*)', 'format' => 'number'],
            'submitted' => ['label' => 'Submitted', 'sql' => 'count(r.submitted_at)', 'format' => 'number'],
            'accepted' => ['label' => 'Accepted by SARS', 'sql' => "count(*) filter (where r.submission_status = 'accepted' or r.status = 'accepted')", 'format' => 'number'],
            'payable' => ['label' => 'Tax payable', 'sql' => 'coalesce(sum(r.amount_payable), 0)', 'format' => 'currency'],
            'unpaid' => ['label' => 'Payable not yet paid', 'sql' => "coalesce(sum(r.amount_payable) filter (where r.payment_status = 'unpaid'), 0)", 'format' => 'currency'],
            'late' => ['label' => 'Filed late', 'sql' => 'count(*) filter (where r.submitted_at::date > r.due_date)', 'format' => 'number'],
            'not_filed' => ['label' => 'Not filed yet', 'sql' => 'count(*) filter (where r.submitted_at is null)', 'format' => 'number'],
            'submitted_rate' => ['label' => 'Submitted %', 'ratio' => ['submitted', 'returns'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'tax_type' => ['label' => 'Tax type', 'sql' => "coalesce(tt.name, 'Unknown')"],
            'period' => ['label' => 'Period', 'sql' => "coalesce(tp.label, 'Unknown')"],
            'status' => ['label' => 'Status', 'sql' => $words('r.status')],
            'submission' => ['label' => 'Submission', 'sql' => $words('r.submission_status')],
            'payment' => ['label' => 'Payment', 'sql' => $words('r.payment_status')],
        ],
    ],
    'tax_liabilities' => [
        'module' => 'tax_management',
        'label' => 'Tax owed to SARS',
        'from' => 'tax_liabilities l left join tax_types tt on tt.id = l.tax_type_id',
        'company' => 'l.company_id',
        'date' => 'l.due_date',
        'where' => "l.status <> 'superseded'",
        'measures' => [
            'owed' => ['label' => 'Assessed', 'sql' => 'coalesce(sum(l.original_amount + coalesce(l.adjustments, 0)), 0)', 'format' => 'currency'],
            'paid' => ['label' => 'Paid', 'sql' => 'coalesce(sum(l.paid_amount), 0)', 'format' => 'currency'],
            'outstanding' => ['label' => 'Outstanding', 'sql' => 'coalesce(sum(l.original_amount + coalesce(l.adjustments, 0) - coalesce(l.paid_amount, 0)), 0)', 'format' => 'currency'],
            'overdue' => ['label' => 'Overdue', 'sql' => "coalesce(sum(l.original_amount + coalesce(l.adjustments, 0) - coalesce(l.paid_amount, 0)) filter (where l.status = 'open' and l.due_date < current_date), 0)", 'format' => 'currency'],
            'items' => ['label' => 'Liabilities', 'sql' => 'count(*)', 'format' => 'number'],
            'paid_rate' => ['label' => 'Paid %', 'ratio' => ['paid', 'owed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'tax_type' => ['label' => 'Tax type', 'sql' => "coalesce(tt.name, 'Unknown')"],
            'source' => ['label' => 'Source', 'sql' => $words('l.source')],
            'status' => ['label' => 'Status', 'sql' => $words('l.status')],
        ],
    ],

    // ── Finance executive ──────────────────────────────────────────────────────────────────────────
    'fx_pnl' => [
        'module' => 'finance_executive',
        'label' => 'Profit & loss (executive)',
        'from' => 'fin_journal_lines l join fin_journals j on j.id = l.journal_id join fin_accounts a on a.id = l.account_id',
        'company' => 'l.company_id',
        'date' => 'j.journal_date',
        'where' => "a.type in ('income', 'expense') and j.status = 'posted'",
        'measures' => [
            'revenue' => ['label' => 'Revenue', 'sql' => "coalesce(sum(case when a.type = 'income' then l.base_credit - l.base_debit else 0 end), 0)", 'format' => 'currency'],
            'cost_of_sales' => ['label' => 'Cost of sales', 'sql' => "coalesce(sum(case when a.type = 'expense' and a.subtype = 'Cost of Sales' then l.base_debit - l.base_credit else 0 end), 0)", 'format' => 'currency'],
            'operating_expenses' => ['label' => 'Operating expenses', 'sql' => "coalesce(sum(case when a.type = 'expense' and coalesce(a.subtype, '') not in ('Cost of Sales', 'Income Tax') then l.base_debit - l.base_credit else 0 end), 0)", 'format' => 'currency'],
            'expenses' => ['label' => 'Total expenses', 'sql' => "coalesce(sum(case when a.type = 'expense' then l.base_debit - l.base_credit else 0 end), 0)", 'format' => 'currency'],
            'gross_profit' => ['label' => 'Gross profit', 'sql' => "coalesce(sum(case when a.type = 'income' then l.base_credit - l.base_debit when a.subtype = 'Cost of Sales' then l.base_credit - l.base_debit else 0 end), 0)", 'format' => 'currency'],
            'net_profit' => ['label' => 'Net profit', 'sql' => 'coalesce(sum(l.base_credit - l.base_debit), 0)', 'format' => 'currency'],
            'gross_margin' => ['label' => 'Gross margin %', 'ratio' => ['gross_profit', 'revenue'], 'scale' => 100, 'format' => 'percent'],
            'net_margin' => ['label' => 'Net margin %', 'ratio' => ['net_profit', 'revenue'], 'scale' => 100, 'format' => 'percent'],
            'cost_ratio' => ['label' => 'Expenses as % of revenue', 'ratio' => ['expenses', 'revenue'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'section' => ['label' => 'P&L section', 'sql' => "case when a.type = 'income' then 'Revenue' when a.subtype = 'Cost of Sales' then 'Cost of sales' when a.subtype = 'Income Tax' then 'Income tax' else 'Operating expenses' end"],
            'account' => ['label' => 'Account', 'sql' => "a.code || ' ' || a.name"],
            'source' => ['label' => 'Source', 'sql' => "coalesce(nullif(j.source_module, ''), 'Manual')"],
        ],
    ],
    'fx_budget' => [
        'module' => 'finance_executive',
        'label' => 'Budget vs actual',
        // One row per budget line (latest approved budget of each year) and per posted P&L ledger line,
        // both signed the natural way: income as credit, expenses as debit.
        'from' => "(select bl.company_id, make_date(b.fiscal_year, least(greatest(bl.period_no, 1), 12), 1) as d, bl.account_id, 'budget' as kind, bl.amount as amount
                      from fin_budget_lines bl join fin_budgets b on b.id = bl.budget_id
                     where b.id = (select max(b2.id) from fin_budgets b2 where b2.company_id = b.company_id and b2.fiscal_year = b.fiscal_year and b2.status = 'approved')
                    union all
                    select l.company_id, j.journal_date as d, l.account_id, 'actual' as kind,
                           case when a2.type = 'income' then l.base_credit - l.base_debit else l.base_debit - l.base_credit end as amount
                      from fin_journal_lines l join fin_journals j on j.id = l.journal_id join fin_accounts a2 on a2.id = l.account_id
                     where j.status = 'posted' and a2.type in ('income', 'expense')) x
                   join fin_accounts a on a.id = x.account_id",
        'company' => 'x.company_id',
        'date' => 'x.d',
        'measures' => [
            'budget' => ['label' => 'Budget', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'budget'), 0)", 'format' => 'currency'],
            'actual' => ['label' => 'Actual', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'actual'), 0)", 'format' => 'currency'],
            'variance' => ['label' => 'Actual less budget', 'sql' => "coalesce(sum(case when x.kind = 'actual' then x.amount else -x.amount end), 0)", 'format' => 'currency'],
            'budget_income' => ['label' => 'Budgeted revenue', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'budget' and a.type = 'income'), 0)", 'format' => 'currency'],
            'actual_income' => ['label' => 'Actual revenue', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'actual' and a.type = 'income'), 0)", 'format' => 'currency'],
            'budget_expense' => ['label' => 'Budgeted expenses', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'budget' and a.type = 'expense'), 0)", 'format' => 'currency'],
            'actual_expense' => ['label' => 'Actual expenses', 'sql' => "coalesce(sum(x.amount) filter (where x.kind = 'actual' and a.type = 'expense'), 0)", 'format' => 'currency'],
            'achieved' => ['label' => 'Actual as % of budget', 'ratio' => ['actual', 'budget'], 'scale' => 100, 'format' => 'percent'],
            'revenue_achieved' => ['label' => 'Revenue vs budget %', 'ratio' => ['actual_income', 'budget_income'], 'scale' => 100, 'format' => 'percent'],
            'spend_used' => ['label' => 'Expense budget used %', 'ratio' => ['actual_expense', 'budget_expense'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'account' => ['label' => 'Account', 'sql' => "a.code || ' ' || a.name"],
            'type' => ['label' => 'Income / expense', 'sql' => "case when a.type = 'income' then 'Income' else 'Expense' end"],
            'group' => ['label' => 'Account group', 'sql' => "coalesce(nullif(a.subtype, ''), 'Other')"],
        ],
    ],

    // ── Accountant partner (the practice's own book of clients) ────────────────────────────────────

    // ── Resources (company library) ────────────────────────────────────────────────────────────────
    'res_library' => [
        'module' => 'resources',
        'label' => 'Resource library',
        'from' => 'resources r left join company_units cu on cu.id = r.company_unit_id left join hr_people hp on hp.user_id = r.user_id',
        'company' => 'r.company_id',
        'date' => 'coalesce(r.published_at, r.created_at)',
        'where' => 'r.deleted_at is null',
        'measures' => [
            'resources' => ['label' => 'Resources', 'sql' => 'count(*)', 'format' => 'number'],
            'active' => ['label' => 'Live', 'sql' => 'count(*) filter (where r.is_active and (r.expired_at is null or r.expired_at > now()))', 'format' => 'number'],
            'internal' => ['label' => 'Internal only', 'sql' => 'count(*) filter (where r.internal_only)', 'format' => 'number'],
            'expiring' => ['label' => 'Expiring in 30 days', 'sql' => "count(*) filter (where r.expired_at between now() and now() + interval '30 days')", 'format' => 'number'],
            'expired' => ['label' => 'Expired', 'sql' => 'count(*) filter (where r.expired_at <= now())', 'format' => 'number'],
            'live_rate' => ['label' => 'Live %', 'ratio' => ['active', 'resources'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'type' => ['label' => 'Type', 'sql' => "case r.type when 1 then 'Form' when 2 then 'Legal document' when 3 then 'Employee handbook' when 4 then 'Health & safety' when 5 then 'Template' when 6 then 'Training material' when 7 then 'SOP' when 8 then 'Download' else 'Resource' end"],
            'department' => ['label' => 'Department', 'sql' => "coalesce(cu.name, 'Company-wide')"],
            'owner' => ['label' => 'Published by', 'sql' => $name('hp', 'Unknown')],
            'audience' => ['label' => 'Audience', 'sql' => "case when r.internal_only then 'Internal only' else 'Everyone' end"],
            'state' => ['label' => 'State', 'sql' => "case when r.expired_at <= now() then 'Expired' when not r.is_active then 'Hidden' else 'Live' end"],
        ],
    ],
];
