<?php

/**
 * Server-side strings for Budget Management.
 *
 * Anything a person reads that was produced by PHP rather than by a Vue page lives here:
 * notification bodies, the sentence a blocked purchase order comes back with, the reason a
 * transfer was refused, and every flash message. Page labels live in the frontend locale files.
 */
return [

    'approvals' => [
        // Engine
        'superseded'             => 'Superseded by a newer submission.',
        'not_yours'              => 'This approval is not yours to give.',
        'reason_required'        => 'A rejection has to say why.',
        'already_decided'        => 'That request has already been decided.',
        'withdrawn_by_submitter' => 'Withdrawn by the person who submitted it.',
        'auto_below_threshold'   => 'Approved on submission — below the routing threshold.',

        // What is being decided
        'subject_budget'      => 'Budget',
        'subject_adjustment'  => 'Budget adjustment',
        'subject_override'    => 'Overspend override',
        'subject_reservation' => 'Fund reservation',

        // Labels carried on the request, so the inbox reads without joining four tables
        'label_adjustment'  => ':type of :amount on :code',
        'label_override'    => 'Overspend of :amount on :code',
        'label_reservation' => 'Reservation of :amount on :code',

        'status_pending'   => 'Pending',
        'status_approved'  => 'Approved',
        'status_rejected'  => 'Rejected',
        'status_cancelled' => 'Withdrawn',

        // Notifications
        'mail_greeting'           => 'Hello,',
        'mail_requested_subject'  => ':subject awaiting your approval — :label',
        'mail_requested_line'     => 'A :subject has been submitted for your approval: :label (budget: :budget).',
        'mail_amount'             => 'Amount: :currency :amount',
        'mail_action'             => 'Open budget approvals',
        'mail_decided_subject'    => ':label — :status',
        'mail_decided_line'       => 'Your submission ":label" has been :status.',
        'mail_reason'             => 'Reason: :reason',
        'inapp_requested'         => 'A :subject needs your approval: :label',
        'inapp_decided'           => ':label was :status.',
    ],

    'alerts' => [
        'severity_info'     => 'For information',
        'severity_warning'  => 'Warning',
        'severity_critical' => 'Critical',
        'severity_block'    => 'Blocking',

        // The sentences a refused encumbrance comes back with. Each names the specific reason,
        // because "budget check failed" tells the person on the other end nothing they can act on.
        'blocked_by_rule'      => 'Blocked by the rule ":rule".',
        'blocked_overrun'      => 'This budget is already over by :amount. Request an increase or an overspend override before committing more.',
        'blocked_status'       => 'This budget is :status, so nothing can be committed against it yet.',
        'blocked_insufficient' => 'Not enough available: :requested requested, :available left.',

        // What an alert says on the page and in the email
        'msg_committed' => ':code is :percent% committed.',
        'msg_actual'    => ':code has spent :percent% of its allocation.',
        'msg_available' => ':code has :available left, below the :threshold you asked to be warned at.',
        'msg_overrun'   => ':code is over budget by :amount.',

        'mail_subject'  => ':severity — :budget',
        'mail_fallback' => 'A budget you own has crossed a threshold.',
        'mail_context'  => 'Budget :code · allocated :currency :allocated',
        'mail_action'   => 'Open budget tracking',
    ],

    'analytics' => [
        'uncategorised' => 'Uncategorised',
    ],

    'ledger' => [
        'already_committed'           => 'That reservation has already been converted into a commitment.',
        'already_consumed'            => 'That commitment has already been invoiced.',
        'reservation_not_on_budget'   => 'That reservation belongs to a different budget.',
        'commitment_not_on_budget'    => 'That commitment belongs to a different budget.',
    ],

    'errors' => [
        'transfer_target_missing'     => 'Transfer target budget not found — the transfer was rejected so the funds are not lost.',
        'transfer_exceeds_available'  => 'Only :available is free on this budget; the rest is already reserved or committed.',
        'transfer_from'               => 'Transferred from :code',
        'decrease_exceeds_available'  => 'Only :available is free on this budget, so it cannot be reduced by more than that.',
    ],

    'planning' => [
        'carried_from'      => 'Carried forward from :code (:year)',
        'close_blocked'     => 'This year still has :count budget(s) in draft or awaiting approval. Decide those first.',
        'year_needs_dates'  => 'This financial year needs a start and end date before periods can be generated.',
        'quarter_label'     => 'Q:n :year',
        'import_empty'      => 'That file has no rows.',
        'import_no_name'    => 'Line :line: no budget name.',
        'import_bad_amount' => 'Line :line: ":value" is not an amount.',
        // Warnings, not failures: the row imported with a default in place of what was written.
        'import_unknown_category' => 'Line :line: no category called ":value" — imported without one.',
        'import_unknown_type'     => 'Line :line: ":value" is not capex or opex — imported as opex.',
        'import_unknown_scope'    => 'Line :line: ":value" is not a scope this module knows — imported as general.',
    ],

    // Notes written on the ledger when another module posts spending, so a consumption row says
    // where it came from in words rather than as a source_type slug.
    'sources' => [
        'payroll'  => 'Payroll run :run',
        'fleet'    => 'Fleet cost :ref',
        'claim'    => 'Expense claim :ref',
        'contract' => 'Contract :ref',
    ],

    'sync' => [
        'mirrored_from' => 'Mirrored from the accounting budget ":budget". The allocation is maintained there.',
        'revised_from'  => 'Allocation changed in the accounting budget ":budget".',
        'managed_notice' => 'This budget is maintained in Finance › Accounting. Its name and allocation are set there and will be overwritten on the next sync; everything else — owners, phasing, alerts, spending — belongs here.',
    ],

    'flash' => [
        'budget_created'   => 'Budget created.',
        'budget_updated'   => 'Budget updated.',
        'budget_deleted'   => 'Budget deleted.',
        'budget_submitted' => 'Submitted for approval.',
        'budget_activated' => 'Budget approved and activated.',
        'not_submittable'  => 'Only a draft or rejected budget can be submitted.',

        'approval_recorded'  => 'Decision recorded.',
        'approval_rejected'  => 'Rejected, and the person who submitted it has been told.',
        'approval_withdrawn' => 'Withdrawn.',

        'flow_saved'   => 'Approval routing saved.',
        'flow_deleted' => 'Approval routing removed. Requests already in the queue keep their trail.',

        'rule_saved'         => 'Alert rule saved.',
        'rule_deleted'       => 'Alert rule removed.',
        'alert_acknowledged' => 'Acknowledged.',
        'sweep_done'         => ':raised raised, :resolved resolved.',

        'adjustment_submitted' => 'Adjustment submitted for approval.',
        'adjustment_applied'   => 'Adjustment applied.',
        'override_submitted'   => 'Overspend override submitted for approval.',
        'override_granted'     => 'Overspend override granted.',

        'reservation_submitted' => 'Reservation submitted for approval — it holds nothing until it is approved.',
        'reservation_held'      => 'Funds reserved.',
        'reservation_released'  => 'Reservation released.',
        'commitment_locked'     => 'Funds committed.',
        'commitment_released'   => 'Commitment released.',
        'spend_recorded'        => 'Spend recorded.',

        'owner_added'   => 'Owner added.',
        'owner_removed' => 'Owner removed.',

        'phasing_saved'   => 'Phasing saved.',
        'phasing_spread'  => 'Allocation spread evenly across the year.',
        'periods_generated' => ':count period(s) created.',
        'carried_forward' => ':created budget(s) carried forward, :skipped skipped.',
        'year_closed'     => 'Financial year closed.',
        'imported'        => ':count budget(s) imported as drafts.',

        /*
         * Delegation and roll-up were translated into all eleven other languages and never added
         * here, so an English user — most of them — got the raw key `budget_management.…` printed
         * under the form field as the validation error. Every other language read correctly.
         */
        'delegation_saved' => 'Cover arranged. They can decide your budget approvals from the start date.',
        'delegation_ended' => 'Cover ended.',
        'parent_saved'     => 'Roll-up saved.',
    ],

    /** Who is covering somebody else's budget approvals, and when. */
    'delegation' => [
        'to_yourself' => 'Delegating to yourself changes nothing.',
        'overlaps'    => 'This person already covers your budget approvals over those dates.',
    ],

    /** A budget that totals up into a parent budget. */
    'rollup' => [
        'self_parent'    => 'A budget cannot roll up into itself.',
        'different_year' => 'A budget can only roll up into one of the same financial year.',
        'cycle'          => 'That would create a loop — the budget you picked already rolls up into this one.',
    ],
];
