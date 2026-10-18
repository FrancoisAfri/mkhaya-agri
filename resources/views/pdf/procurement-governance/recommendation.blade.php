{{--
    §1.8 recommendation report.

    Rendered entirely from the frozen snapshot stored on the recommendation — no queries here — so
    the document reproduces exactly, whatever has changed since. The only live section is the
    approval history, which is stamped as read-at-render because an approver signs after the report
    exists.

    DomPDF's CSS support is narrow: no flexbox, no grid, no custom fonts. Tables and floats only.
--}}
@php
    $money = static function ($value, $currency = 'ZAR') {
        return $value === null ? '—' : $currency . ' ' . number_format((float) $value, 2);
    };
    $score = static fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $tender = $report['tender'] ?? [];
    $rec = $report['recommendation'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Recommendation report — {{ $tender['review_reference'] ?? '' }}</title>
    <style>
        @page { margin: 22mm 16mm 20mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1a1a1a; line-height: 1.45; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 7mm 0 2mm; padding-bottom: 1mm; border-bottom: 1px solid #999; }
        h3 { font-size: 9.5pt; margin: 4mm 0 1.5mm; }
        .sub { color: #555; font-size: 8.5pt; margin: 0 0 4mm; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        th, td { border: 1px solid #ccc; padding: 1.6mm 2mm; text-align: left; vertical-align: top; }
        th { background: #f1f1f1; font-weight: bold; font-size: 8.5pt; }
        td { font-size: 8.5pt; }
        .num { text-align: right; }
        .kv td:first-child { width: 38%; background: #fafafa; font-weight: bold; }
        .muted { color: #666; }
        .rec-box { border: 2px solid #1a1a1a; padding: 3mm; margin: 3mm 0 5mm; }
        .rec-box .name { font-size: 13pt; font-weight: bold; }
        .badge { font-size: 7.5pt; padding: 0.5mm 1.5mm; border: 1px solid #999; }
        .fail { color: #a11; font-weight: bold; }
        .pass { color: #171; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #666; }
        .page-break { page-break-after: always; }
        pre.note { white-space: pre-wrap; font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; margin: 0; }
    </style>
</head>
<body>

<div class="footer">
    {{ $tender['review_reference'] ?? '' }} — recommendation report. Integrity hash:
    {{ substr((string) ($report['integrity_hash'] ?? ''), 0, 16) }}…
    Rendered {{ $report['rendered_at'] ?? '' }}.
</div>

<h1>Tender evaluation — recommendation report</h1>
<p class="sub">
    {{ $tender['review_reference'] ?? '' }}
    @if(!empty($tender['tender_number'])) · Tender {{ $tender['tender_number'] }} @endif
    · Generated {{ $report['generated_at'] ?? '' }}
    @if(!empty($report['generated_by'])) by {{ $report['generated_by'] }} @endif
</p>

{{-- ── 1. Tender information ────────────────────────────────────────────────────────────────── --}}
<h2>1. Tender information</h2>
<table class="kv">
    <tr><td>Title</td><td>{{ $tender['title'] ?? '—' }}</td></tr>
    <tr><td>Category</td><td>{{ $tender['category'] ?? '—' }}</td></tr>
    <tr><td>Evaluation methodology</td><td>
        {{ $tender['methodology'] ?? '—' }}
        @if(!empty($tender['price_weight']))
            ({{ $tender['price_weight'] }}/{{ $tender['preference_weight'] }})
        @endif
    </td></tr>
    <tr><td>Estimated value</td><td>{{ $money($tender['estimated_value'] ?? null, $tender['currency'] ?? 'ZAR') }}</td></tr>
    <tr><td>Tender closed</td><td>{{ $tender['closing_at'] ?? '—' }}</td></tr>
    <tr><td>Evaluation period</td><td>{{ $tender['evaluation_start'] ?? '—' }} to {{ $tender['evaluation_deadline'] ?? '—' }}</td></tr>
    <tr><td>Criteria frozen</td><td>{{ $tender['criteria_frozen_at'] ?? '—' }}</td></tr>
    <tr><td>Required approval level</td><td>{{ $tender['required_approval_level'] ?? '—' }}</td></tr>
</table>

@if(!empty($tender['description']))
    <h3>Description</h3>
    <pre class="note">{{ $tender['description'] }}</pre>
@endif

{{-- ── 2. Committee ─────────────────────────────────────────────────────────────────────────── --}}
<h2>2. Evaluation committee</h2>
@if(!empty($report['committee']))
    <table class="kv">
        <tr><td>Committee</td><td>{{ $report['committee']['reference'] }} — {{ $report['committee']['name'] }}</td></tr>
        <tr><td>Type</td><td>{{ $report['committee']['type'] ?? '—' }}</td></tr>
        <tr><td>Appointed</td><td>{{ $report['committee']['appointment_date'] ?? '—' }}</td></tr>
        <tr><td>Quorum</td><td>{{ $report['committee']['quorum'] ?? '—' }}</td></tr>
    </table>
@endif

<table>
    <thead>
        <tr><th>Member</th><th>Role</th><th>Voting</th><th>External</th><th>Conflict of interest</th><th>Confidentiality</th><th>Status</th></tr>
    </thead>
    <tbody>
        @forelse($report['members'] ?? [] as $member)
            <tr>
                <td>{{ $member['name'] ?? '—' }}</td>
                <td>{{ $member['role'] ?? '—' }}</td>
                <td>{{ ($member['voting'] ?? false) ? 'Yes' : 'No' }}</td>
                <td>{{ ($member['external'] ?? false) ? 'Yes' : 'No' }}</td>
                <td class="{{ in_array($member['coi_status'] ?? '', ['DECLARED', 'APPROVED_TO_PARTICIPATE'], true) ? 'pass' : 'fail' }}">
                    {{ str_replace('_', ' ', $member['coi_status'] ?? '—') }}
                </td>
                <td>{{ ($member['confidentiality'] ?? false) ? 'Signed' : 'Not signed' }}</td>
                <td>{{ $member['status'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No committee members recorded.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ── 3. Criteria ──────────────────────────────────────────────────────────────────────────── --}}
<h2>3. Evaluation criteria</h2>
<table>
    <thead>
        <tr><th>Criterion</th><th>Group</th><th class="num">Weight</th><th class="num">Effective weight</th>
            <th class="num">Max score</th><th>Method</th><th class="num">Minimum</th><th>Mandatory</th></tr>
    </thead>
    <tbody>
        @forelse($report['criteria'] ?? [] as $criterion)
            <tr>
                <td>{!! $criterion['parent'] ? '&nbsp;&nbsp;&nbsp;&nbsp;↳ ' : '' !!}{{ $criterion['name'] }}</td>
                <td>{{ $criterion['group'] }}</td>
                <td class="num">{{ $score($criterion['weight']) }}%</td>
                <td class="num">{{ $score($criterion['effective_weight']) }}</td>
                <td class="num">{{ $score($criterion['max_score']) }}</td>
                <td>{{ $criterion['scoring_method'] }}</td>
                <td class="num">{{ $criterion['minimum_threshold'] !== null ? $score($criterion['minimum_threshold']) . '%' : '—' }}</td>
                <td>{{ ($criterion['is_mandatory'] ?? false) ? 'Yes' : 'No' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No criteria recorded.</td></tr>
        @endforelse
    </tbody>
</table>

<div class="page-break"></div>

{{-- ── 4. Participating suppliers and final ranking ─────────────────────────────────────────── --}}
<h2>4. Participating suppliers and final ranking</h2>
<table>
    <thead>
        <tr><th class="num">Rank</th><th>Supplier</th><th class="num">Bid amount</th><th class="num">Technical</th>
            <th class="num">Financial</th><th class="num">Preference</th><th class="num">Weighted</th>
            <th class="num">Price rank</th><th>Threshold</th><th>Status</th></tr>
    </thead>
    <tbody>
        @forelse($report['suppliers'] ?? [] as $supplier)
            <tr>
                <td class="num">{{ $supplier['overall_rank'] ?? '—' }}</td>
                <td>
                    {{ $supplier['name'] }}
                    @if($supplier['is_late'] ?? false) <span class="badge fail">LATE</span> @endif
                </td>
                <td class="num">{{ $money($supplier['bid_amount'], $supplier['currency'] ?? 'ZAR') }}</td>
                <td class="num">{{ $score($supplier['technical_score']) }}</td>
                <td class="num">{{ $score($supplier['financial_score']) }}</td>
                <td class="num">{{ $score($supplier['preference_score']) }}</td>
                <td class="num"><strong>{{ $score($supplier['weighted_score']) }}</strong></td>
                <td class="num">{{ $supplier['financial_rank'] ?? '—' }}</td>
                <td class="{{ ($supplier['threshold_passed'] ?? null) === false ? 'fail' : 'pass' }}">
                    {{ $supplier['threshold_passed'] === null ? '—' : (($supplier['threshold_passed']) ? 'Passed' : 'Failed') }}
                </td>
                <td>{{ $supplier['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="muted">No bids recorded.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ── 5. Individual evaluator scores ───────────────────────────────────────────────────────── --}}
<h2>5. Individual evaluator scores</h2>
<table>
    <thead>
        <tr><th>Evaluator</th><th>Supplier</th><th class="num">Raw</th><th class="num">Weighted</th>
            <th>Threshold</th><th class="num">Rev.</th><th>Submitted</th></tr>
    </thead>
    <tbody>
        @forelse($report['individual_scores'] ?? [] as $row)
            <tr>
                <td>{{ $row['evaluator'] ?? '—' }}</td>
                <td>{{ $row['supplier'] }}</td>
                <td class="num">{{ $score($row['raw_score']) }}</td>
                <td class="num">{{ $score($row['weighted_score']) }}</td>
                <td>{{ $row['threshold_passed'] === null ? '—' : ($row['threshold_passed'] ? 'Passed' : 'Failed') }}</td>
                <td class="num">{{ $row['revision'] }}</td>
                <td>{{ $row['submitted_at'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No submitted evaluations.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ── 6. Disqualifications ─────────────────────────────────────────────────────────────────── --}}
<h2>6. Disqualifications</h2>
@if(!empty($report['disqualifications']))
    <table>
        <thead><tr><th>Supplier</th><th>Stage</th><th>Reason</th><th>Date</th></tr></thead>
        <tbody>
            @foreach($report['disqualifications'] as $dq)
                <tr>
                    <td>{{ $dq['name'] }}</td>
                    <td>{{ $dq['stage'] ?? '—' }}</td>
                    <td>{{ $dq['reason'] }}</td>
                    <td>{{ $dq['at'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No bidders were disqualified.</p>
@endif

{{-- ── 7. Moderation ────────────────────────────────────────────────────────────────────────── --}}
<h2>7. Moderation</h2>
@if(!empty($report['moderation']))
    <table>
        <thead><tr><th>Type</th><th>Supplier</th><th>Criterion</th><th class="num">Variance</th>
                   <th>Action</th><th>Moderator comment</th><th>Evaluator response</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($report['moderation'] as $item)
                <tr>
                    <td>{{ $item['item_type'] }}</td>
                    <td>{{ $item['supplier_name'] ?? '—' }}</td>
                    <td>{{ $item['criterion'] ?? '—' }}</td>
                    <td class="num">{{ $item['variance'] !== null ? $score($item['variance']) . '%' : '—' }}</td>
                    <td>{{ $item['action'] ?? '—' }}</td>
                    <td>{{ $item['moderator_comment'] ?? '—' }}</td>
                    <td>{{ $item['evaluator_response'] ?? '—' }}</td>
                    <td>{{ $item['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No moderation items were raised.</p>
@endif

{{-- ── 8. Recommendation ────────────────────────────────────────────────────────────────────── --}}
<h2>8. Recommendation</h2>
<div class="rec-box">
    <div class="name">{{ $rec['supplier'] ?? '—' }}</div>
    <div>
        {{ $money($rec['amount'] ?? null, $rec['currency'] ?? 'ZAR') }}
        · Overall rank {{ $rec['overall_rank'] ?? '—' }}
        · Weighted score {{ $score($rec['weighted_score'] ?? null) }}
    </div>
</div>

@if(!empty($rec['narrative']))
    <h3>Recommendation</h3>
    <pre class="note">{{ $rec['narrative'] }}</pre>
@endif

@if(!empty($rec['committee_comments']))
    <h3>Committee comments</h3>
    <pre class="note">{{ $rec['committee_comments'] }}</pre>
@endif

@if(!empty($rec['conditions']))
    <h3>Award conditions</h3>
    <pre class="note">{{ $rec['conditions'] }}</pre>
@endif

{{-- ── 9. Approval history ──────────────────────────────────────────────────────────────────── --}}
<h2>9. Approval history</h2>
<p class="sub">Read live at {{ $report['rendered_at'] ?? '' }} — approvals continue after the report is generated.</p>
@if(!empty($report['approval_history']))
    <table>
        <thead><tr><th class="num">Step</th><th>Level</th><th>Approver</th><th>Decision</th><th>Decided by</th><th>Date</th><th>Comment</th></tr></thead>
        <tbody>
            @foreach($report['approval_history'] as $step)
                <tr>
                    <td class="num">{{ $step['step'] }}</td>
                    <td>{{ $step['name'] }}</td>
                    <td>{{ $step['approver'] ?? '—' }}</td>
                    <td>{{ $step['decision'] ?? $step['status'] }}</td>
                    <td>{{ $step['decided_by'] ?? '—' }}</td>
                    <td>{{ $step['decided_at'] ?? '—' }}</td>
                    <td>{{ $step['comment'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No approval had been raised when this report was rendered.</p>
@endif

{{-- ── 10. Supporting documents, compliance and audit ───────────────────────────────────────── --}}
<h2>10. Supporting documents</h2>
@if(!empty($report['documents']))
    <table>
        <thead><tr><th>Reference</th><th>Title</th><th>Role</th><th>Confidentiality</th><th class="num">Versions</th></tr></thead>
        <tbody>
            @foreach($report['documents'] as $doc)
                <tr>
                    <td>{{ $doc['reference'] ?? '—' }}</td>
                    <td>{{ $doc['title'] }}</td>
                    <td>{{ $doc['link_role'] }}</td>
                    <td>{{ $doc['confidentiality'] }}</td>
                    <td class="num">{{ $doc['version_count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No documents were attached to this tender review.</p>
@endif

<h2>11. Compliance and audit</h2>
@if(!empty($report['compliance']))
    <table>
        <thead><tr><th>Result</th><th>Rule set</th><th class="num">Rules</th><th class="num">Failures</th><th class="num">Warnings</th><th>Checked</th></tr></thead>
        <tbody>
            @foreach($report['compliance'] as $check)
                <tr>
                    <td class="{{ $check['result'] === 'FAIL' ? 'fail' : 'pass' }}">{{ $check['result'] }}</td>
                    <td>{{ $check['rule_set_code'] ?? '—' }} @if(!empty($check['rule_set_version'])) v{{ $check['rule_set_version'] }} @endif</td>
                    <td class="num">{{ $check['rules_evaluated'] }}</td>
                    <td class="num">{{ $check['failures'] }}</td>
                    <td class="num">{{ $check['warnings'] }}</td>
                    <td>{{ $check['checked_at'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No compliance checks were run against this tender review.</p>
@endif

<table class="kv">
    <tr><td>Audit events recorded</td><td>{{ $report['audit']['events'] ?? 0 }}</td></tr>
    <tr><td>Audit chain positions</td><td>
        {{ $report['audit']['first_sequence'] ?? '—' }} to {{ $report['audit']['latest_sequence'] ?? '—' }}
    </td></tr>
    <tr><td>Report integrity hash</td><td style="word-break: break-all;">{{ $report['integrity_hash'] ?? '—' }}</td></tr>
</table>

<p class="sub">
    This report was generated from a frozen snapshot taken at {{ $report['generated_at'] ?? '' }}. The
    integrity hash above covers every figure in sections 1 to 8; any alteration to the stored
    snapshot changes it.
</p>

</body>
</html>
