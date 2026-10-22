<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (tn)
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
            'reason_required' => 'Go gana go tshwanetse go kwala lebaka la gone.',
            'recorded_next_level' => 'Go kwadilwe. Tetla e fetetse mo maemong a a latelang.',
            'decided' => 'Tetla :status.',
        ],
        'committees' => [
            'created' => 'Komiti :reference e dirilwe.',
            'member_added' => 'Leloko le tsentswe.',
            'membership_ended' => 'Boleloko bo felile. Rekoto ya hisitori e bolokilwe.',
            'not_constituted' => 'Komiti ga e a tlhomiwa sentle: :problems',
            'activated' => 'Komiti :reference e a dira.',
            'conflict_resolved' => 'Kgotlhang e rarabolotswe jaaka :resolution.',
        ],
        'deviations' => [
            'created' => 'Go fapoga :reference go dirilwe jaaka kwalodiretswa.',
            'updated' => 'Go fapoga go tokafaditswe.',
            'acceptance_reason_required' => 'Go amogela ditlhagiso tsa kobamelo go tlhoka lebaka.',
            'cannot_submit' => 'Ga go kgonege go romela: :problems',
            'submitted' => 'Go fapoga :reference go romeletswe tetla.',
            'moved' => 'Go fapoga go isitswe kwa :status.',
            'document_attached' => 'Lokwalo lo tshwaraganeditswe.',
            'type_saved' => 'Mofuta wa go fapoga o bolokilwe.',
        ],
        'documents' => [
            'version_uploaded' => 'Pheteletso e ntšha e tsentswe. Pheteletso e e fetileng e sa ntse e le teng.',
            'linked' => 'Lokwalo lo golagantswe. Go ne go sa tlhokege go lo tsenya gape.',
            'acknowledged' => 'Kamogelo e kwadilwe.',
            'access_granted' => 'Phitlhelelo e neilwe.',
        ],
        'evaluations' => [
            'draft_saved' => 'Kwalodiretswa e bolokilwe.',
            'submitted' => 'Tekolo e rometswe. Jaanong e lotletswe.',
            'conflict_declared' => 'Kgotlhang e phatlaladitswe. Molaodi o itsisitswe mme o ka se nee dintlha go fitlha e rarabolotswe.',
            'declaration_recorded' => 'Phatlalatso e kwadilwe.',
            'confidentiality_accepted' => 'Boitlamo jwa sephiri bo amogetswe.',
            'not_your_clarification' => 'Tlhaloso eo e ne e tsositswe mo motlhatlhobing o mongwe. Ke motlhatlhobi yo e neng e lebisitswe mo go ene fela yo o ka e arabang.',
            'clarification_answered' => 'Karabo ya gago e kwadilwe mme tlhaloso e tswetswe.',
        ],
        'insights' => [
            'report_generated' => 'Pegelo e e reng ":title" e dirilwe mme ya bolokwa.',
            'alert_updated' => 'Tlhagiso ya bokgoni e tokafaditswe.',
        ],
        'plans' => [
            'created' => 'Leano la theko :reference le dirilwe.',
            'line_added' => 'Mola wa leano :reference o tsentswe.',
            'line_updated' => 'Mola wa leano :reference o tokafaditswe.',
            'moved' => 'Leano le isitswe kwa :status.',
            'only_approved_live' => 'Ke leano le le amogetsweng fela le le ka dirang gore mela ya lona e dire.',
            'line_live' => 'Mola wa leano :line o a dira jaaka :record.',
            'budget_unavailable' => 'Taolo ya Tekanyetso ga e bonwe, ka jalo dipalo tse di ipofileng le tsa nnete di ka se ntšhafadiwe.',
            'budget_refreshed' => 'Dipalo tsa tekanyetso di ntšhafaditswe.',
        ],
        'records' => [
            'moved' => 'Theko e isitswe kwa :stage.',
            'officer_updated' => 'Motlhankedi yo o ikarabelang o tokafaditswe.',
            'no_links_found' => 'Ga go direkoto tse di golagantsweng tse di bonweng mo dimojuleng tse dingwe.',
            'links_found' => 'Dikgolagano tse :count di bonwe mo dimojuleng tse dingwe.',
            'document_attached' => 'Lokwalo lo tshwaraganeditswe le theko eno.',
        ],
        'settings' => [
            'threshold_order' => 'Molelwane wa go imelwa o tshwanetse go nna kwa godimo ga molelwane o o kwa godimo.',
            'saved' => 'Dipeakanyo tsa Taolo ya Theko di bolokilwe.',
            'rule_set_created' => 'Sete ya melao :code v:version e dirilwe.',
            'rule_set_frozen' => 'Sete ya melao e tswetswe. Diphetogo jaanong di tlhoka pheteletso e ntšha.',
            'rule_set_is_frozen' => 'Sete eno ya melao e tswetswe. Dira pheteletso e ntšha go fetola melao ya yona.',
            'rule_saved' => 'Molao :code o bolokilwe.',
            'rule_not_found' => 'Molao ga o bonwe.',
            'rule_deactivated' => 'Molao o emisitswe.',
            'chain_saved' => 'Keetane ya tetla :code e bolokilwe.',
            'chain_retired' => 'Keetane ya tetla :name e emisitswe.',
            'framework_saved' => 'Morero wa kgetho :name o bolokilwe.',
            'framework_retired' => 'Morero wa kgetho :name o emisitswe.',
            'notification_saved' => 'Molao wa kitsiso :code o bolokilwe.',
            'category_saved' => 'Setlhopha sa lokwalo :code se bolokilwe.',
        ],
        'tenders' => [
            'weights_total' => 'Bokete jwa tlhwatlhwa le kgetho bo tshwanetse go nna 100; bo mo go :total.',
            'initiated' => 'Tlhatlhobo ya tendara :reference e simolotswe.',
            'criterion_added' => 'Tekanyetso e tsentswe.',
            'criterion_updated' => 'Tekanyetso e tokafaditswe.',
            'criterion_removed' => 'Tekanyetso e tlositswe.',
            'criteria_change_requested' => 'Phetogo ya ditekanyetso e kopilwe. E dira fa e sena go amogelwa.',
            'invitation_created' => 'Taletso e dirilwe.',
            'bid_withdrawn' => 'Kopo e e lotletsweng e gogetswe morago mme e ka romelwa gape.',
            'bids_opened' => 'Dikopo tse di lotletsweng tse :count di bulwe.',
            'bid_recorded' => 'Kopo e e tswang go :supplier e kwadilwe.',
            'bidder_disqualified' => 'Mokopi o ntshitswe.',
            'bidder_reinstated' => 'Mokopi o busitswe.',
            'committee_assigned' => 'Komiti e abetswe; batlhatlhobi ba :count ba tsentswe mo tlhatlhobong eno.',
            'evaluation_blocked' => 'Tekolo e ka se bule gone jaanong: :problems',
            'evaluation_open' => 'Tekolo e bulegile. Ditekanyetso jaanong di tswetswe.',
            'moved' => 'Tlhatlhobo e isitswe kwa :status.',
            'scores_recomputed' => 'Dintlha tsa komiti le tatelano di balwa gape.',
            'moderation_recorded' => 'Tokafatso e kwadilwe.',
            'moderation_not_open' => 'Tokafatso ga e a bulwa mo tlhatlhobong eno.',
            'scores_approved' => 'Dintlha tsa bofelo tsa komiti di amogetswe. Kgothaletso jaanong e ka dirwa.',
            'recommendation_generated' => 'Pegelo ya kgothaletso :reference v:version e dirilwe.',
            'recommendation_submitted' => 'Kgothaletso e romeletswe tetla.',
            'recommendation_decided' => 'Kgothaletso :decision.',
            'criteria_copied' => 'Dintlha tse :count di kopilwe mo tenteng ya pele.',
            'criteria_copy_empty' => 'Tente eo ga e na dintlha tse di ka kopiwang.',
            'criteria_copy_same' => 'Tente ga e kgone go kopa dintlha tsa yona mo go yona.',
        ],
        'portal' => [
            'bid_sealed' => 'Kopo ya gago e lotletswe mme e amogetswe.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Kgato :step e amogela ka motho yo o umakilweng, ka jalo go tshwanetse go tlhophiwa motho.',
            'step_quorum_impossible' => 'Kgato :step e umaka motho a le mongwe mme e kopa ditshaeno tse :quorum, se se ka se kgonege. Dirisa karolo kgotsa tetla go tlhoka ba-amogedi ba le bantsi.',
            'step_needs_approver' => 'Kgato :step e amogela ka :what, ka jalo go tshwanetse go tlhophiwa :what.',
            'not_found' => 'Keetane eo ya tetla ga e sa tlhole e le teng.',
            'in_flight' => ':name e sa ntse e na le dikopo tse :count mo tseleng. Wetsa ditshwetso tseo pele, kgotsa fetola keetane go na le go e emisa.',
            'only_default' => ':name ke yona fela ya tlwaelo ya :subject. Dira gore keetane e nngwe e nne ya tlwaelo pele, go seng jalo kopo e e latelang ga e na kwa e ka yang.',
        ],
        'framework' => [
            'split_total' => 'Kgaogano ya tlhwatlhwa le kgetho e tshwanetse go nna 100; e mo go :total.',
            'band_duplicate' => 'Banta :band e boeletsa ":value", e banta :other e setseng e e tseile. Ya ntlha e ne e tla fenya ka metlha.',
            'band_above_max' => 'Banta :band e naya dintlha tse :points, tse di fetang molelwane wa morero ono wa :max.',
            'not_found' => 'Morero oo wa kgetho ga o sa tlhole o le teng.',
            'in_use' => ':name e umakilwe ke ditendara tse :count. E emise go na le moo, gore ditendara tseo di boloke melao e di neilweng dintlha ka fa tlase ga yona.',
        ],
        'bid' => [
            'tender_closed' => 'Tendara eno e tswetswe, ka jalo ga go bakopi ba bangwe ba ba ka laleditswang.',
            'invitation_withdrawn' => 'Taletso eno e gogetswe morago.',
            'already_submitted' => 'Kopo e setse e rometswe ka fa tlase ga taletso eno. Kopa motlhankedi wa theko go e gogela morago fa o tlhoka go e emisetsa.',
            'amount_required' => 'Go tlhokega boleng jwa kopo.',
            'invitation_not_found' => 'Taletso eo ga e sa tlhole e le teng.',
            'already_opened' => 'Kopo eno e setse e bulwe mme e ka se gogelwe morago. E ntshe go na le moo, gore lebaka le nne mo rekotong.',
            'tender_not_found' => 'Tendara eo ga e sa tlhole e le teng.',
        ],
        'document' => [
            'already_opened' => 'Kopo eno e setse e bulwe, ka jalo ga go dikwalo tse dingwe tse di ka tshwaraganediwang le yona.',
            'not_found' => 'Lokwalo loo ga lo sa tlhole lo le teng.',
            'not_opened_yet' => 'Kopo eno ga e ise e bulwe. Dikwalo tsa yona di nna di lotletswe go fitlha go bulwa.',
            'digest_mismatch' => 'Lokwalo lono lwa kopo ga lo tsamaelane le palo e e kwadilweng fa lo romelwa. Ga se lokwalo lo lo neng lo romelwa mme ga lo a tshwanela go ikanngwa.',
        ],
        'opening' => [
            'not_closed' => 'Tendara ga e ise e tswale. Dikopo di nna di lotletswe go fitlha nako ya go tswala.',
            'already_opened' => 'Dikopo tseno di setse di bulwe. Go bula go diragala gangwe.',
            'needs_two' => 'Go bula go tlhoka batho ba babedi: motlhankedi yo o bulang le paki.',
            'witness_distinct' => 'Paki e tshwanetse go nna motho yo mongwe go sa nne motlhankedi yo o bulang dikopo.',
            'no_bids' => 'Ga go dikopo tse di rometsweng mo tendareng eno.',
        ],
        'seal' => [
            'key_unavailable' => 'Sekano sa tendara eno se ka se bulwe ka senotlolo sa gone jaanong sa tirisi. Dikopo tse di lotletsweng ka fa tlase ga senotlolo se se fetileng di ka se bonwe — busa senotlolo pele o bula.',
            'decrypt_failed' => 'Kopo e e lotletsweng e ka se bulwe. E ka tswa e fetotswe mo polokelong; e ka se newe dintlha go fitlha go tlhalosiwa.',
            'digest_mismatch' => 'Kopo e e lotletsweng ga e tsamaelane le palo e e kwadilweng fa e romelwa. Kopo e e bolokilweng ga se kopo e e neng e romelwa, mme tendara eno e ka se bulwe go fitlha go batlisisiwa.',
            'not_a_bid' => 'Kopo e e lotletsweng e bulegile ya nna selo se e seng kopo.',
            'document_decrypt_failed' => 'Lokwalo lwa kopo e e lotletsweng lo ka se bulwe.',
        ],
        'currency' => [
            'no_rate' => 'Ga go selekanyo sa phetogo sa :from go ya go :to se se tsentsweng sa :date, ka jalo dikopo tseno di ka se bapisiwe. Tsenya selekanyo mo go Finance, kgotsa kwala kopo ka :to.',
        ],
    ],
];
