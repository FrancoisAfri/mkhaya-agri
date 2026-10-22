<?php

/**
 * Maipfi a Tshivenḓa a Ndangulo ya Vhaṋei.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'Zwo lindela',
        1 => 'Zwi khou shuma',
        2 => 'Zwo hanwa',
        3 => 'KYC yo lindela',
    ],
    'suppliers' => [
        'activated' => 'U vulwa ha muṋei zwo fhelela.',
        'refused' => 'Muṋei o hanwa, nahone o vhudzwa uri ndi ngani.',
        'info_requested' => 'Khumbelo ya mafhungo yo rumelwa kha muṋei.',
        'portal_link_rotated' => 'Vhukwamani vhuswa ha portal ho bveledzwa. Vhukwamani ha u thoma a vhu tsha shuma.',
        'portal_link_reissued' => 'Vhukwamani haṋu ha portal ho shandukiswa u itela tsireledzo. Shumisani vhukwamani vhuswa nga fhasi.',
        'outstanding_documents' => 'Ri kha ḓi ṱoḓa zwi tevhelaho kha inwi: :documents',
        'errors' => [
            'duplicate' => 'Nomboro ya u ṅwalisa kana imeili hezwi zwo no vha zwa :supplier (:number). Vulani rekhodo yeneyo nṱhani ha u sika ya vhuvhili.',
            'missing_documents' => 'Uyu muṋei a nga si vulwe u swikela hezwi zwiṅwalwa zwa ndeme zwi hone nahone zwi tshi shuma: :documents',
            'blacklisted' => 'Sia ḽino ḽi kha mutevhe mutsu — :matched. Tshiitisi tsho ṅwalwaho: :reason. Arali ḽino ḽi sia ḽiṅwe, kana u thivhela hu tea u bviswa, zwenezwo ndi tsheo ya vhulanguli nṱha musi muṋetshedzi a sa athu engedzwa.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Ndaela yo rumelwa. Vhukwamani ha u thoma, arali hu hone, a vhu tsha shuma.',
        'mail_subject' => 'No rambiwa u ṅwalisa sa muṋei kha :company',
        'mail_greeting' => 'Ndaa :name,',
        'mail_intro' => ':company i ṱoḓa u ni engedza kha mutevhe wayo wa vhaṋei vho tendelwaho. U ṅwalisa zwi dzhia miṱodzi mimalwa.',
        'mail_action' => 'Thomani u ṅwalisa',
        'mail_expiry' => 'Uyu vhukwamani vhu shuma u swikela :date. Arali vhu fhela, humbelani muvhudzisani waṋu a ni rumele vhuswa.',
        'mail_closing' => 'U vha na zwiṅwalwa zwa nṱha ni sa athu thoma zwi ḓo ita uri hezwi zwi ṱavhanye.',
    ],
    'decisions' => [
        'greeting' => 'Ndaa :name,',
        'comment_label' => 'Manwalwa a mulingi:',
        'mail_action' => 'Vulani portal yaṋu ya muṋei',
        'mail_closing' => 'Fhindulani heyi imeili arali hu na tshine tsha vhonala tshi songo lugaho.',
        'approved' => [
            'mail_subject' => 'Zwino ni muṋei wo tendelwaho kha :company',
            'mail_body' => 'U ṅwalisa haṋu ho tendelwa. :company zwino i nga ita odara kha inwi.',
        ],
        'refused' => [
            'mail_subject' => 'U ṅwalisa haṋu sa muṋei kha :company',
            'mail_body' => ':company a i koni u bvela phanḓa na u ṅwalisa haṋu zwino.',
        ],
        'information_requested' => [
            'mail_subject' => ':company i ṱoḓa maṅwe mafhungo kha inwi',
            'mail_body' => 'U ṅwalisa haṋu ho imiswa u swikela ri tshi wana zwiṅwe kha inwi.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Vhukwamani haṋu vhuswa ha portal ha :company',
            'mail_body' => 'Vhukwamani haṋu ha portal ho shandukiswa u itela tsireledzo. Vhukwamani ha u thoma a vhu tsha shuma — shumisani bathene ya fhasi u bva zwino.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC yo tendelwa; muṋei zwino u khou shuma.',
        'rejected' => 'KYC yo hanwa, nahone muṋei o vhudzwa uri ndi ngani.',
        'mail_subject' => 'Ṱhoḓisiso ya KYC i a ṱoḓea: :supplier',
        'mail_greeting' => 'Ndaa,',
        'mail_body' => ':supplier i khou lindela phetho ya KYC.',
        'mail_action' => 'Sedzani mutevhe wa KYC',
        'errors' => [
            'already_decided' => 'Hezwi zwo rumelwaho zwo no vha :status. Vulani muṋei hafhu arali phetho i tshi ṱoḓa u sedzuluswa.',
            'self_review' => 'Ndi inwi no sikaho uyu muṋei, nga zwenezwo a ni nga tendeli KYC yawe. I ṱoḓa mulingi wa vhuvhili.',
        ],
    ],
    'rfx' => [
        'published' => 'Tshiitea tsho ḓivhadzwa. Vhaṋei vho rambiwaho zwino vha nga ita bid.',
        'closed' => 'Tshiitea tsho valwa. A hu na maṅwe ma-bid ane a ḓo ṱanganedzwa.',
        'response_recorded' => 'Bid yo ṅwaliwa.',
        'evaluated' => 'Ṱhoḓisiso ya AI ya ma-bid yo fhela.',
        'awarded' => 'Tshiitea tsho ṋewa.',
        'statuses' => [
            'draft' => 'tsengo',
            'published' => 'tsho ḓivhadzwa',
            'closed' => 'tsho valwa',
            'awarded' => 'tsho ṋewa',
            'cancelled' => 'tsho khanselwa',
        ],
        'errors' => [
            'invalid_transition' => 'Tshiitea tshine tsha vha :from a tshi nga pfulusiwi u ya kha :to.',
            'publish_without_invitations' => 'Rambani muṋei muthihi wa fhedzi ni sa athu ḓivhadza — tshiitea tshine hu si na muthu o humbelwaho u ita bid a tshi nga ṋewi.',
            'publish_after_closing' => 'Ḓuvha ḽa u vala ḽo no fhira. Ḽi pfulusani phanḓa ni sa athu ḓivhadza.',
            'bid_when_not_published' => 'Ma-bid a nga ṅwaliwa fhedzi musi tshiitea tsho ḓivhadzwa. Hetshi ndi :status.',
            'bid_after_closing' => 'Hetshi tshiitea tsho valwa nga :date. Bid ya murahu a i nga ṱanganedzwi.',
            'bid_from_uninvited' => ':supplier a songo rambiwa kha hetshi tshiitea. Vha rambeni u thoma arali vha tshi fanela u dzhenelela.',
            'award_without_bid' => ':supplier a songo ita bid kha hetshi tshiitea, nga zwenezwo a tshi nga ṋewi khavho.',
            'award_reason_required' => 'Heḽi a si bid ḽi re fhasi, nga zwenezwo u ṋea zwi ṱoḓa tshiitisi tsho ṅwaliwaho.',
        ],
    ],
    'orders' => [
        'created' => 'Odara ya u renga yo sikwa.',
        'updated' => 'Odara ya u renga yo khwiniswa.',
        'removed' => 'Odara ya u renga yo bviswa, nahone masheleni e ya vha i tshi a fara o vhofhololwa.',
        'statuses' => [
            'draft' => 'tsengo',
            'approved' => 'yo tendelwa',
            'received' => 'yo ṱanganedzwa',
            'closed' => 'yo valwa',
            'cancelled' => 'yo khanselwa',
        ],
        'errors' => [
            'supplier_not_active' => 'Uyu muṋei ndi :status nahone a nga si ṱanganedze dziodara ntswa dza u renga u swikela o vulwa hafhu.',
            'expired_documents' => 'Uyu muṋei u na zwiṅwalwa zwa ndeme zwo fhelaho nahone a nga si ṱanganedze dziodara ntswa: :documents',
            'invalid_transition' => 'Odara ya :from a i nga pfulusiwi u ya kha :to.',
            'self_approval' => 'Ndi inwi no itaho heyi odara, nga zwenezwo a ni nga i tendeli. I ṱoḓa mutendeli wa vhuvhili.',
        ],
    ],
    'invoices' => [
        'captured' => 'Invoyisi yo dzhielwa.',
        'captured_with_variance' => 'Invoyisi yo dzhielwa, fhedzi i fhira odara ya u renga :po nga :variance. Yo farwa u itela ṱhoḓisiso nahone a i nga badelwi u swikela zwenezwo zwo tandululwa.',
        'updated' => 'Invoyisi yo khwiniswa.',
        'removed' => 'Invoyisi yo bviswa, nahone u shumisa masheleni ho vhuyedzedzwa kha mugaganyagwama.',
        'errors' => [
            'duplicate_number' => 'Invoyisi :number yo no dzhielwa kha uyu muṋei. Vulani i re hone nṱhani ha u i dzhenisa luvhili.',
            'order_belongs_to_other_supplier' => 'Odara ya u renga :po yo itelwa muṅwe muṋei, nga zwenezwo heyi invoyisi a i nga tshimbidzani nayo.',
            'pay_with_open_variance' => 'Heyi invoyisi i fhira odara yayo ya u renga nahone yo farwa u itela ṱhoḓisiso. Tandululani phambano ni sa athu i swaya sa yo badelwaho.',
            'pay_with_unverified_banking' => 'Mbadelo yo imiswa nga ṅwambo wa zwidodombedzwa zwa bannga zwa muṋetshedzi uyu. :reason Khwaṱhisedzani akhaunto na muṋetshedzi ni sa athu badela.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence a si tshipiḓa tsha u ṅwalisa uhu. I engedza u khwaṱhisedza rejisiṱara, muthelo, bannga, vhuṋe, tshikoloto na vhukando kha ndangulo ya vhaṋetshedzi — ambani na Mkhaya u i engedza.',
    ],
    'verdict' => [
        'never_assessed' => 'A hu na ṱhoḓisiso yo teaho yo itwaho kha muṋetshedzi uyu.',
        'cleared' => 'Ṱhoḓisiso yo teaho yo bvelela — khombo :score/100, khoro dzoṱhe dzo kuna.',
        'blocked' => 'Ṱhoḓisiso yo teaho a yo ngo fhelela — khombo :score/100, zwithu zwa :count zwo salaho.',
        'sanctions' => 'U tshimbidzana na vhukando hune hu shuma ho ṅwalwa kha muṋetshedzi uyu.',
    ],
    'risk' => [
        'assessed' => 'Yo ṱolisiswa: :score kha 100 (:band).',
        'tiered' => 'Mushumo uyu u kha vhuimo :tier — :label. Ṱhoḓisiso dzine dzi ṱoḓea dzo vusuluswa.',
        'screened' => 'Vhathu vha :subjects vho ṱolisiswa. U tshimbidzana hutswa ha :new hu ṱoḓa tsheo.',
        'screening_unavailable' => 'A hu na tshisima tsha u sefa tsho vhewaho, ngauralo a hu na tshithu tsho ṱolisiswaho. Ṅwalani zwine ni zwi wana kha mitevhe yo ganḓiswaho, kana vheani tshisima nga fhasi ha Configure → Data sources.',
        'hit_recorded' => 'U tshimbidzana ho ṅwalwa. Hu vhalwa u lwisana na muṋetshedzi uyu u swikela muthu a ita tsheo.',
        'adjudicated' => 'Tsheo yo ṅwalwa. A i nga si imiswe hafhu nga u sefa ha tshifhinga tshi ḓaho.',
        'credit_recorded' => 'Muvhigo wa tshikoloto wo farwa.',
        'errors' => [
            'reason_required' => 'Ambani uri ndi ngani u tshimbidzana uhu hu bviswa. U hana hu si na ṱhalutshedzo a hu nga si fhambanywe na u puṱedza nga vhukhakhi nga murahu ha ṅwaha.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Mitevhe ya mbudziso ya :n yo rumelwa kha muṋetshedzi.',
        'recorded' => 'Phindulo dzo vhulungwa — :completeness% zwo fhelela.',
        'reviewed' => 'Mutevhe wa mbudziso wo ṱalusiwa sa wo ṱolisiswaho.',
    ],
    'alerts' => [
        'subject' => '[:severity] Tsevho ya muṋetshedzi — :supplier',
        'greeting' => 'Ndaa,',
        'context' => ':supplier zwino u kha tshigwada :band.',
        'action' => 'Vulani phasiphoto ya muṋetshedzi',
        'db_title' => 'Tsevho ya muṋetshedzi: :supplier',
        'routing_saved' => 'U rumela ha tsevho ho vhulungwa.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Tsheo yo ṅwalwa. Tshanduko heyi a i nga si bvelele hafhu kha bogisi ḽa zwine zwi dzhena.',
        'swept' => 'Ṱhoḓisiso dza :checks dzo itwa hafhu; tshanduko dza :changes dzo wanala.',
        'no_sources' => 'A hu na tshisima tsho vhewaho kha ṱhoḓisiso naho ifhio yo vha i tshi tea, ngauralo a hu na tshithu tsho itwaho hafhu. Zwikha zwi ṅwalwa u lwisana na muṋetshedzi hu si u dzumbiwa.',
        'errors' => [
            'reason_required' => 'Ambani uri ndi ngani tshanduko heyi i laṱwa. Nga murahu ha ṅwaha, u hana hu si na ṱhalutshedzo a hu nga si fhambanywe na u puṱedza nga vhukhakhi.',
        ],
    ],
    'cases' => [
        'opened' => 'Mulandu :reference wo vulwa.',
        'moved' => 'Mulandu wo iswa kha :state.',
        'edd_recorded' => 'Tshithu tsha ṱhoḓisiso yo khwaṱhiswaho tsho ṅwalwa.',
        'blacklisted' => 'Zwo engedzwa kha mutevhe mutsu. U dzhenisa zwino hu ḓo sevha nga sia ḽino, nga vhathu vhaḽo, na nga tshithu tshiṅwe na tshiṅwe tshine ḽi bvelela kha vhuṋe hatsho.',
        'blacklist_lifted' => 'U dzheniswa kha mutevhe mutsu ho bviswa. Ḓivhazwakale i dzula kha rekhodo.',
        'roles_saved' => 'Mishumo ya thendelo yo vhulungwa.',
        'errors' => [
            'waiver_reason' => 'Ambani uri ndi ngani tshithu hetshi tshi litshwa. Phakedzhe ya ṱhoḓisiso yo khwaṱhiswaho ine i nga litshwa nga u fhumula ndi lukhuṋi lwa mahala.',
            'blacklist_slot' => 'U dzhenisa kha mutevhe mutsu ndi tsheo ya vhulanguli nṱha. Humbelani muthu o vhewaho kha tshikhala tsha Vhulanguli, kana ni ḓi vhee khatsho u thoma.',
        ],
    ],
    'reputation' => [
        'ran' => 'Zwisima zwa :sources zwo ṱoḓiwa. Mawanwa matswa a :new; a :review a songo kona u ṱumanywa na muṋetshedzi uyu nahone a ṱoḓa ṱhoḓisiso.',
        'no_sources' => 'A hu na tshisima tsha mafhungo kana tsha vhushaka tsho vhewaho, ngauralo a hu na tshithu tsho ṱoḓiwaho. Muṋetshedzi ane a hu na muthu ane a nga mu ṱoḓa a songo tea u ṱanganyiswa na ane a hu na tshithu tsho wanalaho ngae — vheani tshisima nga fhasi ha Configure → Data sources.',
        'adjudicated' => 'Tsheo yo ṅwalwa. Muwanwa uyu a u nga si imiswe hafhu nga u shuma ha tshifhinga tshi ḓaho.',
        'handle_added' => 'Zwo engedzwa kha zwine zwi sedzwa kha muṋetshedzi uyu.',
        'handle_removed' => 'Zwo bviswa kha zwine zwi sedzwa.',
        'errors' => [
            'reason_required' => 'Ambani uri ndi ngani muwanwa uyu u bviswa. U hana hu si na ṱhalutshedzo a hu nga si fhambanywe na u puṱedza nga vhukhakhi nga murahu ha ṅwaha.',
        ],
    ],
    'ownership' => [
        'added' => 'Tshipiḓa tsha vhuṋe tsho ṅwalwa.',
        'updated' => 'Tshipiḓa tsha vhuṋe tsho vusuluswa.',
        'errors' => [
            'self_ownership' => 'Khamphani a i nga si fare tshipiḓa khayo nga yoṱhe. Khethani muṋe muṅwe, kana khamphani iṅwe ine i nga farwa.',
            'holder_required' => 'Bulani muṋe, kana khethani muthu o no vha kha faela.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check yo ṅwalwa sa :status.',
        'pin_saved' => 'TCS PIN yo vhulungwa. Tshiimo tsha u tevhedza muthelo zwino tshi nga khwaṱhisedzwa kha muṋetshedzi uyu.',
        'errors' => [
            'no_source' => 'A hu na tshisima tsho vhewaho kha :check, ngauralo a i nga si itwe nga u ḓiita. Ṅwalani ṱhoḓisiso nga inwi vhaṋe, kana vheani tshisima nga fhasi ha Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Yo wana :score% kha mukano wa ṱhoḓisiso wa :threshold%. Sedzani uyu muṋei ni sa athu ṋea muṅwe mushumo.',
        'flash' => [
            'template_created' => 'Khadi Ḿa mbalo Ḿo sikwa. Engedzani mbudziso dzine Ḿa tea u dzi vhudzisa.',
            'template_saved' => 'Khadi Ḿo vhulungwa.',
            'template_removed' => 'Khadi Ḿo bviswa.',
            'template_in_use' => 'Heli khadi Ḿo no shumiswa kha u tola, ngauralo a Ḿi nga phumulwi — vhekani vhuimo haḾo sa Ḿo bviswaho.',
            'question_added' => 'Mbudziso yo engedzwa.',
            'question_saved' => 'Mbudziso yo vhulungwa.',
            'question_removed' => 'Mbudziso yo bviswa.',
            'weight_over' => 'Vhulemu kha heli khadi vhu nga vha 100% hothe. Ho sala :remaining% i tshi tea u kovhelwa.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Uyu vhukwamani ha portal ho fhela. Humbelani muvhudzisani waṋu vhuswa.',
    ],
    'budget' => [
        'po_note' => 'Odara ya u renga :po',
        'invoice_note' => 'Invoyisi ya muṋei :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier yo fhedza u ṅwalisa hayo sa muṋei',
        'mail_body' => ':supplier yo ḓadza u ṅwalisa hayo nahone i khou lindela u sedzuluswa.',
        'mail_action' => 'Sedzani muṋei',
        'submitted' => 'Ri a livhuwa — zwidodombedzwa zwaṋu zwi kha :company u itela ṱhoḓisiso.',
        'link_expired' => 'Uyu vhukwamani ha u ṅwalisa ho fhela. Humbelani muvhudzisani waṋu ndaela ntswa.',
        'already_registered' => 'Heyi ndaela yo no shumiswa u ṅwalisa.',
    ],
];
