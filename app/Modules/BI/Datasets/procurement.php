<?php

// Procurement, suppliers, contracts, tenders, marketplace, petty cash and budgets.
// Module codes: procurement_requests, procurement_governance, supplier_management, supplier_due_diligence,
// contract_management, tender_pursuit, marketplace, petty_cash, budget_management.
// See DatasetRegistry for the format. Variables only (this file may be required more than once).

$supName = fn (string $a) => "coalesce(nullif({$a}.trading_name, ''), {$a}.company_name, 'Unknown')";

// First supplier category of supplier alias $a, joined as alias "scat".
$supCat = fn (string $a) => "left join lateral (select min(sc.name) as name from supplier_category_supplier x join supplier_categories sc on sc.id = x.supplier_category_id where x.supplier_id = {$a}.id) scat on true";

$beeLevel = fn (string $a) => "case when {$a}.bbbee_status_level ~ '^[1-8]\$' then 'Level ' || {$a}.bbbee_status_level else 'Non-compliant / unknown' end";
$beeCompliant = fn (string $a) => "{$a}.bbbee_status_level in ('1', '2', '3', '4')";
// B-BBEE procurement recognition % per level (Codes of Good Practice).
$beeRecognition = fn (string $a) => "case {$a}.bbbee_status_level when '1' then 1.35 when '2' then 1.25 when '3' then 1.10 when '4' then 1.00 when '5' then 0.80 when '6' then 0.60 when '7' then 0.50 when '8' then 0.10 else 0 end";

$nice = fn (string $col, string $empty = 'Unknown') => "coalesce(initcap(replace(replace(nullif({$col}, ''), '_', ' '), '.', ' - ')), '{$empty}')";

return [
    // ---------------------------------------------------------------- procurement_requests
    'proc_requests' => [
        'module' => 'procurement_requests',
        'label' => 'Purchase requests',
        'from' => 'purchase_requests pr left join budgets b on b.id = pr.budget_id',
        'company' => 'pr.company_id',
        'date' => 'pr.created_at',
        'where' => 'pr.deleted_at is null',
        'measures' => [
            'requests' => ['label' => 'Requests', 'sql' => 'count(*)', 'format' => 'number'],
            'value' => ['label' => 'Requested value', 'sql' => 'coalesce(sum(pr.estimated_total), 0)', 'format' => 'currency'],
            'approved' => ['label' => 'Approved / converted', 'sql' => "count(*) filter (where pr.status in ('approved', 'converted'))", 'format' => 'number'],
            'pending' => ['label' => 'Awaiting approval', 'sql' => "count(*) filter (where pr.status = 'pending_approval')", 'format' => 'number'],
            'pending_value' => ['label' => 'Value awaiting approval', 'sql' => "coalesce(sum(pr.estimated_total) filter (where pr.status = 'pending_approval'), 0)", 'format' => 'currency'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where pr.status in ('rejected', 'validation_failed'))", 'format' => 'number'],
            'emergency' => ['label' => 'Emergency requests', 'sql' => 'count(*) filter (where pr.is_emergency)', 'format' => 'number'],
            'emergency_value' => ['label' => 'Emergency value', 'sql' => 'coalesce(sum(pr.estimated_total) filter (where pr.is_emergency), 0)', 'format' => 'currency'],
            'reserved' => ['label' => 'Budget reserved', 'sql' => 'coalesce(sum(pr.reserved_amount), 0)', 'format' => 'currency'],
            'approval_rate' => ['label' => 'Approval rate %', 'ratio' => ['approved', 'requests'], 'scale' => 100, 'format' => 'percent'],
            'emergency_share' => ['label' => 'Emergency share %', 'ratio' => ['emergency_value', 'value'], 'scale' => 100, 'format' => 'percent'],
            'avg_request' => ['label' => 'Average request', 'ratio' => ['value', 'requests'], 'format' => 'currency'],
        ],
        'dimensions' => [
            'department' => ['label' => 'Department', 'sql' => "coalesce(nullif(pr.department, ''), b.scope_label, 'Unassigned')"],
            'status' => ['label' => 'Status', 'sql' => $nice('pr.status')],
            'urgency' => ['label' => 'Urgency', 'sql' => $nice('pr.urgency', 'Normal')],
            'emergency' => ['label' => 'Emergency?', 'sql' => "case when pr.is_emergency then 'Emergency' else 'Normal' end"],
            'budget' => ['label' => 'Budget', 'sql' => "coalesce(b.name, 'No budget')"],
            'project' => ['label' => 'Project code', 'sql' => "coalesce(nullif(pr.project_code, ''), 'None')"],
        ],
    ],
    'proc_order_lines' => [
        'module' => 'procurement_requests',
        'label' => 'Purchase order lines',
        'from' => 'purchase_order_items i join purchase_orders po on po.id = i.purchase_order_id left join suppliers s on s.id = po.supplier_id left join budget_categories bc on bc.id = i.budget_category_id',
        'company' => 'i.company_id',
        'date' => 'coalesce(po.order_date, po.created_at::date)',
        'where' => "po.deleted_at is null and po.status not in ('cancelled', 'draft')",
        'measures' => [
            'line_value' => ['label' => 'Line value', 'sql' => 'coalesce(sum(i.line_total), 0)', 'format' => 'currency'],
            'lines' => ['label' => 'Lines', 'sql' => 'count(*)', 'format' => 'number'],
            'quantity' => ['label' => 'Quantity ordered', 'sql' => 'coalesce(sum(i.quantity), 0)', 'format' => 'number'],
            'received_qty' => ['label' => 'Quantity received', 'sql' => 'coalesce(sum(i.received_qty), 0)', 'format' => 'number'],
            'asset_value' => ['label' => 'Asset purchases', 'sql' => 'coalesce(sum(i.line_total) filter (where i.is_asset), 0)', 'format' => 'currency'],
            'received_rate' => ['label' => 'Received %', 'ratio' => ['received_qty', 'quantity'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'item' => ['label' => 'Item', 'sql' => "coalesce(nullif(i.name, ''), 'Unnamed item')"],
            'category' => ['label' => 'Spend category', 'sql' => "coalesce(bc.name, 'Uncategorised')"],
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
            'asset' => ['label' => 'Asset or consumable', 'sql' => "case when i.is_asset then 'Asset' else 'Goods / services' end"],
        ],
    ],
    'proc_approvals' => [
        'module' => 'procurement_requests',
        'label' => 'Procurement approvals',
        'from' => "procurement_approvals a left join purchase_requests pr on pr.id = a.source_id and a.source_type like '%PurchaseRequest'",
        'company' => 'a.company_id',
        'date' => 'a.created_at',
        'measures' => [
            'approvals' => ['label' => 'Approval runs', 'sql' => 'count(*)', 'format' => 'number'],
            'approved' => ['label' => 'Approved', 'sql' => "count(*) filter (where a.status = 'approved')", 'format' => 'number'],
            'rejected' => ['label' => 'Rejected', 'sql' => "count(*) filter (where a.status = 'rejected')", 'format' => 'number'],
            'pending' => ['label' => 'Pending', 'sql' => "count(*) filter (where a.status = 'pending')", 'format' => 'number'],
            'completed' => ['label' => 'Decided', 'sql' => 'count(a.completed_at)', 'format' => 'number'],
            'cycle_days' => ['label' => 'Total days to decide', 'sql' => 'coalesce(sum(extract(epoch from (a.completed_at - a.created_at)) / 86400.0), 0)', 'format' => 'number'],
            'avg_days' => ['label' => 'Average days to decide', 'ratio' => ['cycle_days', 'completed'], 'format' => 'number'],
            'pending_age_days' => ['label' => 'Days pending (total)', 'sql' => "coalesce(sum(extract(epoch from (now() - a.created_at)) / 86400.0) filter (where a.status = 'pending'), 0)", 'format' => 'number'],
            'avg_pending_age' => ['label' => 'Average age of pending (days)', 'ratio' => ['pending_age_days', 'pending'], 'format' => 'number'],
            'approval_rate' => ['label' => 'Approval rate %', 'ratio' => ['approved', 'completed'], 'scale' => 100, 'format' => 'percent'],
            'value' => ['label' => 'Value put through approval', 'sql' => 'coalesce(sum(pr.estimated_total), 0)', 'format' => 'currency'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $nice('a.status')],
            'department' => ['label' => 'Department', 'sql' => "coalesce(nullif(pr.department, ''), 'Unknown')"],
            'steps' => ['label' => 'Approval steps', 'sql' => "coalesce(a.total_steps::text || ' step(s)', 'Unknown')"],
            'speed' => ['label' => 'Turnaround', 'sql' => "case when a.completed_at is null then 'Still pending' when a.completed_at - a.created_at <= interval '1 day' then 'Within 1 day' when a.completed_at - a.created_at <= interval '3 days' then '1–3 days' when a.completed_at - a.created_at <= interval '7 days' then '3–7 days' else 'Over 7 days' end"],
        ],
    ],
    'proc_violations' => [
        'module' => 'procurement_requests',
        'label' => 'Procurement policy deviations',
        'from' => 'procurement_policy_violations v',
        'company' => 'v.company_id',
        'date' => 'v.created_at',
        'measures' => [
            'violations' => ['label' => 'Deviations', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open deviations', 'sql' => "count(*) filter (where v.status = 'open')", 'format' => 'number'],
            'blocking' => ['label' => 'Blocking deviations', 'sql' => "count(*) filter (where v.severity = 'block')", 'format' => 'number'],
            'warnings' => ['label' => 'Warnings', 'sql' => "count(*) filter (where v.severity = 'warning')", 'format' => 'number'],
            'documents' => ['label' => 'Documents affected', 'sql' => 'count(distinct (v.source_type, v.source_id))', 'format' => 'number'],
            'open_rate' => ['label' => 'Still open %', 'ratio' => ['open', 'violations'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'rule' => ['label' => 'Policy rule', 'sql' => $nice('v.rule_type')],
            'severity' => ['label' => 'Severity', 'sql' => $nice('v.severity')],
            'status' => ['label' => 'Status', 'sql' => $nice('v.status')],
            'source' => ['label' => 'Raised on', 'sql' => "case when v.source_type like '%PurchaseRequest' then 'Purchase request' when v.source_type like '%PurchaseOrder' then 'Purchase order' when v.source_type like '%Requisition' then 'Requisition' else 'Other' end"],
        ],
    ],

    // ---------------------------------------------------------------- procurement_governance
    'proc_tender_reviews' => [
        'module' => 'procurement_governance',
        'label' => 'Tender evaluations (buy side)',
        'from' => 'procgov_tender_reviews tr',
        'company' => 'tr.company_id',
        'date' => 'coalesce(tr.tender_closing_at, tr.created_at)',
        'where' => 'tr.deleted_at is null',
        'measures' => [
            'tenders' => ['label' => 'Tenders', 'sql' => 'count(*)', 'format' => 'number'],
            'value' => ['label' => 'Estimated value', 'sql' => 'coalesce(sum(tr.estimated_value), 0)', 'format' => 'currency'],
            'awarded' => ['label' => 'Awarded', 'sql' => "count(*) filter (where tr.status = 'AWARDED')", 'format' => 'number'],
            'awarded_value' => ['label' => 'Awarded value', 'sql' => "coalesce(sum(tr.estimated_value) filter (where tr.status = 'AWARDED'), 0)", 'format' => 'currency'],
            'in_progress' => ['label' => 'In progress', 'sql' => "count(*) filter (where tr.status not in ('AWARDED', 'CANCELLED'))", 'format' => 'number'],
            'cancelled' => ['label' => 'Cancelled', 'sql' => "count(*) filter (where tr.status = 'CANCELLED')", 'format' => 'number'],
            'award_rate' => ['label' => 'Award rate %', 'ratio' => ['awarded', 'tenders'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Stage', 'sql' => $nice('lower(tr.status)')],
            'category' => ['label' => 'Category', 'sql' => $nice('tr.category', 'Uncategorised')],
            'methodology' => ['label' => 'Evaluation method', 'sql' => "coalesce(nullif(tr.methodology, ''), 'Not set')"],
            'tender' => ['label' => 'Tender', 'sql' => "coalesce(nullif(tr.title, ''), tr.reference, 'Untitled')"],
        ],
    ],
    'proc_bids' => [
        'module' => 'procurement_governance',
        'label' => 'Bids received (buy side)',
        'from' => 'procgov_bidders bd join procgov_tender_reviews tr on tr.id = bd.review_id left join suppliers s on s.id = bd.supplier_id',
        'company' => 'bd.company_id',
        'date' => 'coalesce(bd.submitted_at, bd.created_at)',
        'where' => 'bd.deleted_at is null and tr.deleted_at is null',
        'measures' => [
            'bids' => ['label' => 'Bids', 'sql' => 'count(*)', 'format' => 'number'],
            'tenders' => ['label' => 'Tenders bid on', 'sql' => 'count(distinct bd.review_id)', 'format' => 'number'],
            'bid_value' => ['label' => 'Bid value', 'sql' => 'coalesce(sum(coalesce(bd.bid_amount_base, bd.bid_amount)), 0)', 'format' => 'currency'],
            'avg_bid' => ['label' => 'Average bid', 'ratio' => ['bid_value', 'bids'], 'format' => 'currency'],
            'awarded' => ['label' => 'Awarded bids', 'sql' => "count(*) filter (where bd.status = 'AWARDED')", 'format' => 'number'],
            'disqualified' => ['label' => 'Disqualified', 'sql' => 'count(*) filter (where bd.is_disqualified)', 'format' => 'number'],
            'late' => ['label' => 'Late bids', 'sql' => 'count(*) filter (where bd.is_late)', 'format' => 'number'],
            'score_total' => ['label' => 'Weighted score (sum)', 'sql' => 'coalesce(sum(bd.weighted_score), 0)', 'format' => 'number'],
            'scored' => ['label' => 'Scored bids', 'sql' => 'count(bd.weighted_score)', 'format' => 'number'],
            'avg_score' => ['label' => 'Average weighted score', 'ratio' => ['score_total', 'scored'], 'format' => 'number'],
            'bids_per_tender' => ['label' => 'Bids per tender', 'ratio' => ['bids', 'tenders'], 'format' => 'number'],
            'disqualification_rate' => ['label' => 'Disqualification rate %', 'ratio' => ['disqualified', 'bids'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Bid outcome', 'sql' => $nice('lower(bd.status)')],
            'tender' => ['label' => 'Tender', 'sql' => "coalesce(nullif(tr.title, ''), tr.reference, 'Untitled')"],
            'bidder' => ['label' => 'Bidder', 'sql' => "coalesce(nullif(bd.supplier_name, ''), " . $supName('s') . ')'],
            'bee_level' => ['label' => 'B-BBEE level', 'sql' => "coalesce('Level ' || nullif(bd.bbbee_level::text, ''), 'Unknown')"],
            'stage_out' => ['label' => 'Disqualified at', 'sql' => "case when bd.is_disqualified then " . $nice('bd.disqualification_stage', 'Unspecified') . " else 'Not disqualified' end"],
        ],
    ],

    // ---------------------------------------------------------------- supplier_management
    'sup_suppliers' => [
        'module' => 'supplier_management',
        'label' => 'Supplier register',
        'from' => 'suppliers s ' . $supCat('s'),
        'company' => 's.company_id',
        'date' => 's.created_at',
        'where' => 's.deleted_at is null',
        'measures' => [
            'suppliers' => ['label' => 'Suppliers', 'sql' => 'count(*)', 'format' => 'number'],
            'preferred' => ['label' => 'Preferred / strategic', 'sql' => "count(*) filter (where s.is_preferred or s.segment in ('preferred', 'strategic'))", 'format' => 'number'],
            'bee_compliant' => ['label' => 'B-BBEE level 1–4', 'sql' => 'count(*) filter (where ' . $beeCompliant('s') . ')', 'format' => 'number'],
            'high_risk' => ['label' => 'High-risk suppliers', 'sql' => "count(*) filter (where s.risk_band in ('high', 'critical', 'very_high'))", 'format' => 'number'],
            'risk_total' => ['label' => 'Risk score (sum)', 'sql' => 'coalesce(sum(s.risk_score), 0)', 'format' => 'number'],
            'risk_scored' => ['label' => 'Suppliers risk-scored', 'sql' => 'count(s.risk_score)', 'format' => 'number'],
            'avg_risk' => ['label' => 'Average risk score', 'ratio' => ['risk_total', 'risk_scored'], 'format' => 'number'],
            'monitoring_due' => ['label' => 'Monitoring overdue', 'sql' => 'count(*) filter (where s.monitoring_due_at < now())', 'format' => 'number'],
            'bee_rate' => ['label' => 'B-BBEE level 1–4 %', 'ratio' => ['bee_compliant', 'suppliers'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'segment' => ['label' => 'Segment', 'sql' => $nice('s.segment', 'Unsegmented')],
            'risk_band' => ['label' => 'Risk band', 'sql' => $nice('s.risk_band', 'Not scored')],
            'risk_tier' => ['label' => 'Due-diligence tier', 'sql' => "coalesce('Tier ' || s.risk_tier::text, 'Not tiered')"],
            'bee_level' => ['label' => 'B-BBEE level', 'sql' => $beeLevel('s')],
            'category' => ['label' => 'Category', 'sql' => "coalesce(scat.name, 'Uncategorised')"],
            'entity_type' => ['label' => 'Entity type', 'sql' => "coalesce(nullif(s.business_entity_type, ''), 'Unknown')"],
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
        ],
    ],
    'sup_appraisals' => [
        'module' => 'supplier_management',
        'label' => 'Supplier performance appraisals',
        'from' => 'supplier_appraisals ap join suppliers s on s.id = ap.supplier_id ' . $supCat('s'),
        'company' => 'ap.company_id',
        'date' => 'coalesce(ap.appraisal_date, ap.created_at::date)',
        'where' => 'ap.deleted_at is null',
        'measures' => [
            'appraisals' => ['label' => 'Appraisals', 'sql' => 'count(*)', 'format' => 'number'],
            'suppliers' => ['label' => 'Suppliers appraised', 'sql' => 'count(distinct ap.supplier_id)', 'format' => 'number'],
            'score_total' => ['label' => 'Score (sum)', 'sql' => 'coalesce(sum(ap.total_score), 0)', 'format' => 'number'],
            'scored' => ['label' => 'Scored appraisals', 'sql' => 'count(ap.total_score)', 'format' => 'number'],
            'avg_score' => ['label' => 'Average score', 'ratio' => ['score_total', 'scored'], 'format' => 'number'],
            'poor' => ['label' => 'Poor appraisals (< 50)', 'sql' => 'count(*) filter (where ap.total_score < 50)', 'format' => 'number'],
        ],
        'dimensions' => [
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
            'category' => ['label' => 'Category', 'sql' => "coalesce(scat.name, 'Uncategorised')"],
            'segment' => ['label' => 'Segment', 'sql' => $nice('s.segment', 'Unsegmented')],
            'status' => ['label' => 'Status', 'sql' => $nice('ap.status')],
            'band' => ['label' => 'Score band', 'sql' => "case when ap.total_score is null then 'Not scored' when ap.total_score >= 80 then 'Excellent (80+)' when ap.total_score >= 65 then 'Good (65–79)' when ap.total_score >= 50 then 'Fair (50–64)' else 'Poor (< 50)' end"],
        ],
    ],
    'sup_invoices' => [
        'module' => 'supplier_management',
        'label' => 'Supplier invoices (3-way match)',
        'from' => 'supplier_invoices si left join suppliers s on s.id = si.supplier_id',
        'company' => 'si.company_id',
        'date' => 'coalesce(si.invoice_date, si.created_at::date)',
        'where' => 'si.deleted_at is null',
        'measures' => [
            'invoiced' => ['label' => 'Invoiced', 'sql' => 'coalesce(sum(si.amount), 0)', 'format' => 'currency'],
            'invoices' => ['label' => 'Invoices', 'sql' => 'count(*)', 'format' => 'number'],
            'matched' => ['label' => 'Matched', 'sql' => "count(*) filter (where si.match_status = 'matched')", 'format' => 'number'],
            'exceptions' => ['label' => 'Match exceptions', 'sql' => "count(*) filter (where si.match_status = 'exception' or si.status = 'exception')", 'format' => 'number'],
            'variance' => ['label' => 'Price / qty variance', 'sql' => 'coalesce(sum(abs(si.variance_amount)), 0)', 'format' => 'currency'],
            'unpaid' => ['label' => 'Unpaid', 'sql' => "coalesce(sum(si.amount) filter (where coalesce(si.payment_status, 'unpaid') <> 'paid' and si.status <> 'paid'), 0)", 'format' => 'currency'],
            'overdue' => ['label' => 'Overdue', 'sql' => "coalesce(sum(si.amount) filter (where coalesce(si.payment_status, 'unpaid') <> 'paid' and si.status <> 'paid' and si.due_date < current_date), 0)", 'format' => 'currency'],
            'no_po' => ['label' => 'Invoices without a PO', 'sql' => 'count(*) filter (where si.purchase_order_id is null)', 'format' => 'number'],
            'match_rate' => ['label' => 'Match rate %', 'ratio' => ['matched', 'invoices'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
            'match_status' => ['label' => 'Match status', 'sql' => $nice('si.match_status', 'Unmatched')],
            'payment_status' => ['label' => 'Payment status', 'sql' => $nice('si.payment_status', 'Unpaid')],
            'status' => ['label' => 'Status', 'sql' => $nice('si.status')],
        ],
    ],

    // ---------------------------------------------------------------- supplier_due_diligence
    'sup_verifications' => [
        'module' => 'supplier_due_diligence',
        'label' => 'Due-diligence checks',
        'from' => 'supplier_verifications v left join suppliers s on s.id = v.supplier_id',
        'company' => 'v.company_id',
        'date' => 'coalesce(v.checked_at, v.created_at)',
        'measures' => [
            'checks' => ['label' => 'Checks run', 'sql' => 'count(*)', 'format' => 'number'],
            'verified' => ['label' => 'Verified', 'sql' => "count(*) filter (where v.status = 'verified')", 'format' => 'number'],
            'mismatch' => ['label' => 'Mismatches', 'sql' => "count(*) filter (where v.status = 'mismatch')", 'format' => 'number'],
            'manual_review' => ['label' => 'Needs manual review', 'sql' => "count(*) filter (where v.status = 'manual_review')", 'format' => 'number'],
            'unavailable' => ['label' => 'Source unavailable', 'sql' => "count(*) filter (where v.status = 'unavailable')", 'format' => 'number'],
            'expired' => ['label' => 'Expired checks', 'sql' => 'count(*) filter (where v.valid_until < now())', 'format' => 'number'],
            'suppliers' => ['label' => 'Suppliers checked', 'sql' => 'count(distinct v.supplier_id)', 'format' => 'number'],
            'cost' => ['label' => 'Check cost', 'sql' => 'coalesce(sum(v.cost), 0)', 'format' => 'currency'],
            'pass_rate' => ['label' => 'Verified %', 'ratio' => ['verified', 'checks'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'check_type' => ['label' => 'Check', 'sql' => $nice('v.check_type')],
            'status' => ['label' => 'Result', 'sql' => $nice('v.status')],
            'provider' => ['label' => 'Provider', 'sql' => $nice('v.provider_code')],
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
        ],
    ],
    'sup_risk_flags' => [
        'module' => 'supplier_due_diligence',
        'label' => 'Supplier risk flags',
        'from' => 'supplier_risk_flags f left join suppliers s on s.id = f.supplier_id',
        'company' => 'f.company_id',
        'date' => 'f.created_at',
        'where' => 'f.deleted_at is null',
        'measures' => [
            'flags' => ['label' => 'Risk flags', 'sql' => 'count(*)', 'format' => 'number'],
            'open' => ['label' => 'Open flags', 'sql' => "count(*) filter (where f.status = 'open')", 'format' => 'number'],
            'critical' => ['label' => 'Critical / high', 'sql' => "count(*) filter (where f.severity in ('critical', 'high'))", 'format' => 'number'],
            'suppliers' => ['label' => 'Suppliers flagged', 'sql' => 'count(distinct f.supplier_id)', 'format' => 'number'],
            'resolved' => ['label' => 'Resolved', 'sql' => 'count(f.resolved_at)', 'format' => 'number'],
            'resolution_rate' => ['label' => 'Resolved %', 'ratio' => ['resolved', 'flags'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'type' => ['label' => 'Flag type', 'sql' => "initcap(replace(replace(coalesce(nullif(f.type, ''), 'other'), 'verification_', ''), '_', ' '))"],
            'severity' => ['label' => 'Severity', 'sql' => $nice('f.severity')],
            'status' => ['label' => 'Status', 'sql' => $nice('f.status')],
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
        ],
    ],
    'sup_findings' => [
        'module' => 'supplier_due_diligence',
        'label' => 'Adverse media & reputation findings',
        'from' => 'supplier_findings fd left join suppliers s on s.id = fd.supplier_id',
        'company' => 'fd.company_id',
        'date' => 'coalesce(fd.published_at, fd.first_seen_at, fd.created_at)',
        'measures' => [
            'findings' => ['label' => 'Findings', 'sql' => 'count(*)', 'format' => 'number'],
            'negative' => ['label' => 'Negative findings', 'sql' => 'count(*) filter (where not coalesce(fd.is_positive, false))', 'format' => 'number'],
            'serious' => ['label' => 'Critical / high', 'sql' => "count(*) filter (where fd.severity in ('critical', 'high'))", 'format' => 'number'],
            'open_negative' => ['label' => 'Negative, still open', 'sql' => "count(*) filter (where not coalesce(fd.is_positive, false) and fd.status = 'open')", 'format' => 'number'],
            'confirmed' => ['label' => 'Confirmed', 'sql' => "count(*) filter (where fd.status = 'confirmed')", 'format' => 'number'],
            'suppliers' => ['label' => 'Suppliers mentioned', 'sql' => 'count(distinct fd.supplier_id)', 'format' => 'number'],
            'negative_share' => ['label' => 'Negative %', 'ratio' => ['negative', 'findings'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'channel' => ['label' => 'Channel', 'sql' => $nice('fd.channel')],
            'category' => ['label' => 'Category', 'sql' => $nice('fd.category', 'Uncategorised')],
            'severity' => ['label' => 'Severity', 'sql' => $nice('fd.severity', 'None')],
            'sentiment' => ['label' => 'Sentiment', 'sql' => $nice('fd.sentiment')],
            'status' => ['label' => 'Status', 'sql' => $nice('fd.status')],
            'supplier' => ['label' => 'Supplier', 'sql' => $supName('s')],
        ],
    ],

    // ---------------------------------------------------------------- contract_management
    'con_obligations' => [
        'module' => 'contract_management',
        'label' => 'Contract obligations',
        'from' => 'contract_obligations o join contracts c on c.id = o.contract_id left join contract_types ct on ct.id = c.contract_type_id',
        'company' => 'o.company_id',
        'date' => 'coalesce(o.due_date::date, o.created_at::date)',
        'where' => 'o.deleted_at is null and c.deleted_at is null',
        'measures' => [
            'obligations' => ['label' => 'Obligations', 'sql' => 'count(*)', 'format' => 'number'],
            'met' => ['label' => 'Met', 'sql' => "count(*) filter (where o.status = 'met')", 'format' => 'number'],
            'breached' => ['label' => 'Breached', 'sql' => "count(*) filter (where o.status = 'breached')", 'format' => 'number'],
            'open' => ['label' => 'Open', 'sql' => "count(*) filter (where o.status in ('open', 'in_progress'))", 'format' => 'number'],
            'overdue' => ['label' => 'Overdue', 'sql' => "count(*) filter (where o.status in ('open', 'in_progress') and o.due_date::date < current_date)", 'format' => 'number'],
            'due_30' => ['label' => 'Due in 30 days', 'sql' => "count(*) filter (where o.status in ('open', 'in_progress') and o.due_date::date between current_date and current_date + 30)", 'format' => 'number'],
            'closed' => ['label' => 'Closed (met or breached)', 'sql' => "count(*) filter (where o.status in ('met', 'breached'))", 'format' => 'number'],
            'compliance_rate' => ['label' => 'Compliance %', 'ratio' => ['met', 'closed'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'status' => ['label' => 'Status', 'sql' => $nice('o.status')],
            'contract' => ['label' => 'Contract', 'sql' => "coalesce(c.title, 'Untitled')"],
            'type' => ['label' => 'Contract type', 'sql' => "coalesce(ct.name, 'Untyped')"],
            'owner' => ['label' => 'Owner', 'sql' => "coalesce(nullif(o.owner_name, ''), 'Unassigned')"],
            'timing' => ['label' => 'Due', 'sql' => "case when o.status in ('met', 'breached') then 'Closed' when o.due_date is null then 'No due date' when o.due_date::date < current_date then 'Overdue' when o.due_date::date <= current_date + 30 then 'Next 30 days' else 'Later' end"],
        ],
    ],

    // ---------------------------------------------------------------- tender_pursuit

    // ---------------------------------------------------------------- marketplace

    // ---------------------------------------------------------------- petty_cash
    'pc_requests' => [
        'module' => 'petty_cash',
        'label' => 'Petty cash requests',
        'from' => 'petty_cash_requests r left join petty_cash_floats f on f.id = r.float_id',
        'company' => 'r.company_id',
        'date' => 'coalesce(r.requested_at, r.created_at)',
        'where' => 'r.deleted_at is null',
        'measures' => [
            'requested' => ['label' => 'Requested', 'sql' => 'coalesce(sum(r.amount_requested), 0)', 'format' => 'currency'],
            'approved' => ['label' => 'Approved', 'sql' => 'coalesce(sum(r.amount_approved), 0)', 'format' => 'currency'],
            'issued' => ['label' => 'Cash issued', 'sql' => 'coalesce(sum(r.amount_issued), 0)', 'format' => 'currency'],
            'spent' => ['label' => 'Spent', 'sql' => 'coalesce(sum(r.amount_spent), 0)', 'format' => 'currency'],
            'returned' => ['label' => 'Returned', 'sql' => 'coalesce(sum(r.amount_returned), 0)', 'format' => 'currency'],
            'requests' => ['label' => 'Requests', 'sql' => 'count(*)', 'format' => 'number'],
            'unsettled' => ['label' => 'Issued, not settled', 'sql' => 'coalesce(sum(r.amount_issued - coalesce(r.amount_spent, 0) - coalesce(r.amount_returned, 0)) filter (where r.issued_at is not null and r.settled_at is null), 0)', 'format' => 'currency'],
            'receipts_overdue' => ['label' => 'Receipts overdue', 'sql' => 'count(*) filter (where not coalesce(r.receipt_submitted, false) and r.receipt_due_at < now())', 'format' => 'number'],
            'high_risk' => ['label' => 'High-risk requests', 'sql' => "count(*) filter (where r.risk_level in ('high', 'critical'))", 'format' => 'number'],
            'avg_request' => ['label' => 'Average request', 'ratio' => ['requested', 'requests'], 'format' => 'currency'],
        ],
        'dimensions' => [
            'category' => ['label' => 'Category', 'sql' => $nice('r.category', 'Uncategorised')],
            'status' => ['label' => 'Status', 'sql' => $nice('r.status')],
            'float' => ['label' => 'Float', 'sql' => "coalesce(f.name, 'Unknown')"],
            'risk' => ['label' => 'Risk level', 'sql' => $nice('r.risk_level', 'Not scored')],
        ],
    ],

    // ---------------------------------------------------------------- budget_management
    'bud_budgets' => [
        'module' => 'budget_management',
        'label' => 'Budgets vs actual',
        'from' => 'budgets b left join budget_categories bc on bc.id = b.budget_category_id left join budget_periods bp on bp.id = b.budget_period_id '
            . "left join lateral (select coalesce(sum(x.amount), 0) as amt from budget_commitments x where x.budget_id = b.id and x.status = 'active') com on true "
            . 'left join lateral (select coalesce(sum(x.amount), 0) as amt from budget_consumptions x where x.budget_id = b.id) con on true '
            . "left join lateral (select coalesce(sum(x.amount), 0) as amt from budget_reservations x where x.budget_id = b.id and x.status = 'active') res on true",
        'company' => 'b.company_id',
        'date' => 'coalesce(bp.start_date::date, b.created_at::date)',
        'where' => 'b.deleted_at is null',
        'measures' => [
            'allocated' => ['label' => 'Budget', 'sql' => 'coalesce(sum(b.allocated_amount), 0)', 'format' => 'currency'],
            'actual' => ['label' => 'Actual spend', 'sql' => 'coalesce(sum(con.amt), 0)', 'format' => 'currency'],
            'committed' => ['label' => 'Committed (open POs)', 'sql' => 'coalesce(sum(com.amt), 0)', 'format' => 'currency'],
            'reserved' => ['label' => 'Reserved (requests)', 'sql' => 'coalesce(sum(res.amt), 0)', 'format' => 'currency'],
            'used' => ['label' => 'Actual + committed', 'sql' => 'coalesce(sum(con.amt + com.amt), 0)', 'format' => 'currency'],
            'available' => ['label' => 'Still available', 'sql' => 'coalesce(sum(b.allocated_amount - con.amt - com.amt - res.amt), 0)', 'format' => 'currency'],
            'variance' => ['label' => 'Budget less actual', 'sql' => 'coalesce(sum(b.allocated_amount - con.amt), 0)', 'format' => 'currency'],
            'budgets' => ['label' => 'Budgets', 'sql' => 'count(*)', 'format' => 'number'],
            'over_budget' => ['label' => 'Budgets over-spent', 'sql' => 'count(*) filter (where con.amt + com.amt > b.allocated_amount)', 'format' => 'number'],
            'utilisation' => ['label' => 'Spent %', 'ratio' => ['actual', 'allocated'], 'scale' => 100, 'format' => 'percent'],
            'used_pct' => ['label' => 'Spent + committed %', 'ratio' => ['used', 'allocated'], 'scale' => 100, 'format' => 'percent'],
        ],
        'dimensions' => [
            'department' => ['label' => 'Department', 'sql' => "coalesce(nullif(b.scope_label, ''), 'Unassigned')"],
            'expenditure' => ['label' => 'Capex / opex', 'sql' => "coalesce(upper(nullif(b.expenditure_type, '')), 'Unknown')"],
            'category' => ['label' => 'Budget category', 'sql' => "coalesce(bc.name, 'Uncategorised')"],
            'period' => ['label' => 'Budget period', 'sql' => "coalesce(bp.name, 'Full year')"],
            'status' => ['label' => 'Status', 'sql' => $nice('b.status')],
            'budget' => ['label' => 'Budget', 'sql' => "coalesce(nullif(b.code, '') || ' ' || b.name, b.name, 'Unnamed')"],
            'health' => ['label' => 'Health', 'sql' => "case when b.allocated_amount <= 0 then 'No allocation' when con.amt + com.amt > b.allocated_amount then 'Over budget' when con.amt + com.amt > 0.9 * b.allocated_amount then 'Above 90%' when con.amt + com.amt > 0.5 * b.allocated_amount then '50–90%' else 'Under 50%' end"],
        ],
    ],
    'bud_consumptions' => [
        'module' => 'budget_management',
        'label' => 'Budget spend postings',
        'from' => 'budget_consumptions x join budgets b on b.id = x.budget_id',
        'company' => 'x.company_id',
        'date' => 'x.created_at',
        'where' => 'b.deleted_at is null',
        'measures' => [
            'spent' => ['label' => 'Spend posted', 'sql' => 'coalesce(sum(x.amount), 0)', 'format' => 'currency'],
            'postings' => ['label' => 'Postings', 'sql' => 'count(*)', 'format' => 'number'],
            'budgets' => ['label' => 'Budgets drawn on', 'sql' => 'count(distinct x.budget_id)', 'format' => 'number'],
            'avg_posting' => ['label' => 'Average posting', 'ratio' => ['spent', 'postings'], 'format' => 'currency'],
        ],
        'dimensions' => [
            'department' => ['label' => 'Department', 'sql' => "coalesce(nullif(b.scope_label, ''), 'Unassigned')"],
            'expenditure' => ['label' => 'Capex / opex', 'sql' => "coalesce(upper(nullif(b.expenditure_type, '')), 'Unknown')"],
            'budget' => ['label' => 'Budget', 'sql' => "coalesce(nullif(b.code, '') || ' ' || b.name, b.name, 'Unnamed')"],
            'source' => ['label' => 'Posted from', 'sql' => $nice('x.source_type', 'Manual')],
        ],
    ],
];
