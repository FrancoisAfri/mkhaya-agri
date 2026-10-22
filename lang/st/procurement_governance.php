<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (st)
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
            'reason_required' => 'Ho hana ho tlameha ho ngola lebaka la hona.',
            'recorded_next_level' => 'Ho ngodilwe. Tumello e fetetse boemong bo latelang.',
            'decided' => 'Tumello :status.',
        ],
        'committees' => [
            'created' => 'Komiti :reference e thehilwe.',
            'member_added' => 'Setho se kentswe.',
            'membership_ended' => 'Botho bo felile. Rekoto ya histori e bolokilwe.',
            'not_constituted' => 'Komiti ha e a thehwa ka nepo: :problems',
            'activated' => 'Komiti :reference e sebetsa.',
            'conflict_resolved' => 'Kgohlano e rarollotswe e le :resolution.',
        ],
        'deviations' => [
            'created' => 'Ho kgeloha :reference ho thehilwe e le motlaetsi.',
            'updated' => 'Ho kgeloha ho ntlafaditswe.',
            'acceptance_reason_required' => 'Ho amohela ditemoso tsa kgotlelelo ho hloka lebaka.',
            'cannot_submit' => 'Ha ho kgonehe ho romela: :problems',
            'submitted' => 'Ho kgeloha :reference ho romelletswe tumello.',
            'moved' => 'Ho kgeloha ho isitswe ho :status.',
            'document_attached' => 'Tokomane e kgomaretswe.',
            'type_saved' => 'Mofuta wa ho kgeloha o bolokilwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Phetolelo e ntjha e kentswe. Phetolelo e fetileng e ntse e le teng.',
            'linked' => 'Tokomane e hokahantswe. Ho ne ho sa hlokahale ho e kenya hape.',
            'acknowledged' => 'Kamohelo e ngodilwe.',
            'access_granted' => 'Phihlello e fanwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Motlaetsi o bolokilwe.',
            'submitted' => 'Tekolo e romelwe. Jwale e notletswe.',
            'conflict_declared' => 'Kgohlano e phatlaladitswe. Molaodi o tsebisitswe mme o ke ke wa fana ka dintlha ho fihlela e rarollwa.',
            'declaration_recorded' => 'Phatlalatso e ngodilwe.',
            'confidentiality_accepted' => 'Boitlamo ba lekunutu bo amohetswe.',
            'not_your_clarification' => 'Tlhaloso eo e ne e hlahisitswe bakeng sa mohlahlobi e mong. Ke mohlahlobi eo e neng e lebisitswe ho yena feela ya ka e arabang.',
            'clarification_answered' => 'Karabo ya hao e tlalehilwe mme tlhaloso e koetswe.',
        ],
        'insights' => [
            'report_generated' => 'Tlaleho e reng ":title" e entswe mme ya bolokwa.',
            'alert_updated' => 'Temoso ya matla e ntlafaditswe.',
        ],
        'plans' => [
            'created' => 'Morero wa theko :reference o thehilwe.',
            'line_added' => 'Mola wa morero :reference o kentswe.',
            'line_updated' => 'Mola wa morero :reference o ntlafaditswe.',
            'moved' => 'Morero o isitswe ho :status.',
            'only_approved_live' => 'Ke morero o amohetsweng feela o ka etsang hore mela ya wona e sebetse.',
            'line_live' => 'Mola wa morero :line o sebetsa e le :record.',
            'budget_unavailable' => 'Taolo ya Tekanyetso ha e fumanehe, ka hona dipalo tse itlamileng le tsa sebele di ke ke tsa ntjhafatswa.',
            'budget_refreshed' => 'Dipalo tsa tekanyetso di ntjhafaditswe.',
        ],
        'records' => [
            'moved' => 'Theko e isitswe ho :stage.',
            'officer_updated' => 'Ofisiri e ikarabellang e ntlafaditswe.',
            'no_links_found' => 'Ha ho direkoto tse hokahantsweng tse fumanweng dimojuleng tse ding.',
            'links_found' => 'Dikgokahano tse :count di fumanwe dimojuleng tse ding.',
            'document_attached' => 'Tokomane e kgomaretswe thekong ena.',
        ],
        'settings' => [
            'threshold_order' => 'Moedi wa ho imelwa o tlameha ho ba hodima moedi o phahameng.',
            'saved' => 'Ditlhophiso tsa Puso ya Theko di bolokilwe.',
            'rule_set_created' => 'Sete ya melao :code v:version e thehilwe.',
            'rule_set_frozen' => 'Sete ya melao e kwetswe. Diphetoho jwale di hloka phetolelo e ntjha.',
            'rule_set_is_frozen' => 'Sete ena ya melao e kwetswe. Theha phetolelo e ntjha ho fetola melao ya yona.',
            'rule_saved' => 'Molao :code o bolokilwe.',
            'rule_not_found' => 'Molao ha o fumanehe.',
            'rule_deactivated' => 'Molao o emisitswe.',
            'chain_saved' => 'Ketane ya tumello :code e bolokilwe.',
            'chain_retired' => 'Ketane ya tumello :name e emisitswe.',
            'framework_saved' => 'Morero wa kgetho :name o bolokilwe.',
            'framework_retired' => 'Morero wa kgetho :name o emisitswe.',
            'notification_saved' => 'Molao wa tsebiso :code o bolokilwe.',
            'category_saved' => 'Sehlopha sa tokomane :code se bolokilwe.',
        ],
        'tenders' => [
            'weights_total' => 'Boima ba theko le kgetho bo tlameha ho ba 100; bo ho :total.',
            'initiated' => 'Tlhahlobo ya thendara :reference e qadilwe.',
            'criterion_added' => 'Tekanyetso e kentswe.',
            'criterion_updated' => 'Tekanyetso e ntlafaditswe.',
            'criterion_removed' => 'Tekanyetso e tlositswe.',
            'criteria_change_requested' => 'Phetoho ya ditekanyetso e kopilwe. E sebetsa hang ha e amohetswe.',
            'invitation_created' => 'Memo e thehilwe.',
            'bid_withdrawn' => 'Kopo e notletsweng e hulilwe mme e ka romelwa hape.',
            'bids_opened' => 'Dikopo tse notletsweng tse :count di bulwe.',
            'bid_recorded' => 'Kopo e tswang ho :supplier e ngodilwe.',
            'bidder_disqualified' => 'Mokopi o ntshitswe.',
            'bidder_reinstated' => 'Mokopi o kgutlisitswe.',
            'committee_assigned' => 'Komiti e abetswe; bahlahlobi ba :count ba kentswe tlhahlobong ena.',
            'evaluation_blocked' => 'Tekolo e ke ke ya bulwa hajwale: :problems',
            'evaluation_open' => 'Tekolo e bulehile. Ditekanyetso jwale di kwetswe.',
            'moved' => 'Tlhahlobo e isitswe ho :status.',
            'scores_recomputed' => 'Dintlha tsa komiti le tatellano di balwe hape.',
            'moderation_recorded' => 'Tokiso e ngodilwe.',
            'moderation_not_open' => 'Tokiso ha e a bulwa bakeng sa tlhahlobo ena.',
            'scores_approved' => 'Dintlha tsa ho qetela tsa komiti di amohetswe. Kgothaletso jwale e ka etswa.',
            'recommendation_generated' => 'Tlaleho ya kgothaletso :reference v:version e entswe.',
            'recommendation_submitted' => 'Kgothaletso e romelletswe tumello.',
            'recommendation_decided' => 'Kgothaletso :decision.',
            'criteria_copied' => 'Dintlha tse :count di kopitsitswe ho tendara ya pele.',
            'criteria_copy_empty' => 'Tendara eo ha e na dintlha tse ka kopitswang.',
            'criteria_copy_same' => 'Tendara e ke ke ya kopitsa dintlha tsa yona ho yona.',
        ],
        'portal' => [
            'bid_sealed' => 'Kopo ya hao e notletswe mme e amohetswe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Mohato :step o amohela ka motho ya boletsweng, ka hona ho tlameha ho kgethwa motho.',
            'step_quorum_impossible' => 'Mohato :step o bolela motho a le mong empa o kopa ditekeno tse :quorum, e leng ho ke keng ha kgonahala. Sebedisa karolo kapa tumello ho hloka ba-amoheli ba bangata.',
            'step_needs_approver' => 'Mohato :step o amohela ka :what, ka hona ho tlameha ho kgethwa :what.',
            'not_found' => 'Ketane eo ya tumello ha e sa le teng.',
            'in_flight' => ':name e ntse e na le dikopo tse :count tseleng. Qeta diqeto tseo pele, kapa fetola ketane ho e-na le ho e emisa.',
            'only_default' => ':name ke yona feela ya motheo bakeng sa :subject. Etsa hore ketane e nngwe e be ya motheo pele, ho seng jwalo kopo e latelang ha e na moo e ka yang.',
        ],
        'framework' => [
            'split_total' => 'Karohano ya theko le kgetho e tlameha ho ba 100; e ho :total.',
            'band_duplicate' => 'Banta :band e pheta ":value", eo banta :other e seng e e nkile. Ya pele e ne e tla hlola kamehla.',
            'band_above_max' => 'Banta :band e fana ka dintlha tse :points, tse fetang moedi wa morero ona wa :max.',
            'not_found' => 'Morero oo wa kgetho ha o sa le teng.',
            'in_use' => ':name e boletswe ke dithendara tse :count. E emise ho e-na le hoo, hore dithendara tseo di boloke melao eo di filweng dintlha tlasa yona.',
        ],
        'bid' => [
            'tender_closed' => 'Thendara ena e koetswe, ka hona ha ho bakopi ba bang ba ka memuwang.',
            'invitation_withdrawn' => 'Memo ena e hulilwe.',
            'already_submitted' => 'Kopo e se e romelwe tlasa memo ena. Kopa ofisiri ya theko hore e e hule haeba o hloka ho e fetola.',
            'amount_required' => 'Ho hlokahala boleng ba kopo.',
            'invitation_not_found' => 'Memo eo ha e sa le teng.',
            'already_opened' => 'Kopo ena e se e bulwe mme e ke ke ya hulwa. E ntshe ho e-na le hoo, hore lebaka le be rekotong.',
            'tender_not_found' => 'Thendara eo ha e sa le teng.',
        ],
        'document' => [
            'already_opened' => 'Kopo ena e se e bulwe, ka hona ha ho ditokomane tse ding tse ka kgomaretswang ho yona.',
            'not_found' => 'Tokomane eo ha e sa le teng.',
            'not_opened_yet' => 'Kopo ena ha e so bulwe. Ditokomane tsa yona di dula di notletswe ho fihlela ho bulwa.',
            'digest_mismatch' => 'Tokomane ena ya kopo ha e tsamaellane le palo e ngodilweng ha e romelwa. Hase tokomane e romelwang mme e ke ke ya tshepuwa.',
        ],
        'opening' => [
            'not_closed' => 'Thendara ha e so koale. Dikopo di dula di notletswe ho fihlela nako ya ho koala.',
            'already_opened' => 'Dikopo tsena di se di bulwe. Ho bula ho etsahala hanngwe.',
            'needs_two' => 'Ho bula ho hloka batho ba babedi: ofisiri e bulang le paki.',
            'witness_distinct' => 'Paki e tlameha ho ba motho e mong ho fapana le ofisiri e bulang dikopo.',
            'no_bids' => 'Ha ho dikopo tse romelwang bakeng sa thendara ena.',
        ],
        'seal' => [
            'key_unavailable' => 'Tiiso ya thendara ena e ke ke ya bulwa ka senotlolo sa hajwale sa sesebediswa. Dikopo tse notletsweng tlasa senotlolo se fetileng di ke ke tsa fumanwa — kgutlisa senotlolo pele o bula.',
            'decrypt_failed' => 'Kopo e notletsweng e ke ke ya bulwa. E ka nna ya fetotswe polokelong; e ke ke ya fuwa dintlha ho fihlela ho hlalosuwa.',
            'digest_mismatch' => 'Kopo e notletsweng ha e tsamaellane le palo e ngodilweng ha e romelwa. Kopo e bolokilweng hase kopo e romelwang, mme thendara ena e ke ke ya bulwa ho fihlela ho batlisiswa.',
            'not_a_bid' => 'Kopo e notletsweng e bulehile ya ba ntho e seng kopo.',
            'document_decrypt_failed' => 'Tokomane ya kopo e notletsweng e ke ke ya bulwa.',
        ],
        'currency' => [
            'no_rate' => 'Ha ho sekgahla sa phapanyetsano sa :from ho ya ho :to se kentsweng bakeng sa :date, ka hona dikopo tsena di ke ke tsa bapiswa. Kenya sekgahla ho Finance, kapa ngola kopo ka :to.',
        ],
    ],
];
