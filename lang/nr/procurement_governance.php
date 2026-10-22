<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (nr)
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
            'reason_required' => 'Ukwala kufanele kutlole ibanga lakho.',
            'recorded_next_level' => 'Kutloliwe. Ukuvunyelwa sekudlulele ezingeni elilandelako.',
            'decided' => 'Ukuvunyelwa :status.',
        ],
        'committees' => [
            'created' => 'Ikomidi :reference lenziwe.',
            'member_added' => 'Ilunga lingeziwe.',
            'membership_ended' => 'Ubulunga buphelile. Irekhodi lomlando libulungiwe.',
            'not_constituted' => 'Ikomidi alimiswanga ngokufaneleko: :problems',
            'activated' => 'Ikomidi :reference lenziwe lasebenza.',
            'conflict_resolved' => 'Ukungqubuzana kurarululwe njenge-:resolution.',
        ],
        'deviations' => [
            'created' => 'Ukuphambuka :reference kwenziwe njengokwakhiwako.',
            'updated' => 'Ukuphambuka kubuyekeziwe.',
            'acceptance_reason_required' => 'Ukwamukela iinyeleliso zokulandela kutlhoga ibanga.',
            'cannot_submit' => 'Akukwazi ukuthunyelwa: :problems',
            'submitted' => 'Ukuphambuka :reference kuthunyelwe bona kuvunywe.',
            'moved' => 'Ukuphambuka kusiwe ku-:status.',
            'document_attached' => 'Itlhogomiso linamathiselwe.',
            'type_saved' => 'Uhlobo lokuphambuka lubulungiwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Inguqulelo etjha ilayishiwe. Inguqulelo yangaphambili isafumaneka.',
            'linked' => 'Itlhogomiso lihlanganisiwe. Bekungatlhogeki lilayishwe godu.',
            'acknowledged' => 'Ukwamukela kutloliwe.',
            'access_granted' => 'Ukufinyelela kunikelwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Okwakhiwako kubulungiwe.',
            'submitted' => 'Ukuhlola kuthunyelwe. Nje sekutlhajiwe.',
            'conflict_declared' => 'Ukungqubuzana kumemezelwe. Umlawuli waziswe begodu awukwazi ukunikela amaphuzu bekube kulapha kurarululwa.',
            'declaration_recorded' => 'Ummemezelo utloliwe.',
            'confidentiality_accepted' => 'Ukuzibophelela efihlweni kwamukelwe.',
            'not_your_clarification' => 'Lokho kuhlathululwa bekuphakanyiselwe omunye umhloli. Mhloli okuqalwe kuye kwaphela ongaphendula.',
            'clarification_answered' => 'Ipendulo yakho irekhodiwe begodu ukuhlathululwa kuvaliwe.',
        ],
        'insights' => [
            'report_generated' => 'Umbiko othi ":title" wenziwe begodu wabulungwa.',
            'alert_updated' => 'Isiyeleliso samandla sibuyekeziwe.',
        ],
        'plans' => [
            'created' => 'Ihlelo lokuthenga :reference lenziwe.',
            'line_added' => 'Umgca wehlelo :reference wengeziwe.',
            'line_updated' => 'Umgca wehlelo :reference ubuyekeziwe.',
            'moved' => 'Ihlelo lisiwe ku-:status.',
            'only_approved_live' => 'Lihlelo elivunyiweko kwaphela elingenza imigca yalo isebenze.',
            'line_live' => 'Umgca wehlelo :line uyasebenza njenge-:record.',
            'budget_unavailable' => 'Ukuphathwa kweSabelomali akufumaneki, ngalokho iinombolo ezizibophelelako nezamambala azikwazi ukuvuselelwa.',
            'budget_refreshed' => 'Iinombolo zesabelomali zivuselelwe.',
        ],
        'records' => [
            'moved' => 'Ukuthenga kusiwe ku-:stage.',
            'officer_updated' => 'Isikhulu esibhekene nalokhu sibuyekeziwe.',
            'no_links_found' => 'Akukho marekhodi ahlanganisiweko afunyanweko kezinye iimojula.',
            'links_found' => 'Iinhlanganisi ezingu-:count zifunyanwe kezinye iimojula.',
            'document_attached' => 'Itlhogomiso linamathiselwe kilokhu kuthenga.',
        ],
        'settings' => [
            'threshold_order' => 'Umkhawulo wokusindwa kufanele ube ngaphezu komkhawulo ophezulu.',
            'saved' => 'Iimlungiselelo zoKubusa kokuThenga zibulungiwe.',
            'rule_set_created' => 'Isethi yemithetho :code v:version yenziwe.',
            'rule_set_frozen' => 'Isethi yemithetho ivaliwe. Iintjhuguluko nje zitlhoga inguqulelo etjha.',
            'rule_set_is_frozen' => 'Isethi yemithetho le ivaliwe. Yenza inguqulelo etjha ukuze utjhugulule imithetho yayo.',
            'rule_saved' => 'Umthetho :code ubulungiwe.',
            'rule_not_found' => 'Umthetho awufumaneki.',
            'rule_deactivated' => 'Umthetho wenziwe bona ungasebenzi.',
            'chain_saved' => 'Iketani lokuvuma :code libulungiwe.',
            'chain_retired' => 'Iketani lokuvuma :name likhutjhiwe.',
            'framework_saved' => 'Ihlaka lokukhetha :name libulungiwe.',
            'framework_retired' => 'Ihlaka lokukhetha :name likhutjhiwe.',
            'notification_saved' => 'Umthetho wesaziso :code ubulungiwe.',
            'category_saved' => 'Isigaba setlhogomiso :code sibulungiwe.',
        ],
        'tenders' => [
            'weights_total' => 'Isisindo senani nesokukhetha kufanele sibe ngu-100; singu-:total.',
            'initiated' => 'Ukubuyekezwa kwethenda :reference kuthonyiwe.',
            'criterion_added' => 'Inkambisolawulo yengeziwe.',
            'criterion_updated' => 'Inkambisolawulo ibuyekeziwe.',
            'criterion_removed' => 'Inkambisolawulo isusiwe.',
            'criteria_change_requested' => 'Itjhuguluko leenkambisolawulo libawiwe. Lisebenza nalisele livunyiwe.',
            'invitation_created' => 'Isimemo senziwe.',
            'bid_withdrawn' => 'Isibawo esivaliweko sihoxisiwe begodu singathunyelwa godu.',
            'bids_opened' => 'Iimbawo ezivaliweko ezingu-:count zivuliwe.',
            'bid_recorded' => 'Isibawo esivela ku-:supplier sitloliwe.',
            'bidder_disqualified' => 'Umnikeli ukhutjhiwe.',
            'bidder_reinstated' => 'Umnikeli ubuyiselwe.',
            'committee_assigned' => 'Ikomidi likhethiwe; abahloli abangu-:count bengeziwe kilokhu kubuyekezwa.',
            'evaluation_blocked' => 'Ukuhlola akukwazi ukuvulwa okwanje: :problems',
            'evaluation_open' => 'Ukuhlola kuvuliwe. Iinkambisolawulo nje sezivaliwe.',
            'moved' => 'Ukubuyekezwa kusiwe ku-:status.',
            'scores_recomputed' => 'Amaphuzu wekomidi nokulandelana kubalwe kabutjha.',
            'moderation_recorded' => 'Ukulungisa kutloliwe.',
            'moderation_not_open' => 'Ukulungisa akukavulwa kilokhu kubuyekezwa.',
            'scores_approved' => 'Amaphuzu wokugcina wekomidi avunyiwe. Isincomo nje singenziwa.',
            'recommendation_generated' => 'Umbiko wesincomo :reference v:version wenziwe.',
            'recommendation_submitted' => 'Isincomo sithunyelwe bona sivunywe.',
            'recommendation_decided' => 'Isincomo :decision.',
            'criteria_copied' => 'Iinkambisolawulo ezingu-:count zikotjhiweko ethendeni yangaphambili.',
            'criteria_copy_empty' => 'Lelo thenda alinazo iinkambisolawulo ezingakotjhwa.',
            'criteria_copy_same' => 'Ithenda ayikwazi ukukopa iinkambisolawulo zayo kiyo.',
        ],
        'portal' => [
            'bid_sealed' => 'Isibawo sakho sivaliwe begodu samukelwa.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Isinyathelo :step sivuma ngomuntu obaliweko, ngalokho kufanele kukhethwe umuntu.',
            'step_quorum_impossible' => 'Isinyathelo :step sibala umuntu munye kodwana sibawa amasayino angu-:quorum, okungeze kwenzeka. Sebenzisa indima namkha imvumo nangabe utlhoga abavumi abanengi.',
            'step_needs_approver' => 'Isinyathelo :step sivuma nge-:what, ngalokho kufanele kukhethwe i-:what.',
            'not_found' => 'Leyo ketani lokuvuma alisekho.',
            'in_flight' => 'I-:name isanembawo ezingu-:count ezisendleleni. Qeda leyo iinqunto kokuthoma, namkha ulungise iketani esikhundleni sokulikhupha.',
            'only_default' => 'I-:name ngiyo yodwa esisekelo se-:subject. Yenza elinye iketani libe sisekelo kokuthoma, ngaphandle kwalokho isibawo esilandelako asinandawo yokuya.',
        ],
        'framework' => [
            'split_total' => 'Ukwahlukaniswa kwenani nokukhetha kufanele kube ngu-100; kungu-:total.',
            'band_duplicate' => 'Ibhentji :band liphinda okuthi ":value", okusele kuthethwe libhentji :other. Elokuthoma belizokuhlala liwina.',
            'band_above_max' => 'Ibhentji :band linikela amaphuzu angu-:points, angaphezu komkhawulo wehlaka leli ongu-:max.',
            'not_found' => 'Leyo hlaka lokukhetha alisekho.',
            'in_use' => 'I-:name ibalwe mathenda angu-:count. Yenza lingasebenzi esikhundleni salokho, ukuze lawo mathenda abulunge imithetho anikelwe amaphuzu ngayo.',
        ],
        'bid' => [
            'tender_closed' => 'Ithenda le sele ivaliwe, ngalokho abakho abanye abanikeli abangamenywa.',
            'invitation_withdrawn' => 'Isimemo lesi sihoxisiwe.',
            'already_submitted' => 'Sekuvele kuthunyelwe isibawo ngaphasi kwesimemo lesi. Bawa isikhulu sokuthenga bona sisihoxise nangabe utlhoga ukusitjhugulula.',
            'amount_required' => 'Kutlhogeka inani lesibawo.',
            'invitation_not_found' => 'Leyo simemo asisekho.',
            'already_opened' => 'Isibawo lesi sele sivuliwe begodu asikwazi ukuhoxiswa. Sikhuphe esikhundleni salokho, ukuze ibanga libe serekhodini.',
            'tender_not_found' => 'Leyo thenda alisekho.',
        ],
        'document' => [
            'already_opened' => 'Isibawo lesi sele sivuliwe, ngalokho akukho amanye amatlhogomiso anganamathiselwa kiso.',
            'not_found' => 'Leyo tlhogomiso alisekho.',
            'not_opened_yet' => 'Isibawo lesi asikavulwa. Amatlhogomiso waso ahlala avaliwe bekube sekuvulweni.',
            'digest_mismatch' => 'Itlhogomiso lesibawo lesi alivumelani nesibalo esatlolwa nasithunyelwa. Akulo itlhogomiso elathunyelwa begodu akufanele lithenjwe.',
        ],
        'opening' => [
            'not_closed' => 'Ithenda alikavalwa. Iimbawo zihlala zivaliwe bekube sikhathi sokuvala.',
            'already_opened' => 'Iimbawo lezi sele zivuliwe. Ukuvula kwenzeka kanye.',
            'needs_two' => 'Ukuvula kutlhoga abantu ababili: isikhulu esivulako nofakazi.',
            'witness_distinct' => 'Ufakazi kufanele abe ngomunye umuntu ongasiso isikhulu esivula iimbawo.',
            'no_bids' => 'Akukho iimbawo ezithunyelweko kithenda leli.',
        ],
        'seal' => [
            'key_unavailable' => 'Uphawu lwethenda leli alukwazi ukuvulwa ngesikhiya sanje sesistimu. Iimbawo ezavalwa ngesikhiya esidluleko azikwazi ukufunyanwa — buyisela isikhiya ngaphambi kokuvula.',
            'decrypt_failed' => 'Isibawo esivaliweko asikwazanga ukuvulwa. Kungenzeka satjhugululwa lapha sibulungwe khona; akufanele sinikelwe amaphuzu bekube kulapha lokho kuhlathululwa.',
            'digest_mismatch' => 'Isibawo esivaliweko asivumelani nesibalo esatlolwa nasithunyelwa. Isibawo esibulungiweko akusiso isibawo esathunyelwa, begodu ithenda leli akufanele livulwe bekube kulapha lokho kuphenywa.',
            'not_a_bid' => 'Isibawo esivaliweko sivuleke saba yinto engasiso isibawo.',
            'document_decrypt_failed' => 'Itlhogomiso lesibawo esivaliweko alikwazanga ukuvulwa.',
        ],
        'currency' => [
            'no_rate' => 'Alikho izinga lokutjhugulula le-:from liye ku-:to elifakiweko la-:date, ngalokho iimbawo lezi azikwazi ukumadaniswa. Faka izinga ku-Finance, namkha utlole isibawo nge-:to.',
        ],
    ],
];
