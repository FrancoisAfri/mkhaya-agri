<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (nso)
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
            'reason_required' => 'Go gana go swanetše go ngwala lebaka la gona.',
            'recorded_next_level' => 'Go ngwadilwe. Tumelelo e fetetše boemong bjo bo latelago.',
            'decided' => 'Tumelelo :status.',
        ],
        'committees' => [
            'created' => 'Komiti :reference e hlanngwe.',
            'member_added' => 'Leloko le tsentšwe.',
            'membership_ended' => 'Boleloko bo felile. Rekoto ya histori e bolokilwe.',
            'not_constituted' => 'Komiti ga se ya hlongwa ka mo go swanetšego: :problems',
            'activated' => 'Komiti :reference e a šoma.',
            'conflict_resolved' => 'Thulano e rarollotšwe bjalo ka :resolution.',
        ],
        'deviations' => [
            'created' => 'Go aroga :reference go hlanngwe bjalo ka sengwalwana.',
            'updated' => 'Go aroga go mpshafaditšwe.',
            'acceptance_reason_required' => 'Go amogela ditemošo tša kobamelo go nyaka lebaka.',
            'cannot_submit' => 'Ga go kgonege go romela: :problems',
            'submitted' => 'Go aroga :reference go romilwe gore go amogelwe.',
            'moved' => 'Go aroga go išitšwe go :status.',
            'document_attached' => 'Tokumente e kgomareditšwe.',
            'type_saved' => 'Mohuta wa go aroga o bolokilwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Phetolelo ye mpsha e tsentšwe. Phetolelo ya peleng e sa le gona.',
            'linked' => 'Tokumente e kgokagantšwe. Go be go sa nyakege go e tsenya gape.',
            'acknowledged' => 'Kamogelo e ngwadilwe.',
            'access_granted' => 'Phihlelelo e filwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Sengwalwana se bolokilwe.',
            'submitted' => 'Tekolo e romilwe. Bjale e notletšwe.',
            'conflict_declared' => 'Thulano e begilwe. Molaodi o tsebišitšwe gomme o ka se fe dintlha go fihlela e rarollwa.',
            'declaration_recorded' => 'Kanegelo e ngwadilwe.',
            'confidentiality_accepted' => 'Boitlamo bja sephiri bo amogetšwe.',
            'not_your_clarification' => 'Tlhaloso yeo e be e hlagisitswe bakeng sa mohlahlobi yo mongwe. Ke mohlahlobi yo e bego e lebisitswe go yena fela yo a ka e arabago.',
            'clarification_answered' => 'Karabo ya gago e begilwe gomme tlhaloso e tswaletswe.',
        ],
        'insights' => [
            'report_generated' => 'Pego ye e rego ":title" e dirilwe gomme ya bolokwa.',
            'alert_updated' => 'Temošo ya bokgoni e mpshafaditšwe.',
        ],
        'plans' => [
            'created' => 'Leano la theko :reference le hlanngwe.',
            'line_added' => 'Mothaladi wa leano :reference o tsentšwe.',
            'line_updated' => 'Mothaladi wa leano :reference o mpshafaditšwe.',
            'moved' => 'Leano le išitšwe go :status.',
            'only_approved_live' => 'Ke leano leo le amogetšwego fela leo le ka dirago gore methaladi ya lona e šome.',
            'line_live' => 'Mothaladi wa leano :line o a šoma bjalo ka :record.',
            'budget_unavailable' => 'Taolo ya Tekanyetšo ga e hwetšagale, ka fao dipalo tšeo di itlamilego le tša kgonthe di ka se mpshafatšwe.',
            'budget_refreshed' => 'Dipalo tša tekanyetšo di mpshafaditšwe.',
        ],
        'records' => [
            'moved' => 'Theko e išitšwe go :stage.',
            'officer_updated' => 'Mohlankedi yo a ikarabelago o mpshafaditšwe.',
            'no_links_found' => 'Ga go direkoto tšeo di kgokagantšwego tšeo di hweditšwego dimojuleng tše dingwe.',
            'links_found' => 'Dikgokagano tše :count di hweditšwe dimojuleng tše dingwe.',
            'document_attached' => 'Tokumente e kgomareditšwe thekong ye.',
        ],
        'settings' => [
            'threshold_order' => 'Mollwane wa go imelwa o swanetše go ba ka godimo ga mollwane wo mogolo.',
            'saved' => 'Dipeakanyo tša Pušo ya Theko di bolokilwe.',
            'rule_set_created' => 'Sete ya melao :code v:version e hlanngwe.',
            'rule_set_frozen' => 'Sete ya melao e tswaletšwe. Diphetogo bjale di nyaka phetolelo ye mpsha.',
            'rule_set_is_frozen' => 'Sete ye ya melao e tswaletšwe. Hlama phetolelo ye mpsha go fetoša melao ya yona.',
            'rule_saved' => 'Molao :code o bolokilwe.',
            'rule_not_found' => 'Molao ga o hwetšagale.',
            'rule_deactivated' => 'Molao o emišitšwe.',
            'chain_saved' => 'Ketane ya tumelelo :code e bolokilwe.',
            'chain_retired' => 'Ketane ya tumelelo :name e emišitšwe.',
            'framework_saved' => 'Tlhako ya kgetho :name e bolokilwe.',
            'framework_retired' => 'Tlhako ya kgetho :name e emišitšwe.',
            'notification_saved' => 'Molao wa tsebišo :code o bolokilwe.',
            'category_saved' => 'Sehlopha sa tokumente :code se bolokilwe.',
        ],
        'tenders' => [
            'weights_total' => 'Boima bja theko le kgetho bo swanetše go ba 100; bo go :total.',
            'initiated' => 'Tlhahlobo ya tendara :reference e thomilwe.',
            'criterion_added' => 'Tekanyetšo e tsentšwe.',
            'criterion_updated' => 'Tekanyetšo e mpshafaditšwe.',
            'criterion_removed' => 'Tekanyetšo e tlošitšwe.',
            'criteria_change_requested' => 'Phetogo ya ditekanyetšo e kgopetšwe. E šoma ge e amogetšwe.',
            'invitation_created' => 'Taletšo e hlanngwe.',
            'bid_withdrawn' => 'Kgopelo ye e notletšwego e gogilwe morago gomme e ka romelwa gape.',
            'bids_opened' => 'Dikgopelo tše di notletšwego tše :count di bulwe.',
            'bid_recorded' => 'Kgopelo ye e tšwago go :supplier e ngwadilwe.',
            'bidder_disqualified' => 'Mokgopedi o ntšhitšwe.',
            'bidder_reinstated' => 'Mokgopedi o bušeditšwe.',
            'committee_assigned' => 'Komiti e abilwe; bahlahlobi ba :count ba tsentšwe tlhahlobong ye.',
            'evaluation_blocked' => 'Tekolo e ka se bulwe bjale: :problems',
            'evaluation_open' => 'Tekolo e bulegile. Ditekanyetšo bjale di tswaletšwe.',
            'moved' => 'Tlhahlobo e išitšwe go :status.',
            'scores_recomputed' => 'Dintlha tša komiti le tatelano di badilwe gape.',
            'moderation_recorded' => 'Tokafatšo e ngwadilwe.',
            'moderation_not_open' => 'Tokafatšo ga se ya bulwa tlhahlobong ye.',
            'scores_approved' => 'Dintlha tša mafelelo tša komiti di amogetšwe. Kgothaletšo bjale e ka dirwa.',
            'recommendation_generated' => 'Pego ya kgothaletšo :reference v:version e dirilwe.',
            'recommendation_submitted' => 'Kgothaletšo e romilwe gore e amogelwe.',
            'recommendation_decided' => 'Kgothaletšo :decision.',
            'criteria_copied' => 'Dintlha tse :count di kopisitswe go thentara ya pele.',
            'criteria_copy_empty' => 'Thentara yeo ga e na dintlha tseo di ka kopisiwago.',
            'criteria_copy_same' => 'Thentara e ka se kopise dintlha tsa yona go yona.',
        ],
        'portal' => [
            'bid_sealed' => 'Kgopelo ya gago e notletšwe gomme e amogetšwe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Kgato :step e amogela ka motho yo a boletšwego, ka fao go swanetše go kgethwa motho.',
            'step_quorum_impossible' => 'Kgato :step e bolela motho o tee eupša e nyaka ditshaeno tše :quorum, tšeo di ka se kgonagalego. Šomiša karolo goba tumelelo go nyaka ba-amogedi ba bantši.',
            'step_needs_approver' => 'Kgato :step e amogela ka :what, ka fao go swanetše go kgethwa :what.',
            'not_found' => 'Ketane yeo ya tumelelo ga e sa le gona.',
            'in_flight' => ':name e sa na le dikgopelo tše :count tseleng. Feditše diphetho tšeo pele, goba fetoša ketane go e-na le go e emiša.',
            'only_default' => ':name ke yona fela ya motheo ya :subject. Dira gore ketane ye nngwe e be ya motheo pele, go sego bjalo kgopelo ye e latelago ga e na moo e ka yago.',
        ],
        'framework' => [
            'split_total' => 'Karogano ya theko le kgetho e swanetše go ba 100; e go :total.',
            'band_duplicate' => 'Banta :band e boeletša ":value", yeo banta :other e šetšego e e tšere. Ya mathomo e be e tla fenya ka mehla.',
            'band_above_max' => 'Banta :band e fa dintlha tše :points, tšeo di fetago mollwane wa tlhako ye wa :max.',
            'not_found' => 'Tlhako yeo ya kgetho ga e sa le gona.',
            'in_use' => ':name e boletšwe ke ditendara tše :count. E emiše go e-na le moo, gore ditendara tšeo di boloke melao yeo di filwego dintlha ka fase ga yona.',
        ],
        'bid' => [
            'tender_closed' => 'Tendara ye e tswaletšwe, ka fao ga go bakgopedi ba bangwe bao ba ka laletšwago.',
            'invitation_withdrawn' => 'Taletšo ye e gogilwe morago.',
            'already_submitted' => 'Kgopelo e šetše e romilwe ka fase ga taletšo ye. Kgopela mohlankedi wa theko go e goga morago ge o nyaka go e fetoša.',
            'amount_required' => 'Go nyakega boleng bja kgopelo.',
            'invitation_not_found' => 'Taletšo yeo ga e sa le gona.',
            'already_opened' => 'Kgopelo ye e šetše e bulwe gomme e ka se gogwe morago. E ntšhe go e-na le moo, gore lebaka le be rekotong.',
            'tender_not_found' => 'Tendara yeo ga e sa le gona.',
        ],
        'document' => [
            'already_opened' => 'Kgopelo ye e šetše e bulwe, ka fao ga go ditokumente tše dingwe tšeo di ka kgomaretšwago go yona.',
            'not_found' => 'Tokumente yeo ga e sa le gona.',
            'not_opened_yet' => 'Kgopelo ye ga se ya bulwa. Ditokumente tša yona di dula di notletšwe go fihlela go bulwa.',
            'digest_mismatch' => 'Tokumente ye ya kgopelo ga e sepelelane le palo yeo e ngwadilwego ge e romelwa. Ga se tokumente yeo e romilwego gomme e ka se tshepšwe.',
        ],
        'opening' => [
            'not_closed' => 'Tendara ga se ya tswalela. Dikgopelo di dula di notletšwe go fihlela nako ya go tswalela.',
            'already_opened' => 'Dikgopelo tše di šetše di bulwe. Go bula go direga gatee.',
            'needs_two' => 'Go bula go nyaka batho ba babedi: mohlankedi yo a bulago le hlatse.',
            'witness_distinct' => 'Hlatse e swanetše go ba motho yo mongwe e sego mohlankedi yo a bulago dikgopelo.',
            'no_bids' => 'Ga go dikgopelo tšeo di romilwego tendareng ye.',
        ],
        'seal' => [
            'key_unavailable' => 'Sekano sa tendara ye se ka se bulwe ka senotlelo sa bjale sa tirišo. Dikgopelo tšeo di notletšwego ka fase ga senotlelo sa peleng di ka se hwetšwe — bušetša senotlelo pele o bula.',
            'decrypt_failed' => 'Kgopelo ye e notletšwego e ka se bulwe. E ka ba e fetotšwe polokelong; e ka se fiwe dintlha go fihlela seo se hlalošwa.',
            'digest_mismatch' => 'Kgopelo ye e notletšwego ga e sepelelane le palo yeo e ngwadilwego ge e romelwa. Kgopelo yeo e bolokilwego ga se kgopelo yeo e romilwego, gomme tendara ye e ka se bulwe go fihlela seo se nyakišišwa.',
            'not_a_bid' => 'Kgopelo ye e notletšwego e bulegile ya ba selo seo e sego kgopelo.',
            'document_decrypt_failed' => 'Tokumente ya kgopelo ye e notletšwego e ka se bulwe.',
        ],
        'currency' => [
            'no_rate' => 'Ga go tekanyo ya phetošo ya :from go ya go :to yeo e tsentšwego ya :date, ka fao dikgopelo tše di ka se bapetšwe. Tsenya tekanyo go Finance, goba ngwala kgopelo ka :to.',
        ],
    ],
];
