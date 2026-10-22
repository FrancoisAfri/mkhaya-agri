<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (xh)
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
            'reason_required' => 'Ukwala kufuneka kubhale isizathu saso.',
            'recorded_next_level' => 'Kubhaliwe. Ukuvunywa kudlulele kwinqanaba elilandelayo.',
            'decided' => 'Ukuvunywa :status.',
        ],
        'committees' => [
            'created' => 'Ikomiti :reference yenziwe.',
            'member_added' => 'Ilungu longeziwe.',
            'membership_ended' => 'Ubulungu buphelile. Irekhodi lembali ligciniwe.',
            'not_constituted' => 'Ikomiti ayimiselwanga ngokufanelekileyo: :problems',
            'activated' => 'Ikomiti :reference yenziwe yasebenza.',
            'conflict_resolved' => 'Ungquzulwano lusonjululwe njenge-:resolution.',
        ],
        'deviations' => [
            'created' => 'Ukuphambuka :reference kwenziwe njengoyilo.',
            'updated' => 'Ukuphambuka kuhlaziyiwe.',
            'acceptance_reason_required' => 'Ukwamkela izilumkiso zokuthobela kufuna isizathu.',
            'cannot_submit' => 'Akunakungeniswa: :problems',
            'submitted' => 'Ukuphambuka :reference kungenisiwe ukuze kuvunywe.',
            'moved' => 'Ukuphambuka kusiwe ku-:status.',
            'document_attached' => 'Uxwebhu luqhotyoshelwe.',
            'type_saved' => 'Uhlobo lokuphambuka lugciniwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Inguqulelo entsha ilayishiwe. Inguqulelo yangaphambili isafumaneka.',
            'linked' => 'Uxwebhu luqhotyoshelwe. Bekungafuneki lulayishwe kwakhona.',
            'acknowledged' => 'Ukwamkela kubhaliwe.',
            'access_granted' => 'Ufikelelo lunikiwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Uyilo lugciniwe.',
            'submitted' => 'Uhlolo lungenisiwe. Ngoku lutshixiwe.',
            'conflict_declared' => 'Ungquzulwano lubhengeziwe. Umlawuli waziswe kwaye awunakunika amanqaku de lusonjululwe.',
            'declaration_recorded' => 'Isibhengezo sibhaliwe.',
            'confidentiality_accepted' => 'Ukuzibophelela kwimfihlo kwamkelwe.',
            'not_your_clarification' => 'Olo caciso lwaphakanyiselwa omnye umvavanyi. Ngumvavanyi olujoliswe kuye kuphela onokuluphendula.',
            'clarification_answered' => 'Impendulo yakho irekhodiwe kwaye ucaciso luvaliwe.',
        ],
        'insights' => [
            'report_generated' => 'Ingxelo ethi ":title" yenziwe kwaye yagcinwa.',
            'alert_updated' => 'Isilumkiso samandla sihlaziyiwe.',
        ],
        'plans' => [
            'created' => 'Isicwangciso sokuthenga :reference senziwe.',
            'line_added' => 'Umgca wesicwangciso :reference wongeziwe.',
            'line_updated' => 'Umgca wesicwangciso :reference uhlaziyiwe.',
            'moved' => 'Isicwangciso sisiwe ku-:status.',
            'only_approved_live' => 'Sisicwangciso esivunyiweyo kuphela esinokwenza imigca yaso isebenze.',
            'line_live' => 'Umgca wesicwangciso :line uyasebenza njenge-:record.',
            'budget_unavailable' => 'ULawulo lweSabelo-mali alufumaneki, ngoko amanani azibophelelayo nawokwenene awanakuhlaziywa.',
            'budget_refreshed' => 'Amanani esabelo-mali ahlaziyiwe.',
        ],
        'records' => [
            'moved' => 'Ukuthenga kusiwe ku-:stage.',
            'officer_updated' => 'Igosa elijongene noku lihlaziyiwe.',
            'no_links_found' => 'Akukho rekhodi ziqhotyoshelweyo zifunyenweyo kwezinye iimodyuli.',
            'links_found' => 'Amakhonkco angu-:count afunyenwe kwezinye iimodyuli.',
            'document_attached' => 'Uxwebhu luqhotyoshelwe koku kuthenga.',
        ],
        'settings' => [
            'threshold_order' => 'Umda wokuthwala kakhulu kufuneka ube ngaphezu komda ophezulu.',
            'saved' => 'Iisetingi zoLawulo lokuThenga zigciniwe.',
            'rule_set_created' => 'Iseti yemithetho :code v:version yenziwe.',
            'rule_set_frozen' => 'Iseti yemithetho ityhidiwe. Utshintsho ngoku lufuna inguqulelo entsha.',
            'rule_set_is_frozen' => 'Le seti yemithetho ityhidiwe. Yenza inguqulelo entsha ukuze utshintshe imithetho yayo.',
            'rule_saved' => 'Umthetho :code ugciniwe.',
            'rule_not_found' => 'Umthetho awufumaneki.',
            'rule_deactivated' => 'Umthetho wenziwe akasebenzi.',
            'chain_saved' => 'Ikhonkco lokuvuma :code ligciniwe.',
            'chain_retired' => 'Ikhonkco lokuvuma :name likhutshiwe.',
            'framework_saved' => 'Isikhokelo sokukhetha :name sigciniwe.',
            'framework_retired' => 'Isikhokelo sokukhetha :name sikhutshiwe.',
            'notification_saved' => 'Umthetho wesaziso :code ugciniwe.',
            'category_saved' => 'Udidi lwexwebhu :code lugciniwe.',
        ],
        'tenders' => [
            'weights_total' => 'Ubunzima bexabiso nobokukhetha kufuneka bube ngu-100; bungu-:total.',
            'initiated' => 'Uphononongo lwethenda :reference luqalisiwe.',
            'criterion_added' => 'Umlinganiselo wongeziwe.',
            'criterion_updated' => 'Umlinganiselo uhlaziyiwe.',
            'criterion_removed' => 'Umlinganiselo ususiwe.',
            'criteria_change_requested' => 'Utshintsho lwemilinganiselo lucelwe. Lusebenza xa sele luvunyiwe.',
            'invitation_created' => 'Isimemo senziwe.',
            'bid_withdrawn' => 'Isicelo esivaliweyo sirhoxisiwe kwaye singangeniswa kwakhona.',
            'bids_opened' => 'Izicelo ezivaliweyo ezingu-:count zivuliwe.',
            'bid_recorded' => 'Isicelo esivela ku-:supplier sibhaliwe.',
            'bidder_disqualified' => 'Umnikezeli ukhutshiwe.',
            'bidder_reinstated' => 'Umnikezeli ubuyiselwe.',
            'committee_assigned' => 'Ikomiti yabelwe; abahloli abangu-:count bongeziwe kolu phononongo.',
            'evaluation_blocked' => 'Uhlolo alunakuvulwa okwangoku: :problems',
            'evaluation_open' => 'Uhlolo luvuliwe. Imilinganiselo ngoku ityhidiwe.',
            'moved' => 'Uphononongo lusiwe ku-:status.',
            'scores_recomputed' => 'Amanqaku ekomiti nokulandelelana kubalwe kwakhona.',
            'moderation_recorded' => 'Ulungiso lubhaliwe.',
            'moderation_not_open' => 'Ulungiso alukavulwa kolu phononongo.',
            'scores_approved' => 'Amanqaku okugqibela ekomiti avunyiwe. Isindululo ngoku singenziwa.',
            'recommendation_generated' => 'Ingxelo yesindululo :reference v:version yenziwe.',
            'recommendation_submitted' => 'Isindululo singenisiwe ukuze sivunywe.',
            'recommendation_decided' => 'Isindululo :decision.',
            'criteria_copied' => 'Imigaqo engu-:count ikhutshelwe kwithenda yangaphambili.',
            'criteria_copy_empty' => 'Loo thenda ayinayo imigaqo enokukhutshelwa.',
            'criteria_copy_same' => 'Ithenda ayinakuyikhuphela imigaqo yayo kuyo ngokwayo.',
        ],
        'portal' => [
            'bid_sealed' => 'Isicelo sakho sivaliwe kwaye samkelwe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Inyathelo :step livuma ngomntu ochongiweyo, ngoko kufuneka kukhethwe umntu.',
            'step_quorum_impossible' => 'Inyathelo :step lichonga umntu omnye kodwa licela utyikityo olungu-:quorum, into engenakuze yenzeke. Sebenzisa indima okanye imvume ukuba ufuna abavumi abaninzi.',
            'step_needs_approver' => 'Inyathelo :step livuma nge-:what, ngoko kufuneka kukhethwe i-:what.',
            'not_found' => 'Elo khonkco lokuvuma alisekho.',
            'in_flight' => 'I-:name isenezicelo ezingu-:count ezisendleleni. Gqiba ezo zigqibo kuqala, okanye hlela ikhonkco endaweni yokulikhupha.',
            'only_default' => 'I-:name kuphela kwesisiseko se-:subject. Yenza elinye ikhonkco libe sisiseko kuqala, kungenjalo isicelo esilandelayo asinandawo yokuya.',
        ],
        'framework' => [
            'split_total' => 'Ukwahlulwa kwexabiso nokukhetha kufuneka kube ngu-100; kungu-:total.',
            'band_duplicate' => 'Umbhinqo :band uphinda okuthi ":value", into esele ithathwe ngumbhinqo :other. Owokuqala ubuya kusoloko uphumelela.',
            'band_above_max' => 'Umbhinqo :band unika amanqaku angu-:points, angaphezu komda wesi sikhokelo ongu-:max.',
            'not_found' => 'Eso sikhokelo sokukhetha asisekho.',
            'in_use' => 'I-:name ichazwe ziithenda ezingu-:count. Yenza ingasebenzi endaweni yoko, ukuze ezo thenda zigcine imithetho ezanikwa amanqaku phantsi kwayo.',
        ],
        'bid' => [
            'tender_closed' => 'Le thenda sele ivaliwe, ngoko akukho banikezeli bambi banokumenywa.',
            'invitation_withdrawn' => 'Esi simemo sirhoxisiwe.',
            'already_submitted' => 'Sele kungeniswe isicelo phantsi kwesi simemo. Cela igosa lokuthenga ukuba lisirhoxise ukuba ufuna ukusitshintsha.',
            'amount_required' => 'Kufuneka ixabiso lesicelo.',
            'invitation_not_found' => 'Eso simemo asisekho.',
            'already_opened' => 'Esi sicelo sele sivuliwe kwaye asinakurhoxiswa. Sikhuphe endaweni yoko, ukuze isizathu sibe serekhodini.',
            'tender_not_found' => 'Loo thenda ayisekho.',
        ],
        'document' => [
            'already_opened' => 'Esi sicelo sele sivuliwe, ngoko akukho manye amaxwebhu anokuqhotyoshelwa kuso.',
            'not_found' => 'Olo xwebhu alusekho.',
            'not_opened_yet' => 'Esi sicelo asikavulwa. Amaxwebhu aso ahlala evaliwe de kube kukuvulwa.',
            'digest_mismatch' => 'Olu xwebhu lwesicelo aluhambelani nesibalo esabhalwayo xa lwangeniswa. Asilulo uxwebhu olwangeniswayo kwaye akufuneki luthenjwe.',
        ],
        'opening' => [
            'not_closed' => 'Ithenda ayikavalwa. Izicelo zihlala zivaliwe de kufike ixesha lokuvala.',
            'already_opened' => 'Ezi zicelo sele zivuliwe. Ukuvula kwenzeka kanye.',
            'needs_two' => 'Ukuvula kufuna abantu ababini: igosa elivulayo nengqina.',
            'witness_distinct' => 'Ingqina kufuneka libe ngomnye umntu ongelilo igosa elivula izicelo.',
            'no_bids' => 'Akukho zicelo zingenisiweyo kule thenda.',
        ],
        'seal' => [
            'key_unavailable' => 'Uphawu lwale thenda alunakuvulwa ngesitshixo sanamhlanje senkqubo. Izicelo ezavalwa phantsi kwesitshixo sangaphambili azinakubuyiselwa — buyisela isitshixo phambi kokuvula.',
            'decrypt_failed' => 'Isicelo esivaliweyo asikwazanga ukuvulwa. Kusenokwenzeka satshintshwa apho sigcinwe khona; akufuneki sinikwe amanqaku de oko kucaciswe.',
            'digest_mismatch' => 'Isicelo esivaliweyo asihambelani nesibalo esabhalwayo xa sangeniswa. Isicelo esigciniweyo asisosicelo esangeniswayo, kwaye le thenda akufuneki ivulwe de kuphandwe oko.',
            'not_a_bid' => 'Isicelo esivaliweyo sivuleke saba yinto engesosicelo.',
            'document_decrypt_failed' => 'Uxwebhu lwesicelo esivaliweyo alukwazanga ukuvulwa.',
        ],
        'currency' => [
            'no_rate' => 'Akukho zinga lotshintshiselwano le-:from ukuya ku-:to elifakiweyo lika-:date, ngoko ezi zicelo azinakuthelekiswa. Faka izinga kwiFinance, okanye ubhale isicelo nge-:to.',
        ],
    ],
];
