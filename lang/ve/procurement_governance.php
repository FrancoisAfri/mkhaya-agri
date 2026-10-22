<?php

/*
|--------------------------------------------------------------------------
| Procurement Governance — server-generated messages (ve)
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
            'reason_required' => 'U hana hu fanela u ṅwala tshiitisi tshaho.',
            'recorded_next_level' => 'Zwo ṅwalwa. Thendelo yo ya kha vhuimo vhu tevhelaho.',
            'decided' => 'Thendelo :status.',
        ],
        'committees' => [
            'created' => 'Komiti :reference yo sikwa.',
            'member_added' => 'Muraḓo wo engedzwa.',
            'membership_ended' => 'Vhuraḓo ho fhela. Rekhodo ya ḓivhazwakale yo vhulungwa.',
            'not_constituted' => 'Komiti a yo ngo vhumbwa nga nḓila yo teaho: :problems',
            'activated' => 'Komiti :reference i a shuma.',
            'conflict_resolved' => 'U lwisana ho tandululwa sa :resolution.',
        ],
        'deviations' => [
            'created' => 'U ṱutshela :reference ho sikwa sa tshinwalwana.',
            'updated' => 'U ṱutshela ho swayiswa.',
            'acceptance_reason_required' => 'U ṱanganedza tsevho dza u tevhedza hu ṱoḓa tshiitisi.',
            'cannot_submit' => 'A zwi konadzei u rumela: :problems',
            'submitted' => 'U ṱutshela :reference ho rumelwa uri hu tendelwe.',
            'moved' => 'U ṱutshela ho iswa kha :status.',
            'document_attached' => 'Lunwalo lwo nambatedzwa.',
            'type_saved' => 'Lushaka lwa u ṱutshela lwo vhulungwa.',
        ],
        'documents' => [
            'version_uploaded' => 'Ṱhanziela ntswa yo dzheniswa. Ṱhanziela ya kale i kha ḓi vha hone.',
            'linked' => 'Lunwalo lwo ṱanganyiswa. Ho vha hu songo ṱoḓea u lu dzhenisa hafhu.',
            'acknowledged' => 'U ṱanganedza ho ṅwalwa.',
            'access_granted' => 'U swikelela ho ṋewa.',
        ],
        'evaluations' => [
            'draft_saved' => 'Tshinwalwana tsho vhulungwa.',
            'submitted' => 'U sengulusa ho rumelwa. Zwino ho valwa.',
            'conflict_declared' => 'U lwisana ho ḓivhadzwa. Mulanguli o ḓivhadzwa nahone a ni nga ṋei maphuzu u swika ho tandululwa.',
            'declaration_recorded' => 'Nḓivhadzo yo ṅwalwa.',
            'confidentiality_accepted' => 'U ḓiṋekedza ha tshiphiri ho ṱanganedzwa.',
            'not_your_clarification' => 'Yeneyo thalutshedzo yo itelwa munwe mutoli. Ndi mutoli we ya livhiswa khae fhedzi ane a nga i fhindula.',
            'clarification_answered' => 'Phindulo yanu yo rekhodiwa nahone thalutshedzo yo valwa.',
        ],
        'insights' => [
            'report_generated' => 'Muvhigo une wa ri ":title" wo itwa nahone wa vhulungwa.',
            'alert_updated' => 'Tsevho ya vhukoni yo swayiswa.',
        ],
        'plans' => [
            'created' => 'Mano a u renga :reference o sikwa.',
            'line_added' => 'Mutalo wa mano :reference wo engedzwa.',
            'line_updated' => 'Mutalo wa mano :reference wo swayiswa.',
            'moved' => 'Mano o iswa kha :status.',
            'only_approved_live' => 'Ndi mano o tendelwaho fhedzi ane a nga ita uri mitalo yawo i shume.',
            'line_live' => 'Mutalo wa mano :line u a shuma sa :record.',
            'budget_unavailable' => 'Ndangulo ya Mugaganyagwama a i wanali, ngauralo nomboro dzo ḓiṋekedzaho na dza vhukuma a dzi nga swayiswi.',
            'budget_refreshed' => 'Nomboro dza mugaganyagwama dzo swayiswa.',
        ],
        'records' => [
            'moved' => 'U renga ho iswa kha :stage.',
            'officer_updated' => 'Mushumeli ane a ḓifhinduleli o swayiswa.',
            'no_links_found' => 'A hu na dzirekhodo dzo ṱanganyiswaho dzo wanalaho kha dziṅwe modulu.',
            'links_found' => 'Zwiṱanganyisi zwa :count zwo wanala kha dziṅwe modulu.',
            'document_attached' => 'Lunwalo lwo nambatedzwa kha uhu u renga.',
        ],
        'settings' => [
            'threshold_order' => 'Mukano wa u lemelwa u fanela u vha nṱha ha mukano muhulwane.',
            'saved' => 'Nzudzanyo dza Vhuvhusi ha u Renga dzo vhulungwa.',
            'rule_set_created' => 'Sethe ya milayo :code v:version yo sikwa.',
            'rule_set_frozen' => 'Sethe ya milayo yo valwa. Tshanduko zwino dzi ṱoḓa ṱhanziela ntswa.',
            'rule_set_is_frozen' => 'Iyi sethe ya milayo yo valwa. Sikani ṱhanziela ntswa uri ni shandukise milayo yayo.',
            'rule_saved' => 'Mulayo :code wo vhulungwa.',
            'rule_not_found' => 'Mulayo a u wanali.',
            'rule_deactivated' => 'Mulayo wo imiswa.',
            'chain_saved' => 'Khethani ya thendelo :code yo vhulungwa.',
            'chain_retired' => 'Khethani ya thendelo :name yo imiswa.',
            'framework_saved' => 'Tshivhumbeo tsha u nangwa :name tsho vhulungwa.',
            'framework_retired' => 'Tshivhumbeo tsha u nangwa :name tsho imiswa.',
            'notification_saved' => 'Mulayo wa ndivhadzo :code wo vhulungwa.',
            'category_saved' => 'Tshigwada tsha lunwalo :code tsho vhulungwa.',
        ],
        'tenders' => [
            'weights_total' => 'Vhulemu ha mutengo na u nangwa vhu fanela u vha 100; vhu kha :total.',
            'initiated' => 'Ṱhoḓisiso ya thendara :reference yo thoma.',
            'criterion_added' => 'Tshilinganyo tsho engedzwa.',
            'criterion_updated' => 'Tshilinganyo tsho swayiswa.',
            'criterion_removed' => 'Tshilinganyo tsho bviswa.',
            'criteria_change_requested' => 'U shandukisa zwilinganyo ho humbelwa. Zwi shuma musi zwo tendelwa.',
            'invitation_created' => 'U rambiwa ho sikwa.',
            'bid_withdrawn' => 'Khumbelo yo valwaho yo bviswa nahone i nga rumelwa hafhu.',
            'bids_opened' => 'Khumbelo dzo valwaho dza :count dzo vulwa.',
            'bid_recorded' => 'Khumbelo i bvaho kha :supplier yo ṅwalwa.',
            'bidder_disqualified' => 'Mukhumbeli o bviswa.',
            'bidder_reinstated' => 'Mukhumbeli o vhuyedzedzwa.',
            'committee_assigned' => 'Komiti yo kovhekanywa; vhasenguluse vha :count vho engedzwa kha iyi ṱhoḓisiso.',
            'evaluation_blocked' => 'U sengulusa a hu nga vulwi zwino: :problems',
            'evaluation_open' => 'U sengulusa ho vulwa. Zwilinganyo zwino zwo valwa.',
            'moved' => 'Ṱhoḓisiso yo iswa kha :status.',
            'scores_recomputed' => 'Maphuzu a komiti na u tevhekana zwo vhalwa hafhu.',
            'moderation_recorded' => 'U lugisa ho ṅwalwa.',
            'moderation_not_open' => 'U lugisa a ho ngo vulwa kha iyi ṱhoḓisiso.',
            'scores_approved' => 'Maphuzu a u fhedzisela a komiti o tendelwa. Themendelo zwino i nga itwa.',
            'recommendation_generated' => 'Muvhigo wa themendelo :reference v:version wo itwa.',
            'recommendation_submitted' => 'Themendelo yo rumelwa uri i tendelwe.',
            'recommendation_decided' => 'Themendelo :decision.',
            'criteria_copied' => 'Zwipimo zwa :count zwo kopiwa kha thendara ya u thoma.',
            'criteria_copy_empty' => 'Yeneyo thendara a i na zwipimo zwine zwa nga kopiwa.',
            'criteria_copy_same' => 'Thendara a i nga koni u kopa zwipimo zwayo kha yone.',
        ],
        'portal' => [
            'bid_sealed' => 'Khumbelo yaṋu yo valwa nahone ya ṱanganedzwa.',
        ],
    ],
    'rules' => [
        'chain' => [
            'step_needs_person' => 'Gwada :step ḽi tendela nga muthu o bulwaho, ngauralo hu fanela u nangwa muthu.',
            'step_quorum_impossible' => 'Gwada :step ḽi bula muthu muthihi fhedzi ḽi humbela dzisaini dza :quorum, zwine zwa sa nga koneli. Shumisani mushumo kana thendelo arali ni tshi ṱoḓa vhaṱanganedzi vhanzhi.',
            'step_needs_approver' => 'Gwada :step ḽi tendela nga :what, ngauralo hu fanela u nangwa :what.',
            'not_found' => 'Iyo khethani ya thendelo a i tsheho.',
            'in_flight' => ':name i kha ḓi vha na khumbelo dza :count nḓilani. Fhedzisani dzenedzo tsheo u thoma, kana ni lugise khethani nṱhani ha u i imisa.',
            'only_default' => ':name ndi yone fhedzi ya mathomo ya :subject. Itani uri iṅwe khethani i vhe ya mathomo u thoma, hu si zwauralo khumbelo i tevhelaho a i na hune ya nga ya hone.',
        ],
        'framework' => [
            'split_total' => 'U fhandekanywa ha mutengo na u nangwa hu fanela u vha 100; hu kha :total.',
            'band_duplicate' => 'Mubande :band u dovholola ":value", une mubande :other wo no u dzhia. Wa u thoma wo vha u tshi ḓo kunda tshifhinga tshoṱhe.',
            'band_above_max' => 'Mubande :band u ṋea maphuzu a :points, ane a fhira mukano wa hetshi tshivhumbeo wa :max.',
            'not_found' => 'Tshenetsho tshivhumbeo tsha u nangwa a tshi tsheho.',
            'in_use' => ':name yo bulwa nga dzithendara dza :count. I imiseni nṱhani ha zwenezwo, uri dzenedzo thendara dzi vhulunge milayo dze dza ṋewa maphuzu nga yone.',
        ],
        'bid' => [
            'tender_closed' => 'Ino thendara yo valwa, ngauralo a hu na vhaṅwe vhakhumbeli vhane vha nga rambiwa.',
            'invitation_withdrawn' => 'Ho u rambiwa ho bviswa.',
            'already_submitted' => 'Khumbelo yo no rumelwa nga fhasi ha ho u rambiwa. Humbelani mushumeli wa u renga uri a i bvise arali ni tshi ṱoḓa u i shandukisa.',
            'amount_required' => 'Hu ṱoḓea ndeme ya khumbelo.',
            'invitation_not_found' => 'Ho u rambiwa a hu tsheho.',
            'already_opened' => 'Ino khumbelo yo no vulwa nahone a i nga bviswi. I bviseni nga u i hana nṱhani ha zwenezwo, uri tshiitisi tshi vhe kha rekhodo.',
            'tender_not_found' => 'Iyo thendara a i tsheho.',
        ],
        'document' => [
            'already_opened' => 'Ino khumbelo yo no vulwa, ngauralo a hu na maṅwe manwalo ane a nga nambatedzwa khayo.',
            'not_found' => 'Ulwo lunwalo a lu tsheho.',
            'not_opened_yet' => 'Ino khumbelo a i athu vulwa. Manwalo ayo a dzula o valwa u swika hu tshi vulwa.',
            'digest_mismatch' => 'Ulu lunwalo lwa khumbelo a lu tshimbidzani na tshivhalo tsho ṅwalwaho musi lu tshi rumelwa. A si lwone lunwalo lwo rumelwaho nahone a lu fanelwi u fhulufhedzwa.',
        ],
        'opening' => [
            'not_closed' => 'Thendara a i athu valwa. Khumbelo dzi dzula dzo valwa u swika tshifhinga tsha u vala.',
            'already_opened' => 'Idzi khumbelo dzo no vulwa. U vula hu itea luthihi.',
            'needs_two' => 'U vula hu ṱoḓa vhathu vhavhili: mushumeli a vulaho na ṱhanzi.',
            'witness_distinct' => 'Ṱhanzi i fanela u vha muṅwe muthu hu si mushumeli a vulaho khumbelo.',
            'no_bids' => 'A hu na khumbelo dzo rumelwaho kha ino thendara.',
        ],
        'seal' => [
            'key_unavailable' => 'Tshifungo tsha ino thendara a tshi nga vulwi nga khiye ya zwino ya tshishumiswa. Khumbelo dzo valwaho nga khiye ya kale a dzi nga wanali — vhuyedzedzani khiye ni sa athu vula.',
            'decrypt_failed' => 'Khumbelo yo valwaho a yo ngo konadzea u vulwa. I nga vha yo shandukiswa kha vhuvhulungeli; a i fanelwi u ṋewa maphuzu u swika zwenezwo zwo ṱalutshedzwa.',
            'digest_mismatch' => 'Khumbelo yo valwaho a i tshimbidzani na tshivhalo tsho ṅwalwaho musi i tshi rumelwa. Khumbelo yo vhulungwaho a si yone yo rumelwaho, nahone ino thendara a i fanelwi u vulwa u swika zwenezwo zwo ṱoḓisiswa.',
            'not_a_bid' => 'Khumbelo yo valwaho yo vuleka ya vha tshithu tshi si khumbelo.',
            'document_decrypt_failed' => 'Lunwalo lwa khumbelo yo valwaho a lwo ngo konadzea u vulwa.',
        ],
        'currency' => [
            'no_rate' => 'A hu na tshikalo tsha u shandukisa tsha :from u ya kha :to tsho dzheniswaho tsha :date, ngauralo idzi khumbelo a dzi nga vhambedzwi. Dzhenisani tshikalo kha Finance, kana ni ṅwale khumbelo nga :to.',
        ],
    ],
];
