<?php

/**
 * Server-side strings for Supplier Management.
 *
 * These are the messages the module says rather than the labels it displays — the screen text lives
 * in the JS locale files. Almost all of them are refusals, and a refusal has one job: tell the person
 * what the system will not do and what would make it possible. "Invalid status" is not that; "a
 * closed event cannot be published again" is.
 */
return [

    // Supplier status values, by the `is_active` integer stored on the row.
    'statuses' => [
        0 => 'Pending',
        1 => 'Active',
        2 => 'Refused',
        3 => 'Pending KYC',
    ],

    'suppliers' => [
        'activated'             => 'Supplier activation processed.',
        'refused'               => 'Supplier refused, and told why.',
        'info_requested'        => 'Information request sent to the supplier.',
        'portal_link_rotated'   => 'A new portal link has been issued. The previous link no longer works.',
        'portal_link_reissued'  => 'Your portal link has been replaced for security. Use the new link below.',
        'outstanding_documents' => 'We still need the following from you: :documents',

        'errors' => [
            'duplicate'         => 'This registration number or email already belongs to :supplier (:number). Open that record instead of creating a second one.',
            'missing_documents' => 'This supplier cannot be activated until these compulsory documents are on file and in date: :documents',
            'blacklisted'       => 'This party is on the blacklist — :matched. Reason on record: :reason. If this is a different party, or the bar should be lifted, that is an executive decision before the supplier can be added.',
        ],
    ],

    'onboarding' => [
        'invitation_sent' => 'Invitation sent. The previous link, if any, no longer works.',
        'mail_subject'    => 'You have been invited to register as a supplier to :company',
        'mail_greeting'   => 'Hello :name,',
        'mail_intro'      => ':company would like to add you to their approved supplier list. Registering takes a few minutes.',
        'mail_action'     => 'Start registration',
        'mail_expiry'     => 'This link works until :date. If it lapses, ask your contact to send a new one.',
        'mail_closing'    => 'Having the documents above to hand before you start will make this quicker.',
    ],

    'decisions' => [
        'greeting'      => 'Hello :name,',
        'comment_label' => 'Notes from the reviewer:',
        'mail_action'   => 'Open your supplier portal',
        'mail_closing'  => 'Reply to this email if anything here looks wrong.',

        'approved' => [
            'mail_subject' => 'You are now an approved supplier to :company',
            'mail_body'    => 'Your registration has been approved. :company can now raise orders with you.',
        ],
        'refused' => [
            'mail_subject' => 'Your supplier registration with :company',
            'mail_body'    => ':company is not able to proceed with your registration at this time.',
        ],
        'information_requested' => [
            'mail_subject' => ':company needs more information from you',
            'mail_body'    => 'Your registration is on hold until we receive a little more from you.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Your new portal link for :company',
            'mail_body'    => 'Your portal link has been replaced for security. The previous link no longer works — use the button below from now on.',
        ],
    ],

    'kyc' => [
        'approved'     => 'KYC approved; the supplier is now active.',
        'rejected'     => 'KYC rejected, and the supplier has been told why.',
        'mail_subject' => 'KYC review needed: :supplier',
        'mail_greeting' => 'Hello,',
        'mail_body'    => ':supplier is waiting on a KYC decision.',
        'mail_action'  => 'Review the KYC queue',

        'errors' => [
            'already_decided' => 'This submission was already :status. Reopen the supplier if the decision needs revisiting.',
            'self_review'     => 'You raised this supplier, so you cannot also clear its KYC. It needs a second reviewer.',
        ],
    ],

    'rfx' => [
        'published'        => 'Event published. Invited suppliers may now bid.',
        'closed'           => 'Event closed. No further bids will be accepted.',
        'response_recorded' => 'Bid recorded.',
        'evaluated'        => 'AI bid evaluation complete.',
        'awarded'          => 'Event awarded.',

        'statuses' => [
            'draft'     => 'draft',
            'published' => 'published',
            'closed'    => 'closed',
            'awarded'   => 'awarded',
            'cancelled' => 'cancelled',
        ],

        'errors' => [
            'invalid_transition'          => 'An event that is :from cannot be moved to :to.',
            'publish_without_invitations' => 'Invite at least one supplier before publishing — an event nobody was asked to bid on cannot be awarded.',
            'publish_after_closing'       => 'The closing date has already passed. Move it forward before publishing.',
            'bid_when_not_published'      => 'Bids can only be recorded while an event is published. This one is :status.',
            'bid_after_closing'           => 'This event closed on :date. A late bid cannot be accepted.',
            'bid_from_uninvited'          => ':supplier was not invited to this event. Invite them first if they should be taking part.',
            'award_without_bid'           => ':supplier did not bid on this event, so it cannot be awarded to them.',
            'award_reason_required'       => 'This is not the lowest bid, so the award needs a reason on record.',
        ],
    ],

    'orders' => [
        'created' => 'Purchase order created.',
        'updated' => 'Purchase order updated.',
        'removed' => 'Purchase order removed, and any funds it held have been released.',

        'statuses' => [
            'draft'     => 'draft',
            'approved'  => 'approved',
            'received'  => 'received',
            'closed'    => 'closed',
            'cancelled' => 'cancelled',
        ],

        'errors' => [
            'supplier_not_active'  => 'This supplier is :status and cannot receive new purchase orders until reactivated.',
            'expired_documents'    => 'This supplier has expired compulsory documents and cannot receive new orders: :documents',
            'invalid_transition'   => 'A :from order cannot be moved to :to.',
            'self_approval'        => 'You raised this order, so you cannot also approve it. It needs a second approver.',
        ],
    ],

    'invoices' => [
        'captured'              => 'Invoice captured.',
        'captured_with_variance' => 'Invoice captured, but it is :variance over purchase order :po. It is held for review and cannot be paid until that is resolved.',
        'updated'               => 'Invoice updated.',
        'removed'               => 'Invoice removed, and the spend taken back off the budget.',

        'errors' => [
            'duplicate_number'                 => 'Invoice :number has already been captured for this supplier. Open the existing one rather than entering it twice.',
            'order_belongs_to_other_supplier'  => 'Purchase order :po was raised for a different supplier, so this invoice cannot be matched to it.',
            'pay_with_open_variance'           => 'This invoice is over its purchase order and is held for review. Resolve the variance before marking it paid.',
            'pay_with_unverified_banking'      => 'Payment is held on this supplier\'s banking. :reason Verify the account against the supplier before paying.',
        ],
    ],

    // The paid add-on, and what other modules are told when it is not on.
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence is not part of this subscription. It adds registry, tax, banking, ownership, credit and sanctions verification to supplier management — speak to Mkhaya to add it.',
    ],

    // Short lines other modules show their own users about a supplier's due-diligence position.
    'verdict' => [
        'never_assessed' => 'Due diligence has not been run on this supplier.',
        'cleared'        => 'Due diligence passed — risk :score/100, every gate clear.',
        'blocked'        => 'Due diligence incomplete — risk :score/100, :count outstanding item(s).',
        'sanctions'      => 'A live sanctions match is recorded against this supplier.',
    ],

    // Risk assessment and screening — server-side messages.
    'risk' => [
        'assessed'              => 'Assessed: :score out of 100 (:band).',
        'tiered'                => 'This engagement is tier :tier — :label. The checks it requires have been updated.',
        'screened'              => 'Screened :subjects subject(s). :new new match(es) need adjudicating.',
        'screening_unavailable' => 'No screening source is configured, so nothing was checked. Record what you find on the published lists, or configure a source under Configure → Data sources.',
        'hit_recorded'          => 'Match recorded. It counts against this supplier until somebody adjudicates it.',
        'adjudicated'           => 'Decision recorded. It will not be raised again by a later screening.',
        'credit_recorded'       => 'Credit report captured.',

        'errors' => [
            'reason_required' => 'Say why this match is being cleared. An unexplained dismissal cannot be told apart from a mis-click a year from now.',
        ],
    ],

    // Questionnaire packs.
    'questionnaires' => [
        'sent'     => ':n questionnaire(s) sent to the supplier.',
        'recorded' => 'Answers saved — :completeness% complete.',
        'reviewed' => 'Questionnaire marked reviewed.',
    ],

    // Routed alerts.
    'alerts' => [
        'subject'       => '[:severity] Supplier alert — :supplier',
        'greeting'      => 'Hello,',
        'context'       => ':supplier is currently banded :band.',
        'action'        => 'Open the supplier passport',
        'db_title'      => 'Supplier alert: :supplier',
        'routing_saved' => 'Alert routing saved.',
    ],

    // The monitoring inbox — server-side messages.
    'monitoring' => [
        'change_reviewed' => 'Decision recorded. This change will not appear in the inbox again.',
        'swept'           => 'Re-ran :checks check(s); :changes change(s) detected.',
        'no_sources'      => 'No source is configured for any of the checks that were due, so nothing was re-run. The gaps are recorded against the supplier rather than hidden.',

        'errors' => [
            'reason_required' => 'Say why this change is being dismissed. A year from now, an unexplained dismissal cannot be told apart from a mis-click.',
        ],
    ],

    // Due-diligence cases, sign-off and the blacklist.
    'cases' => [
        'opened'           => 'Case :reference opened.',
        'moved'            => 'Case moved to :state.',
        'edd_recorded'     => 'Enhanced due-diligence item recorded.',
        'blacklisted'      => 'Added to the blacklist. Onboarding will now warn on this party, on its people, and on anything it appears in the ownership of.',
        'blacklist_lifted' => 'Blacklist entry lifted. The history stays on record.',
        'roles_saved'      => 'Sign-off roles saved.',

        'errors' => [
            'waiver_reason'   => 'Say why this item is being waived. An enhanced due-diligence pack that can be waived silently is decoration.',
            'blacklist_slot'  => 'Blacklisting is an executive decision. Ask somebody mapped to the Executive slot, or map yourself to it first.',
        ],
    ],

    // Reputation and social monitoring.
    'reputation' => [
        'ran'            => 'Searched :sources source(s). :new new finding(s); :review could not be tied to this supplier and need review.',
        'no_sources'     => 'No news or social source is configured, so nothing was searched. A supplier nobody could search must not be mistaken for one nothing was found about — configure a source under Configure → Data sources.',
        'adjudicated'    => 'Decision recorded. This finding will not be raised again by a later run.',
        'handle_added'   => 'Added to what is watched for this supplier.',
        'handle_removed' => 'Removed from what is watched.',

        'errors' => [
            'reason_required' => 'Say why this finding is being cleared. An unexplained dismissal cannot be told apart from a mis-click a year from now.',
        ],
    ],

    // Ownership chain — server-side messages.
    'ownership' => [
        'added'   => 'Ownership stake recorded.',
        'updated' => 'Ownership stake updated.',

        'errors' => [
            'self_ownership'  => 'A company cannot hold a stake in itself. Pick a different holder, or a different company for it to hold.',
            'holder_required' => 'Name the holder, or pick somebody already on file.',
        ],
    ],

    // Due diligence verifications — messages the server sends back after a check.
    'verifications' => [
        'ran'       => ':check: :status.',
        'recorded'  => ':check recorded as :status.',
        'pin_saved' => 'TCS PIN saved. Tax compliance status can now be verified for this supplier.',

        'errors' => [
            'no_source' => 'No source is configured for :check, so it cannot be run automatically. Record the check yourself, or configure a source under Configure → Data sources.',
        ],
    ],

    'appraisals' => [
        'poor_score_flag' => 'Scored :score% against a review threshold of :threshold%. Check this supplier before placing further work.',
        'flash' => [
            'template_created' => 'Scorecard created. Add the questions it should ask.',
            'template_saved' => 'Scorecard saved.',
            'template_removed' => 'Scorecard removed.',
            'template_in_use' => 'This scorecard has been used for an appraisal, so it cannot be deleted — set its status to retired instead.',
            'question_added' => 'Question added.',
            'question_saved' => 'Question saved.',
            'question_removed' => 'Question removed.',
            'weight_over' => 'The weights on this scorecard may total 100%. There is :remaining% left to assign.',
        ],
    ],

    'portal' => [
        'link_expired' => 'This portal link has expired. Ask your contact for a new one.',
    ],

    'budget' => [
        'po_note'      => 'Purchase order :po',
        'invoice_note' => 'Supplier invoice :number',
    ],


    'registration' => [
        'mail_subject'      => ':supplier has completed their supplier registration',
        'mail_body'         => ':supplier has filled in their registration and is waiting to be reviewed.',
        'mail_action'       => 'Review the supplier',
        'submitted'         => 'Thank you — your details are with :company for review.',
        'link_expired'      => 'This registration link has expired. Ask your contact for a new invitation.',
        'already_registered' => 'This invitation has already been used to register.',
    ],

];
