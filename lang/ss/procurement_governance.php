<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (ss)
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
            'reason_required' => 'Kwala kumele kubhale sizatfu sako.',
            'recorded_next_level' => 'Kubhaliwe. Kuvunywa sekudlulele ezingeni lelilandzelako.',
            'decided' => 'Kuvunywa :status.',
        ],
        'committees' => [
            'created' => 'Likomiti :reference lentiwe.',
            'member_added' => 'Lilunga lingetiwe.',
            'membership_ended' => 'Bulunga buphelile. Lirekhodi lemlandvo ligciniwe.',
            'not_constituted' => 'Likomiti alimiswanga ngalokufanele: :problems',
            'activated' => 'Likomiti :reference lentiwe lasebenta.',
            'conflict_resolved' => 'Kungcubutana kucatululwe njenge-:resolution.',
        ],
        'deviations' => [
            'created' => 'Kuphambuka :reference kwentiwe njengalokwakhiwako.',
            'updated' => 'Kuphambuka kubuyeketiwe.',
            'acceptance_reason_required' => 'Kwemukela ticwayiso tekulandzela kudzinga sizatfu.',
            'cannot_submit' => 'Akukwatiwa kutfunyelwa: :problems',
            'submitted' => 'Kuphambuka :reference kutfunyelwe kutovunywa.',
            'moved' => 'Kuphambuka kuyiswe ku-:status.',
            'document_attached' => 'Lidokhumende linamatsiselwe.',
            'type_saved' => 'Luhlobo lwekuphambuka lugciniwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Kuguculo lokusha kulayishiwe. Kuguculo lokwendlulile kusatfolakala.',
            'linked' => 'Lidokhumende lihlanganisiwe. Bekungadzingeki lilayishwe futsi.',
            'acknowledged' => 'Kwemukela kubhaliwe.',
            'access_granted' => 'Kufinyelela kuniketiwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Lokwakhiwako kugciniwe.',
            'submitted' => 'Kuhlola kutfunyelwe. Nyalo sekukhiyiwe.',
            'conflict_declared' => 'Kungcubutana kumemetelwe. Umlawuli waziswe futsi awukwati kuniketa emaphuzu kuze kucatululwe.',
            'declaration_recorded' => 'Simemetelo sibhaliwe.',
            'confidentiality_accepted' => 'Kutibophetela emfihlweni kwemukelwe.',
            'not_your_clarification' => 'Leko kucaciswa bekuphakanyiselwe lomunye umhloli. Ngumhloli lokubhekiswe kuye kuphela longaphendvula.',
            'clarification_answered' => 'Imphendvulo yakho irekhodiwe futsi kucaciswa kuvaliwe.',
        ],
        'insights' => [
            'report_generated' => 'Umbiko lotsi ":title" wentiwe futsi wagcinwa.',
            'alert_updated' => 'Sicwayiso semandla sibuyeketiwe.',
        ],
        'plans' => [
            'created' => 'Liphulani lekutsenga :reference lentiwe.',
            'line_added' => 'Umgca weliphulani :reference wengetiwe.',
            'line_updated' => 'Umgca weliphulani :reference ubuyeketiwe.',
            'moved' => 'Liphulani liyiswe ku-:status.',
            'only_approved_live' => 'Liphulani lelivunyiwe kuphela lelingenta imigca yalo isebente.',
            'line_live' => 'Umgca weliphulani :line uyasebenta njenge-:record.',
            'budget_unavailable' => 'Kuphatfwa kweSabelomali akutfolakali, ngako tibalo letitibophetelele naletiyimphumelelo atikwati kuvuselelwa.',
            'budget_refreshed' => 'Tibalo tesabelomali tivuselelwe.',
        ],
        'records' => [
            'moved' => 'Kutsenga kuyiswe ku-:stage.',
            'officer_updated' => 'Sikhulu lesibhekene naloku sibuyeketiwe.',
            'no_links_found' => 'Akukho marekhodi lahlanganisiwe latfolakele kuletinye timojuli.',
            'links_found' => 'Tihlanganisi letingu-:count titfolakele kuletinye timojuli.',
            'document_attached' => 'Lidokhumende linamatsiselwe kuloku kutsenga.',
        ],
        'settings' => [
            'threshold_order' => 'Umkhawulo wekusindvwa kumele ube ngetulu kwemkhawulo lophakeme.',
            'saved' => 'Tilungiselelo teKubusa kweKutsenga tigciniwe.',
            'rule_set_created' => 'Lisethi lemitsetfo :code v:version lentiwe.',
            'rule_set_frozen' => 'Lisethi lemitsetfo livaliwe. Tinshintjo nyalo tidzinga kuguculo lokusha.',
            'rule_set_is_frozen' => 'Leli sethi lemitsetfo livaliwe. Yenta kuguculo lokusha kute ushintje imitsetfo yalo.',
            'rule_saved' => 'Umtsetfo :code ugciniwe.',
            'rule_not_found' => 'Umtsetfo awutfolakali.',
            'rule_deactivated' => 'Umtsetfo wentiwe kutsi angasebenti.',
            'chain_saved' => 'Liketane lekuvuma :code ligciniwe.',
            'chain_retired' => 'Liketane lekuvuma :name likhishiwe.',
            'framework_saved' => 'Luhlaka lwekukhetsa :name lugciniwe.',
            'framework_retired' => 'Luhlaka lwekukhetsa :name lukhishiwe.',
            'notification_saved' => 'Umtsetfo wesatiso :code ugciniwe.',
            'category_saved' => 'Sigaba selidokhumende :code sigciniwe.',
        ],
        'tenders' => [
            'weights_total' => 'Sisindvo selinani nesekukhetsa kumele sibe ngu-100; singu-:total.',
            'initiated' => 'Kubuketwa kwelithenda :reference kucaliwe.',
            'criterion_added' => 'Simiso singetiwe.',
            'criterion_updated' => 'Simiso sibuyeketiwe.',
            'criterion_removed' => 'Simiso sisusiwe.',
            'criteria_change_requested' => 'Lushintjo lwetimiso lucelwe. Lusebenta nasekuvunyiwe.',
            'invitation_created' => 'Simemo sentiwe.',
            'bid_withdrawn' => 'Sicelo lesivaliwe sihoxisiwe futsi singatfunyelwa futsi.',
            'bids_opened' => 'Ticelo letivaliwe letingu-:count tivuliwe.',
            'bid_recorded' => 'Sicelo lesivela ku-:supplier sibhaliwe.',
            'bidder_disqualified' => 'Umgcugcuteli ukhishiwe.',
            'bidder_reinstated' => 'Umgcugcuteli ubuyiselwe.',
            'committee_assigned' => 'Likomiti likhetsiwe; bahloli labangu-:count bengetiwe kuloku kubuketwa.',
            'evaluation_blocked' => 'Kuhlola akukwati kuvulwa kuze kube nyalo: :problems',
            'evaluation_open' => 'Kuhlola kuvuliwe. Timiso nyalo tivaliwe.',
            'moved' => 'Kubuketwa kuyiswe ku-:status.',
            'scores_recomputed' => 'Emaphuzu elikomiti nekulandzelana kubalwe kabusha.',
            'moderation_recorded' => 'Kulungisa kubhaliwe.',
            'moderation_not_open' => 'Kulungisa akukavulwa kuloku kubuketwa.',
            'scores_approved' => 'Emaphuzu ekugcina elikomiti avunyiwe. Sincomo nyalo singentiwa.',
            'recommendation_generated' => 'Umbiko wesincomo :reference v:version wentiwe.',
            'recommendation_submitted' => 'Sincomo sitfunyelwe kutovunywa.',
            'recommendation_decided' => 'Sincomo :decision.',
            'criteria_copied' => 'Timiso letingu-:count tikotjiwe kuthenda yangaphambilini.',
            'criteria_copy_empty' => 'Leyo thenda ayinato timiso letingakotjwa.',
            'criteria_copy_same' => 'Ithenda ayikhoni kukopisha timiso tayo kuyo.',
        ],
        'portal' => [
            'bid_sealed' => 'Sicelo sakho sivaliwe futsi semukelwe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Sinyatselo :step sivuma ngemuntfu lobaliwe, ngako kumele kukhetfwe umuntfu.',
            'step_quorum_impossible' => 'Sinyatselo :step sibala muntfu munye kodvwa sicela tisayino letingu-:quorum, lokungeke kwenteke. Sebentisa indzima nobe imvume nangabe udzinga labavumako labaningi.',
            'step_needs_approver' => 'Sinyatselo :step sivuma nge-:what, ngako kumele kukhetfwe i-:what.',
            'not_found' => 'Lelo ketane lekuvuma alisekho.',
            'in_flight' => 'I-:name isanaticelo letingu-:count letisendleleni. Cedza leto tincumo kucala, nobe hlela liketane esikhundleni sekulikhipha.',
            'only_default' => 'I-:name ngiyo yodvwa lesisekelo se-:subject. Yenta lelinye liketane libe sisekelo kucala, ngaphandle kwaloko sicelo lesilandzelako asinandzawo yekuya.',
        ],
        'framework' => [
            'split_total' => 'Kwehlukaniswa kwelinani nekukhetsa kumele kube ngu-100; kungu-:total.',
            'band_duplicate' => 'Libhendi :band liphindza lokutsi ":value", lokusetsatfwe libhendi :other. Lelokucala belitawuhlala liwina.',
            'band_above_max' => 'Libhendi :band liniketa emaphuzu langu-:points, langetulu kwemkhawulo waloluhlaka longu-:max.',
            'not_found' => 'Lolo hlaka lwekukhetsa alusekho.',
            'in_use' => 'I-:name ibalwe ngemathenda langu-:count. Yenta ingasebenti esikhundleni saloko, kute lawo mathenda agcine imitsetfo laniketwe emaphuzu ngayo.',
        ],
        'bid' => [
            'tender_closed' => 'Lelithenda selivaliwe, ngako abakho labanye bagcugcuteli labangamenywa.',
            'invitation_withdrawn' => 'Lesi simemo sihoxisiwe.',
            'already_submitted' => 'Sekuvele kutfunyelwe sicelo ngaphansi kwalesi simemo. Cela sikhulu sekutsenga kutsi sisihoxise nangabe udzinga kusishintja.',
            'amount_required' => 'Kudzingeka linani lesicelo.',
            'invitation_not_found' => 'Leso simemo asisekho.',
            'already_opened' => 'Lesi sicelo sesivuliwe futsi asikwati kuhoxiswa. Sikhiphe esikhundleni saloko, kute sizatfu sibe serekhodini.',
            'tender_not_found' => 'Lelo thenda alisekho.',
        ],
        'document' => [
            'already_opened' => 'Lesi sicelo sesivuliwe, ngako akukho lamanye madokhumende langanamatsiselwa kuso.',
            'not_found' => 'Lelo dokhumende alisekho.',
            'not_opened_yet' => 'Lesi sicelo asikavulwa. Emadokhumende aso ahlala avaliwe kuze kube sekuvulweni.',
            'digest_mismatch' => 'Leli dokhumende lesicelo alihambisani nesibalo lesabhalwa nasitfunyelwa. Akulona lidokhumende lelatfunyelwa futsi akufanele litsenjwe.',
        ],
        'opening' => [
            'not_closed' => 'Lithenda alikavalwa. Ticelo tihlala tivaliwe kuze kufike sikhatsi sekuvala.',
            'already_opened' => 'Leticelo setivuliwe. Kuvula kwenteka kanye.',
            'needs_two' => 'Kuvula kudzinga bantfu lababili: sikhulu lesivulako nemfakazi.',
            'witness_distinct' => 'Umfakazi kumele abe ngulomunye umuntfu lokungesiso sikhulu lesivula ticelo.',
            'no_bids' => 'Akukho ticelo letitfunyelwe kulelithenda.',
        ],
        'seal' => [
            'key_unavailable' => 'Luphawu lwalelithenda alukwati kuvulwa ngesikhiya salamuhla seluhlelo. Ticelo letavalwa ngesikhiya lesendlulile atikwati kutfolakala — buyisela sikhiya ngaphambi kwekuvula.',
            'decrypt_failed' => 'Sicelo lesivaliwe asikwatanga kuvulwa. Kungenteka sashintjwa lapho sigcinwe khona; akufanele siniketwe emaphuzu kuze kuchazwe loko.',
            'digest_mismatch' => 'Sicelo lesivaliwe asihambisani nesibalo lesabhalwa nasitfunyelwa. Sicelo lesigciniwe akusiso sicelo lesatfunyelwa, futsi lelithenda akufanele livulwe kuze kuphenywe loko.',
            'not_a_bid' => 'Sicelo lesivaliwe sivuleke saba yintfo lengesiso sicelo.',
            'document_decrypt_failed' => 'Lidokhumende lesicelo lesivaliwe alikwatanga kuvulwa.',
        ],
        'currency' => [
            'no_rate' => 'Alikho lizinga lekushintjanisa le-:from liye ku-:to lelifakiwe la-:date, ngako leticelo atikwati kucatsaniswa. Faka lizinga ku-Finance, nobe ubhale sicelo nge-:to.',
        ],
    ],
];
