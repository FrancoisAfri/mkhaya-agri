<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (ts)
|--------------------------------------------------------------------------
|
| Mirrors lang/en/procurement_governance.php. Placeholders such as :reference
| and :count are part of the string and must survive translation — a message
| that loses one renders the placeholder name to the user.
|
*/

return [

    'flash' => [
        'approvals' => [
            'reason_required' => 'Ku ala ku fanele ku tsala xivangelo xa kona.',
            'recorded_next_level' => 'Swi tsariwile. Mpfumelelo wu yile eka xiyimo lexi landzelaka.',
            'decided' => 'Mpfumelelo :status.',
        ],
        'committees' => [
            'created' => 'Komiti :reference yi endliwile.',
            'member_added' => 'Xirho xi engetiwile.',
            'membership_ended' => 'Vuxirho byi herile. Rhekhodo ya matimu yi hlayisiwile.',
            'not_constituted' => 'Komiti a yi vumbiwanga kahle: :problems',
            'activated' => 'Komiti :reference ya tirha.',
            'conflict_resolved' => 'Ku lwisana ku lulamisiwile tanihi :resolution.',
        ],
        'deviations' => [
            'created' => 'Ku hambuka :reference ku endliwile tanihi xitsariwana.',
            'updated' => 'Ku hambuka ku pfuxetiwile.',
            'acceptance_reason_required' => 'Ku amukela switsundzuxo swa ku landzelela ku lava xivangelo.',
            'cannot_submit' => 'A swi koteki ku rhumela: :problems',
            'submitted' => 'Ku hambuka :reference ku rhumeriwile leswaku ku amukeriwa.',
            'moved' => 'Ku hambuka ku yisiwile eka :status.',
            'document_attached' => 'Tsalwa ri namarhetiwile.',
            'type_saved' => 'Muxaka wa ku hambuka wu hlayisiwile.',
        ],
        'documents' => [
            'version_uploaded' => 'Nhundzuluxo lowuntshwa wu nghenisiwile. Nhundzuluxo wa khale wu ha ri kona.',
            'linked' => 'Tsalwa ri hlanganisiwile. A swi lavekanga ku ri nghenisa nakambe.',
            'acknowledged' => 'Ku amukela ku tsariwile.',
            'access_granted' => 'Ku nghena ku nyikiwile.',
        ],
        'evaluations' => [
            'draft_saved' => 'Xitsariwana xi hlayisiwile.',
            'submitted' => 'Nkameto wu rhumeriwile. Sweswi wu pfariwile.',
            'conflict_declared' => 'Ku lwisana ku tivisiwile. Mulawuri u tivisiwile naswona a wu nge nyiki tinhlayo ku kondza swi lulamisiwa.',
            'declaration_recorded' => 'Xitiviso xi tsariwile.',
            'confidentiality_accepted' => 'Ku tiboha ka xihundla ku amukeriwile.',
            'not_your_clarification' => 'Nhlamuselo yoleyo yi tlakusiwile eka muxopaxopi un’wana. I muxopaxopi loyi yi kongomisiweke eka yena ntsena la nga hlamulaka.',
            'clarification_answered' => 'Nhlamulo ya wena yi rhekhodiwile naswona nhlamuselo yi pfariwile.',
        ],
        'insights' => [
            'report_generated' => 'Xiviko lexi nge ":title" xi endliwile naswona xi hlayisiwile.',
            'alert_updated' => 'Xitsundzuxo xa vuswikoti xi pfuxetiwile.',
        ],
        'plans' => [
            'created' => 'Kungu ra ku xava :reference ri endliwile.',
            'line_added' => 'Layini ya kungu :reference yi engetiwile.',
            'line_updated' => 'Layini ya kungu :reference yi pfuxetiwile.',
            'moved' => 'Kungu ri yisiwile eka :status.',
            'only_approved_live' => 'I kungu leri amukeriweke ntsena leri nga endlaka leswaku milayini ya rona yi tirha.',
            'line_live' => 'Layini ya kungu :line ya tirha tanihi :record.',
            'budget_unavailable' => 'Vulawuri bya Mpimanyeto a byi kumeki, hikwalaho tinhlayo leti tibohaka na ta ntiyiso a ti nge pfuxetiwi.',
            'budget_refreshed' => 'Tinhlayo ta mpimanyeto ti pfuxetiwile.',
        ],
        'records' => [
            'moved' => 'Ku xava ku yisiwile eka :stage.',
            'officer_updated' => 'Mutirhi la nga na vutihlamuleri byi pfuxetiwile.',
            'no_links_found' => 'A ku na tirhekhodo leti hlanganisiweke leti kumiweke eka timoduli tin\'wana.',
            'links_found' => 'Swihlanganisi swa :count swi kumiwile eka timoduli tin\'wana.',
            'document_attached' => 'Tsalwa ri namarhetiwile eka ku xava loku.',
        ],
        'settings' => [
            'threshold_order' => 'Ndzilakano wo tikeriwa wu fanele ku va ehenhla ka ndzilakano lowukulu.',
            'saved' => 'Swilulamiso swa Vulawuri bya ku Xava swi hlayisiwile.',
            'rule_set_created' => 'Sete ya milawu :code v:version yi endliwile.',
            'rule_set_frozen' => 'Sete ya milawu yi pfariwile. Ku cinca sweswi ku lava nhundzuluxo lowuntshwa.',
            'rule_set_is_frozen' => 'Sete leyi ya milawu yi pfariwile. Endla nhundzuluxo lowuntshwa leswaku u cinca milawu ya yona.',
            'rule_saved' => 'Nawu :code wu hlayisiwile.',
            'rule_not_found' => 'Nawu a wu kumeki.',
            'rule_deactivated' => 'Nawu wu yimisiwile.',
            'chain_saved' => 'Nketani wa mpfumelelo :code wu hlayisiwile.',
            'chain_retired' => 'Nketani wa mpfumelelo :name wu yimisiwile.',
            'framework_saved' => 'Xivumbeko xa ku hlawuleka :name xi hlayisiwile.',
            'framework_retired' => 'Xivumbeko xa ku hlawuleka :name xi yimisiwile.',
            'notification_saved' => 'Nawu wa xitiviso :code wu hlayisiwile.',
            'category_saved' => 'Ntlawa wa tsalwa :code wu hlayisiwile.',
        ],
        'tenders' => [
            'weights_total' => 'Ntikelo wa nxavo na ku hlawuleka wu fanele ku va 100; wu eka :total.',
            'initiated' => 'Nkambelo wa thendara :reference wu sunguriwile.',
            'criterion_added' => 'Xipimelo xi engetiwile.',
            'criterion_updated' => 'Xipimelo xi pfuxetiwile.',
            'criterion_removed' => 'Xipimelo xi susiwile.',
            'criteria_change_requested' => 'Ku cinca ka swipimelo ku komberiwile. Swi tirha loko swi amukeriwile.',
            'invitation_created' => 'Xirhambo xi endliwile.',
            'bid_withdrawn' => 'Xikombelo lexi pfariweke xi susiwile naswona xi nga rhumeriwa nakambe.',
            'bids_opened' => 'Swikombelo leswi pfariweke swa :count swi pfuriwile.',
            'bid_recorded' => 'Xikombelo lexi humaka eka :supplier xi tsariwile.',
            'bidder_disqualified' => 'Mukomberi u susiwile.',
            'bidder_reinstated' => 'Mukomberi u tlheriseriwile.',
            'committee_assigned' => 'Komiti yi averiwile; vakameti va :count va engetiwile eka nkambelo lowu.',
            'evaluation_blocked' => 'Nkameto a wu nge pfuriwi sweswi: :problems',
            'evaluation_open' => 'Nkameto wu pfulekile. Swipimelo sweswi swi pfariwile.',
            'moved' => 'Nkambelo wu yisiwile eka :status.',
            'scores_recomputed' => 'Tinhlayo ta komiti na ku landzelelana swi hlayeriwile nakambe.',
            'moderation_recorded' => 'Ku lulamisa ku tsariwile.',
            'moderation_not_open' => 'Ku lulamisa a ku pfuriwanga eka nkambelo lowu.',
            'scores_approved' => 'Tinhlayo to hetelela ta komiti ti amukeriwile. Xibumabumelo sweswi xi nga endliwa.',
            'recommendation_generated' => 'Xiviko xa xibumabumelo :reference v:version xi endliwile.',
            'recommendation_submitted' => 'Xibumabumelo xi rhumeriwile leswaku xi amukeriwa.',
            'recommendation_decided' => 'Xibumabumelo :decision.',
            'criteria_copied' => 'Swipimelo swa :count swi kopiwile eka thenda yo sungula.',
            'criteria_copy_empty' => 'Thenda yoleyo a yi na swipimelo leswi nga kopiwaka.',
            'criteria_copy_same' => 'Thenda a yi nge koti ku kopa swipimelo swa yona eka yona.',
        ],
        'portal' => [
            'bid_sealed' => 'Xikombelo xa wena xi pfariwile naswona xi amukeriwile.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Goza :step ri amukela hi munhu la boxiweke, hikwalaho ku fanele ku hlawuriwa munhu.',
            'step_quorum_impossible' => 'Goza :step ri boxa munhu un\'we kambe ri kombela minsayino ya :quorum, leswi swi nga ta ka swi nga koteki. Tirhisa xiavelo kumbe mpfumelelo loko u lava va-amukeri vo tala.',
            'step_needs_approver' => 'Goza :step ri amukela hi :what, hikwalaho ku fanele ku hlawuriwa :what.',
            'not_found' => 'Nketani wolowo wa mpfumelelo a wa ha ri kona.',
            'in_flight' => ':name wu ha ri na swikombelo swa :count endleleni. Hetisisa swiboho sweswo ku sungula, kumbe lulamisa nketani ematshan\'weni yo wu yimisa.',
            'only_default' => ':name hi wona ntsena wa masungulo wa :subject. Endla leswaku nketani wun\'wana wu va wa masungulo ku sungula, handle ka sweswo xikombelo lexi landzelaka a xi na laha xi nga yaka kona.',
        ],
        'framework' => [
            'split_total' => 'Ku avanyisiwa ka nxavo na ku hlawuleka ku fanele ku va 100; ku eka :total.',
            'band_duplicate' => 'Bendi :band ri phindha ":value", leri bendi :other ri se ri ri tekeke. Ro sungula a ri ta hlula nkarhi hinkwawo.',
            'band_above_max' => 'Bendi :band ri nyika tinhlayo ta :points, leti tlulaka ndzilakano wa xivumbeko lexi wa :max.',
            'not_found' => 'Xivumbeko xexo xa ku hlawuleka a xi ha ri kona.',
            'in_use' => ':name yi boxiwile hi tithendara ta :count. Yi yimise ematshan\'weni ya sweswo, leswaku tithendara teto ti hlayisa milawu leyi ti nyikiweke tinhlayo hi yona.',
        ],
        'bid' => [
            'tender_closed' => 'Thendara leyi yi pfariwile, hikwalaho a ku na vakomberi van\'wana lava nga rhambiwaka.',
            'invitation_withdrawn' => 'Xirhambo lexi xi susiwile.',
            'already_submitted' => 'Xikombelo xi se xi rhumeriwile ehansi ka xirhambo lexi. Kombela mutirhi wa ku xava leswaku a xi susa loko u lava ku xi cinca.',
            'amount_required' => 'Ku laveka nkoka wa xikombelo.',
            'invitation_not_found' => 'Xirhambo xexo a xi ha ri kona.',
            'already_opened' => 'Xikombelo lexi xi se xi pfuriwile naswona a xi nge susiwi. Xi susa hi ku xi ala ematshan\'weni ya sweswo, leswaku xivangelo xi va eka rhekhodo.',
            'tender_not_found' => 'Thendara yoleyo a yi ha ri kona.',
        ],
        'document' => [
            'already_opened' => 'Xikombelo lexi xi se xi pfuriwile, hikwalaho a ku na matsalwa man\'wana lama nga namarhetiwaka eka xona.',
            'not_found' => 'Tsalwa rero a ri ha ri kona.',
            'not_opened_yet' => 'Xikombelo lexi a xi si pfuriwa. Matsalwa ya xona ma tshama ma pfariwile ku kondza ku pfuriwa.',
            'digest_mismatch' => 'Tsalwa leri ra xikombelo a ri fambelani na nhlayo leyi tsariweke loko ri rhumeriwa. A hi rona tsalwa leri rhumeriweke naswona a ri fanelanga ku tshembiwa.',
        ],
        'opening' => [
            'not_closed' => 'Thendara a yi si pfariwa. Swikombelo swi tshama swi pfariwile ku kondza nkarhi wo pfala.',
            'already_opened' => 'Swikombelo leswi swi se swi pfuriwile. Ku pfula ku humelela kan\'we.',
            'needs_two' => 'Ku pfula ku lava vanhu vambirhi: mutirhi la pfulaka na mbhoni.',
            'witness_distinct' => 'Mbhoni yi fanele ku va munhu un\'wana ku nga ri mutirhi la pfulaka swikombelo.',
            'no_bids' => 'A ku na swikombelo leswi rhumeriweke eka thendara leyi.',
        ],
        'seal' => [
            'key_unavailable' => 'Xifungho xa thendara leyi a xi nge pfuriwi hi xilotlelo xa sweswi xa xitirhisiwa. Swikombelo leswi pfariweke hi xilotlelo xa khale a swi nge kumiwi — tlherisela xilotlelo u nga si pfula.',
            'decrypt_failed' => 'Xikombelo lexi pfariweke a xi kotekanga ku pfuriwa. Xi nga ha va xi cinciwile eka vuhlayiselo; a xi fanelanga ku nyikiwa tinhlayo ku kondza sweswo swi hlamuseriwa.',
            'digest_mismatch' => 'Xikombelo lexi pfariweke a xi fambelani na nhlayo leyi tsariweke loko xi rhumeriwa. Xikombelo lexi hlayisiweke a hi xona lexi rhumeriweke, naswona thendara leyi a yi fanelanga ku pfuriwa ku kondza sweswo swi lavisisiwa.',
            'not_a_bid' => 'Xikombelo lexi pfariweke xi pfulekile xi va nchumu lowu nga riki xikombelo.',
            'document_decrypt_failed' => 'Tsalwa ra xikombelo lexi pfariweke a ri kotekanga ku pfuriwa.',
        ],
        'currency' => [
            'no_rate' => 'A ku na nkoka wo cinca wa :from ku ya eka :to lowu nghenisiweke wa :date, hikwalaho swikombelo leswi a swi nge ringanisiwi. Nghenisa nkoka eka Finance, kumbe tsala xikombelo hi :to.',
        ],
    ],
];
