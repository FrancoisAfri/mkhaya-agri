<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (zu)
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
            'reason_required' => 'Ukwenqaba kumele kubhale isizathu sako.',
            'recorded_next_level' => 'Kubhaliwe. Ukugunyazwa sekudlulele ezingeni elilandelayo.',
            'decided' => 'Ukugunyazwa :status.',
        ],
        'committees' => [
            'created' => 'Ikomiti :reference lidaliwe.',
            'member_added' => 'Ilungu lengeziwe.',
            'membership_ended' => 'Ubulungu buphelile. Irekhodi lomlando ligciniwe.',
            'not_constituted' => 'Ikomiti alimiswanga ngendlela efanele: :problems',
            'activated' => 'Ikomiti :reference lenziwe lasebenza.',
            'conflict_resolved' => 'Ukungqubuzana kuxazululwe njenge-:resolution.',
        ],
        'deviations' => [
            'created' => 'Ukuphambuka :reference kudalwe njengoyilo.',
            'updated' => 'Ukuphambuka kubuyekeziwe.',
            'acceptance_reason_required' => 'Ukwamukela izixwayiso zokuthobela kudinga isizathu.',
            'cannot_submit' => 'Akukwazi ukuthunyelwa: :problems',
            'submitted' => 'Ukuphambuka :reference kuthunyelwe ukuze kugunyazwe.',
            'moved' => 'Ukuphambuka kudluliselwe ku-:status.',
            'document_attached' => 'Idokhumenti linamathiselwe.',
            'type_saved' => 'Uhlobo lokuphambuka lulondoloziwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Inguqulo entsha ilayishiwe. Inguqulo edlule isatholakala.',
            'linked' => 'Idokhumenti lixhunyiwe. Bekungadingeki lilayishwe futhi.',
            'acknowledged' => 'Ukwamukela kubhaliwe.',
            'access_granted' => 'Ukufinyelela kunikeziwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Uyilo lulondoloziwe.',
            'submitted' => 'Ukuhlola kuthunyelwe. Manje sekukhiyiwe.',
            'conflict_declared' => 'Ukungqubuzana kumenyezelwe. Umlawuli waziswe futhi awukwazi ukunika amaphuzu kuze kuxazululwe.',
            'declaration_recorded' => 'Isimemezelo sibhaliwe.',
            'confidentiality_accepted' => 'Ukuzibophezela emfihlweni kwamukelwe.',
            'not_your_clarification' => 'Lokho kucaciswa kwaphakanyiselwa omunye umhloli. Umhloli okubhekiswe kuye kuphela ongaphendula.',
            'clarification_answered' => 'Impendulo yakho irekhodiwe futhi ukucaciswa kuvaliwe.',
        ],
        'insights' => [
            'report_generated' => 'Umbiko othi ":title" wenziwe futhi wagcinwa.',
            'alert_updated' => 'Isexwayiso samandla sibuyekeziwe.',
        ],
        'plans' => [
            'created' => 'Ipulani lokuthenga :reference lidaliwe.',
            'line_added' => 'Umugqa wepulani :reference wengeziwe.',
            'line_updated' => 'Umugqa wepulani :reference ubuyekeziwe.',
            'moved' => 'Ipulani lidluliselwe ku-:status.',
            'only_approved_live' => 'Ipulani elivunyiwe kuphela elingenza imigqa yalo isebenze.',
            'line_live' => 'Umugqa wepulani :line usebenza njenge-:record.',
            'budget_unavailable' => 'Ukuphathwa Kwesabelomali akutholakali, ngakho izibalo ezizibophezelayo nezangempela azikwazi ukuvuselelwa.',
            'budget_refreshed' => 'Izibalo zesabelomali zivuselelwe.',
        ],
        'records' => [
            'moved' => 'Ukuthenga kudluliselwe ku-:stage.',
            'officer_updated' => 'Isikhulu esibhekele lokhu sibuyekeziwe.',
            'no_links_found' => 'Awekho amarekhodi axhunyiwe atholakele kwezinye izimojula.',
            'links_found' => 'Izixhumanisi ezingu-:count zitholakele kwezinye izimojula.',
            'document_attached' => 'Idokhumenti linamathiselwe kulokhu kuthenga.',
        ],
        'settings' => [
            'threshold_order' => 'Umkhawulo wokusindwa kumele ube ngaphezu komkhawulo ophezulu.',
            'saved' => 'Izilungiselelo Zokubusa Kokuthenga zilondoloziwe.',
            'rule_set_created' => 'Isethi yemithetho :code v:version idaliwe.',
            'rule_set_frozen' => 'Isethi yemithetho ivaliwe. Izinguquko manje zidinga inguqulo entsha.',
            'rule_set_is_frozen' => 'Le sethi yemithetho ivaliwe. Dala inguqulo entsha ukuze ushintshe imithetho yayo.',
            'rule_saved' => 'Umthetho :code ulondoloziwe.',
            'rule_not_found' => 'Umthetho awutholakali.',
            'rule_deactivated' => 'Umthetho wenziwe wangasebenzi.',
            'chain_saved' => 'Iketanga lokugunyaza :code lilondoloziwe.',
            'chain_retired' => 'Iketanga lokugunyaza :name likhiphiwe.',
            'framework_saved' => 'Uhlaka lokukhetha :name lulondoloziwe.',
            'framework_retired' => 'Uhlaka lokukhetha :name lukhiphiwe.',
            'notification_saved' => 'Umthetho wesaziso :code ulondoloziwe.',
            'category_saved' => 'Isigaba sedokhumenti :code silondoloziwe.',
        ],
        'tenders' => [
            'weights_total' => 'Ubunzima bentengo nobokukhetha kumele bube ngu-100; bungu-:total.',
            'initiated' => 'Ukubuyekezwa kwethenda :reference kuqaliwe.',
            'criterion_added' => 'Isilinganiso sengeziwe.',
            'criterion_updated' => 'Isilinganiso sibuyekeziwe.',
            'criterion_removed' => 'Isilinganiso sisusiwe.',
            'criteria_change_requested' => 'Ushintsho lwezilinganiso lucelwe. Lusebenza uma seluvunyiwe.',
            'invitation_created' => 'Isimemo sidaliwe.',
            'bid_withdrawn' => 'Ithenda elivaliwe lihoxisiwe futhi lingathunyelwa futhi.',
            'bids_opened' => 'Amathenda avaliwe angu-:count avuliwe.',
            'bid_recorded' => 'Ithenda elivela ku-:supplier libhaliwe.',
            'bidder_disqualified' => 'Umnikeli ukhishiwe.',
            'bidder_reinstated' => 'Umnikeli ubuyiselwe.',
            'committee_assigned' => 'Ikomiti labelwe; abahloli abangu-:count bengeziwe kulokhu kubuyekezwa.',
            'evaluation_blocked' => 'Ukuhlola akukwazi ukuvulwa okwamanje: :problems',
            'evaluation_open' => 'Ukuhlola kuvuliwe. Izilinganiso manje sezivaliwe.',
            'moved' => 'Ukubuyekezwa kudluliselwe ku-:status.',
            'scores_recomputed' => 'Amaphuzu ekomiti nokulandelana kubalwe kabusha.',
            'moderation_recorded' => 'Ukulungisa kubhaliwe.',
            'moderation_not_open' => 'Ukulungisa akukavulwa kulokhu kubuyekezwa.',
            'scores_approved' => 'Amaphuzu okugcina ekomiti avunyiwe. Isincomo manje singenziwa.',
            'recommendation_generated' => 'Umbiko wesincomo :reference v:version wenziwe.',
            'recommendation_submitted' => 'Isincomo sithunyelwe ukuze sigunyazwe.',
            'recommendation_decided' => 'Isincomo :decision.',
            'criteria_copied' => 'Imigomo engu-:count ikotshiwe kwithenda yangaphambilini.',
            'criteria_copy_empty' => 'Leyo thenda ayinayo imigomo engakotshwa.',
            'criteria_copy_same' => 'Ithenda ayikwazi ukukopisha imigomo yayo kuyo ngokwayo.',
        ],
        'portal' => [
            'bid_sealed' => 'Ithenda lakho livaliwe futhi lamukelwe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Isinyathelo :step sigunyaza ngomuntu oqanjiwe, ngakho kumele kukhethwe umuntu.',
            'step_quorum_impossible' => 'Isinyathelo :step siqamba umuntu oyedwa kodwa sicela izignesha ezingu-:quorum, okungeke kwenzeke. Sebenzisa indima noma imvume ukuze udinge abagunyazi abaningi.',
            'step_needs_approver' => 'Isinyathelo :step sigunyaza ngo-:what, ngakho kumele kukhethwe i-:what.',
            'not_found' => 'Lelo ketanga lokugunyaza alisekho.',
            'in_flight' => 'I-:name isanezicelo ezingu-:count ezisendleleni. Qeda lezo zinqumo kuqala, noma uhlele iketanga esikhundleni sokulikhipha.',
            'only_default' => 'I-:name iwukuphela kwesisekelo se-:subject. Yenza elinye iketanga libe yisisekelo kuqala, ngaphandle kwalokho isicelo esilandelayo asinandawo yokuya.',
        ],
        'framework' => [
            'split_total' => 'Ukwahlukaniswa kwentengo nokukhetha kumele kube ngu-100; kungu-:total.',
            'band_duplicate' => 'Ibhande :band liphinda okuthi ":value", okuyilokho ibhande :other elivele likufuna. Elokuqala belizohlala liwina.',
            'band_above_max' => 'Ibhande :band linika amaphuzu angu-:points, angaphezu komkhawulo waloluhlaka ongu-:max.',
            'not_found' => 'Lolo hlaka lokukhetha alusekho.',
            'in_use' => 'I-:name ibalwe ngamathenda angu-:count. Yenza ingasebenzi esikhundleni salokho, ukuze lawo mathenda agcine imithetho ayinikwe amaphuzu ngayo.',
        ],
        'bid' => [
            'tender_closed' => 'Leli thenda selivaliwe, ngakho abekho abanye abanikeli abangamenywa.',
            'invitation_withdrawn' => 'Lesi simemo sihoxisiwe.',
            'already_submitted' => 'Sekuvele kuthunyelwe ithenda ngaphansi kwalesi simemo. Cela isikhulu sokuthenga ukuthi silihoxise uma udinga ukulishintsha.',
            'amount_required' => 'Kudingeka inani lethenda.',
            'invitation_not_found' => 'Leso simemo asisekho.',
            'already_opened' => 'Leli thenda selivuliwe futhi alikwazi ukuhoxiswa. Khipha umnikeli esikhundleni salokho, ukuze isizathu sibe serekhodini.',
            'tender_not_found' => 'Lelo thenda alisekho.',
        ],
        'document' => [
            'already_opened' => 'Leli thenda selivuliwe, ngakho awekho amanye amadokhumenti anganamathiselwa kulo.',
            'not_found' => 'Lelo dokhumenti alisekho.',
            'not_opened_yet' => 'Leli thenda alikavulwa. Amadokhumenti alo ahlala evaliwe kuze kube sekuvulweni.',
            'digest_mismatch' => 'Leli dokhumenti lethenda alihambisani nesibalo esibhalwe ngesikhathi lithunyelwa. Akulona idokhumenti elathunyelwa futhi akufanele lithenjwe.',
        ],
        'opening' => [
            'not_closed' => 'Ithenda alikavalwa. Amathenda ahlala evaliwe kuze kube isikhathi sokuvala.',
            'already_opened' => 'La mathenda asevuliwe. Ukuvula kwenzeka kanye.',
            'needs_two' => 'Ukuvula kudinga abantu ababili: isikhulu esivulayo nofakazi.',
            'witness_distinct' => 'Ufakazi kumele abe ngomunye umuntu ongesona isikhulu esivula amathenda.',
            'no_bids' => 'Awekho amathenda athunyelwe kuleli thenda.',
        ],
        'seal' => [
            'key_unavailable' => 'Uphawu lwaleli thenda alukwazi ukuvulwa ngokhiye wamanje wohlelo. Amathenda avalwe ngokhiye owedlule awakwazi ukutholakala — buyisela ukhiye ngaphambi kokuvula.',
            'decrypt_failed' => 'Ithenda elivaliwe alikwazanga ukuvulwa. Kungenzeka lishintshiwe lapho ligcinwe khona; akufanele linikwe amaphuzu kuze kuchazwe lokho.',
            'digest_mismatch' => 'Ithenda elivaliwe alihambisani nesibalo esibhalwe ngesikhathi lithunyelwa. Ithenda eligciniwe akulona ithenda elathunyelwa, futhi leli thenda akufanele livulwe kuze kuphenywe lokho.',
            'not_a_bid' => 'Ithenda elivaliwe livuleke laba yinto engelona ithenda.',
            'document_decrypt_failed' => 'Idokhumenti lethenda elivaliwe alikwazanga ukuvulwa.',
        ],
        'currency' => [
            'no_rate' => 'Alikho izinga lokushintshanisa le-:from liya ku-:to elifakelwe u-:date, ngakho la mathenda awakwazi ukuqhathaniswa. Faka izinga ku-Finance, noma ubhale ithenda nge-:to.',
        ],
    ],
];
