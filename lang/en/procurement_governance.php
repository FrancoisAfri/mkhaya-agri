<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages
|--------------------------------------------------------------------------
|
| Everything this module says to a user from the server: the confirmation after
| an action, and the reason when the answer is no.
|
| These were English literals until now, which meant a user working in isiZulu or
| French got a fully translated interface that answered them in English the moment
| something went wrong — precisely when clear language matters most. A refusal
| nobody can read is indistinguishable from a broken system.
|
| The rules section carries the weight. Those messages explain why a tender cannot
| be opened, why a bid was refused, why an approval chain can never complete. They
| are read by people under deadline pressure who may have to defend the decision
| afterwards, so they say what happened and what to do about it.
|
*/

return [

    /*
    | Confirmations. Short, factual, and always naming what changed — "Saved" tells
    | someone nothing when six things on the screen could have been saved.
    */
    'flash' => [

        'approvals' => [
            'reason_required' => 'A rejection must record its reason.',
            'recorded_next_level' => 'Recorded. The approval has moved to the next level.',
            'decided' => 'Approval :status.',
        ],

        'committees' => [
            'created' => 'Committee :reference created.',
            'member_added' => 'Member added.',
            'membership_ended' => 'Membership ended. The historical record is retained.',
            'not_constituted' => 'The committee is not properly constituted: :problems',
            'activated' => 'Committee :reference activated.',
            'conflict_resolved' => 'Conflict resolved as :resolution.',
        ],

        'deviations' => [
            'created' => 'Deviation :reference created as a draft.',
            'updated' => 'Deviation updated.',
            'acceptance_reason_required' => 'Accepting compliance warnings requires a reason.',
            'cannot_submit' => 'Cannot submit: :problems',
            'submitted' => 'Deviation :reference submitted for approval.',
            'moved' => 'Deviation moved to :status.',
            'document_attached' => 'Document attached.',
            'type_saved' => 'Deviation type saved.',
        ],

        'documents' => [
            'version_uploaded' => 'New version uploaded. The previous version remains available.',
            'linked' => 'Document linked. It did not need to be uploaded again.',
            'acknowledged' => 'Acknowledgement recorded.',
            'access_granted' => 'Access granted.',
        ],

        'evaluations' => [
            'draft_saved' => 'Draft saved.',
            'submitted' => 'Evaluation submitted. It is now locked.',
            'conflict_declared' => 'Conflict declared. An administrator has been notified and you cannot score until it is resolved.',
            'declaration_recorded' => 'Declaration recorded.',
            'confidentiality_accepted' => 'Confidentiality undertaking accepted.',
            'not_your_clarification' => 'That clarification was raised for another evaluator. Only the evaluator it was addressed to may answer it.',
            'clarification_answered' => 'Your answer has been recorded and the clarification is closed.',
        ],

        'insights' => [
            'report_generated' => 'Report ":title" generated and stored.',
            'alert_updated' => 'Capacity alert updated.',
        ],

        'plans' => [
            'created' => 'Procurement plan :reference created.',
            'line_added' => 'Plan line :reference added.',
            'line_updated' => 'Plan line :reference updated.',
            'moved' => 'Plan moved to :status.',
            'only_approved_live' => 'Only an approved plan can have its lines taken live.',
            'line_live' => 'Plan line :line is live as :record.',
            'budget_unavailable' => 'Budget Management is not available, so committed and actual figures cannot be refreshed.',
            'budget_refreshed' => 'Budget figures refreshed.',
        ],

        'records' => [
            'moved' => 'Procurement moved to :stage.',
            'officer_updated' => 'Responsible officer updated.',
            'no_links_found' => 'No linked records were found in the other modules.',
            'links_found' => ':count link(s) discovered in the other modules.',
            'document_attached' => 'Document attached to this procurement.',
        ],

        'settings' => [
            'threshold_order' => 'The overloaded threshold must be higher than the high threshold.',
            'saved' => 'Procurement Governance settings saved.',
            'rule_set_created' => 'Rule set :code v:version created.',
            'rule_set_frozen' => 'Rule set frozen. Changes now require a new version.',
            'rule_set_is_frozen' => 'This rule set is frozen. Create a new version to change its rules.',
            'rule_saved' => 'Rule :code saved.',
            'rule_not_found' => 'Rule not found.',
            'rule_deactivated' => 'Rule deactivated.',
            'chain_saved' => 'Approval chain :code saved.',
            'chain_retired' => 'Approval chain :name retired.',
            'framework_saved' => 'Preference framework :name saved.',
            'framework_retired' => 'Preference framework :name retired.',
            'notification_saved' => 'Notification rule :code saved.',
            'category_saved' => 'Document category :code saved.',
        ],

        'tenders' => [
            'weights_total' => 'Price and preference weights must total 100; they total :total.',
            'initiated' => 'Tender review :reference initiated.',
            'criterion_added' => 'Criterion added.',
            'criterion_updated' => 'Criterion updated.',
            'criterion_removed' => 'Criterion removed.',
            'criteria_change_requested' => 'Criteria change requested. It applies once approved.',
            'invitation_created' => 'Invitation created.',
            'bid_withdrawn' => 'The sealed bid was withdrawn and can be submitted again.',
            'bids_opened' => ':count sealed bid(s) opened.',
            'bid_recorded' => 'Bid from :supplier recorded.',
            'bidder_disqualified' => 'Bidder disqualified.',
            'bidder_reinstated' => 'Bidder reinstated.',
            'committee_assigned' => 'Committee assigned; :count evaluator(s) added to this review.',
            'evaluation_blocked' => 'Evaluation cannot open yet: :problems',
            'evaluation_open' => 'Evaluation is open. The criteria are now frozen.',
            'moved' => 'Review moved to :status.',
            'scores_recomputed' => 'Committee scores and ranking recomputed.',
            'moderation_recorded' => 'Moderation recorded.',
            'moderation_not_open' => 'Moderation has not been opened for this review.',
            'scores_approved' => 'Final committee scores approved. A recommendation can now be generated.',
            'recommendation_generated' => 'Recommendation report :reference v:version generated.',
            'recommendation_submitted' => 'Recommendation submitted for approval.',
            'recommendation_decided' => 'Recommendation :decision.',
            'criteria_copied' => ':count criteria copied from the earlier tender.',
            'criteria_copy_empty' => 'That tender has no criteria to copy.',
            'criteria_copy_same' => 'A tender cannot copy its criteria from itself.',
        ],

        'portal' => [
            'bid_sealed' => 'Your bid has been sealed and received.',
        ],
    ],

    /*
    | Refusals. Each says what is wrong and what would fix it, because a rule the
    | user cannot act on is just an obstruction.
    */
    'rules' => [

        'chain' => [
            'step_needs_person' => 'Step :step approves by named person, so a person must be chosen.',
            'step_quorum_impossible' => 'Step :step names one person but asks for :quorum signatures, which can never be met. Use a role or permission to require several approvers.',
            'step_needs_approver' => 'Step :step approves by :what, so a :what must be chosen.',
            'not_found' => 'That approval chain no longer exists.',
            'in_flight' => ':name still has :count request(s) in flight. Clear those decisions first, or edit the chain instead of retiring it.',
            'only_default' => ':name is the only default for :subject. Make another chain the default first, otherwise the next request has nowhere to route.',
        ],

        'framework' => [
            'split_total' => 'The price and preference split must total 100; it totals :total.',
            'band_duplicate' => 'Band :band repeats ":value", which band :other already claims. The first would always win.',
            'band_above_max' => 'Band :band awards :points points, which is more than this framework\'s maximum of :max.',
            'not_found' => 'That preference framework no longer exists.',
            'in_use' => ':name is named by :count tender(s). Deactivate it instead, so those tenders keep the rules they were scored under.',
        ],

        'bid' => [
            'tender_closed' => 'This tender has closed, so no further bidders can be invited.',
            'invitation_withdrawn' => 'This invitation has been withdrawn.',
            'already_submitted' => 'A bid has already been submitted against this invitation. Ask the procurement officer to withdraw it if you need to replace it.',
            'amount_required' => 'A bid amount is required.',
            'invitation_not_found' => 'That invitation no longer exists.',
            'already_opened' => 'This bid has already been opened and cannot be withdrawn. Disqualify it instead, so the reason is on the record.',
            'tender_not_found' => 'That tender no longer exists.',
        ],

        'document' => [
            'already_opened' => 'This bid has already been opened, so no further documents can be attached to it.',
            'not_found' => 'That document no longer exists.',
            'not_opened_yet' => 'This bid has not been opened yet. Its documents stay sealed until the opening.',
            'digest_mismatch' => 'This bid document does not match the digest recorded when it was submitted. It is not the document that was submitted and must not be relied on.',
        ],

        'opening' => [
            'not_closed' => 'The tender has not closed yet. Bids stay sealed until the closing time.',
            'already_opened' => 'These bids have already been opened. Opening happens once.',
            'needs_two' => 'Opening needs two people: the officer opening and a witness.',
            'witness_distinct' => 'The witness must be someone other than the officer opening the bids.',
            'no_bids' => 'No bids were submitted for this tender.',
        ],

        'seal' => [
            'key_unavailable' => 'This tender\'s seal cannot be opened with the current application key. Bids sealed under a previous key cannot be recovered — restore the key before opening.',
            'decrypt_failed' => 'A sealed bid could not be decrypted. It may have been altered in storage; it must not be scored until that is explained.',
            'digest_mismatch' => 'A sealed bid does not match the digest recorded when it was submitted. The stored bid is not the bid that was submitted, and this tender must not be opened until that is investigated.',
            'not_a_bid' => 'A sealed bid decrypted to something that is not a bid.',
            'document_decrypt_failed' => 'A sealed bid document could not be decrypted.',
        ],

        'currency' => [
            'no_rate' => 'No :from to :to exchange rate is loaded for :date, so these bids cannot be compared. Load the rate in Finance, or record the bid in :to.',
        ],
    ],
];
