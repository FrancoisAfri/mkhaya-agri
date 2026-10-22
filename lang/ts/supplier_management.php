<?php

/**
 * Marito ya Xitsonga ya Vulawuri bya Vaphakeri.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'Swi rindzele',
        1 => 'Swa tirha',
        2 => 'Swi aleriwile',
        3 => 'KYC yi rindzele',
    ],
    'suppliers' => [
        'activated' => 'Ku pfuriwa ka muphakeri ku hetiwile.',
        'refused' => 'Muphakeri u aleriwile, naswona u byeriwile leswaku hikwalaho ka yini.',
        'info_requested' => 'Xikombelo xa vuxokoxoko xi rhumeriwile eka muphakeri.',
        'portal_link_rotated' => 'Vhukwamani vhuswa ha portal ho bveledzwa. Vhukwamani ha u thoma a vhu tsha shuma.',
        'portal_link_reissued' => 'Vhukwamani haṋu ha portal ho shandukiswa u itela tsireledzo. Shumisani vhukwamani vhuswa nga fhasi.',
        'outstanding_documents' => 'Ha ha lava leswi landzelaka eka wena: :documents',
        'errors' => [
            'duplicate' => 'Nomboro ya u ṅwalisa kana imeili hezwi zwo no vha zwa :supplier (:number). Vulani rekhodo yeneyo nṱhani ha u sika ya vhuvhili.',
            'missing_documents' => 'Uyu muṋei a nga si vulwe u swikela hezwi zwiṅwalwa zwa ndeme zwi hone nahone zwi tshi shuma: :documents',
            'blacklisted' => 'Tlhelo leri ri eka nxaxamelo wa ntima — :matched. Xivangelo lexi tsariweke: :reason. Loko leri ri ri tlhelo rin\'wana, kumbe ku siveriwa ku fanele ku susiwa, sweswo i xiboho xa vafambisi va le henhla ku nga si engetelerisiwa muphakeri.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Ndaela yo rumelwa. Vhukwamani ha u thoma, arali hu hone, a vhu tsha shuma.',
        'mail_subject' => 'No rambiwa u ṅwalisa sa muṋei kha :company',
        'mail_greeting' => 'Avuxeni :name,',
        'mail_intro' => ':company i ṱoḓa u ni engedza kha mutevhe wayo wa vhaṋei vho tendelwaho. U ṅwalisa zwi dzhia miṱodzi mimalwa.',
        'mail_action' => 'Sungula ku tsarisa',
        'mail_expiry' => 'Uyu vhukwamani vhu shuma u swikela :date. Arali vhu fhela, humbelani muvhudzisani waṋu a ni rumele vhuswa.',
        'mail_closing' => 'U vha na zwiṅwalwa zwa nṱha ni sa athu thoma zwi ḓo ita uri hezwi zwi ṱavhanye.',
    ],
    'decisions' => [
        'greeting' => 'Avuxeni :name,',
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
            'mail_subject' => 'Vuhlanganisi bya wena lebyintshwa bya portal bya :company',
            'mail_body' => 'Vuhlanganisi bya wena bya portal byi cinciwile hikwalaho ka vuhlayiseki. Vuhlanganisi bya khale a byi ha tirhi — tirhisa nkinganyeto lowu nga hansi ku sukela sweswi.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC yo tendelwa; muṋei zwino u khou shuma.',
        'rejected' => 'KYC yo hanwa, nahone muṋei o vhudzwa uri ndi ngani.',
        'mail_subject' => 'Ṱhoḓisiso ya KYC i a ṱoḓea: :supplier',
        'mail_greeting' => 'Avuxeni,',
        'mail_body' => ':supplier i khou lindela phetho ya KYC.',
        'mail_action' => 'Sedzani mutevhe wa KYC',
        'errors' => [
            'already_decided' => 'Hezwi zwo rumelwaho zwo no vha :status. Vulani muṋei hafhu arali phetho i tshi ṱoḓa u sedzuluswa.',
            'self_review' => 'Ndi inwi no sikaho uyu muṋei, nga zwenezwo a ni nga tendeli KYC yawe. I ṱoḓa mulingi wa vhuvhili.',
        ],
    ],
    'rfx' => [
        'published' => 'Xiendlakalo xi kandziyisiwile. Vaphakeri lava rhambiweke sweswi va nga nyika tibhidi.',
        'closed' => 'Xiendlakalo xi pfariwile. A ku na tibhidi tin’wana leti nga ta amukeriwa.',
        'response_recorded' => 'Bhidi yi tsariwile.',
        'evaluated' => 'Ṱhoḓisiso ya AI ya ma-bid yo fhela.',
        'awarded' => 'Xiendlakalo xi nyikiwile.',
        'statuses' => [
            'draft' => 'xikombiso',
            'published' => 'xi kandziyisiwile',
            'closed' => 'xi pfariwile',
            'awarded' => 'xi nyikiwile',
            'cancelled' => 'xi khanseriwile',
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
        'created' => 'Odara ya ku xava yi endliwile.',
        'updated' => 'Odara ya ku xava yi antswisiwile.',
        'removed' => 'Odara ya u renga yo bviswa, nahone masheleni e ya vha i tshi a fara o vhofhololwa.',
        'statuses' => [
            'draft' => 'xikombiso',
            'approved' => 'yi amukeriwile',
            'received' => 'yi amukeriwile',
            'closed' => 'yi pfariwile',
            'cancelled' => 'yi khanseriwile',
        ],
        'errors' => [
            'supplier_not_active' => 'Uyu muṋei ndi :status nahone a nga si ṱanganedze dziodara ntswa dza u renga u swikela o vulwa hafhu.',
            'expired_documents' => 'Uyu muṋei u na zwiṅwalwa zwa ndeme zwo fhelaho nahone a nga si ṱanganedze dziodara ntswa: :documents',
            'invalid_transition' => 'Odara ya :from a i nga pfulusiwi u ya kha :to.',
            'self_approval' => 'Ndi inwi no itaho heyi odara, nga zwenezwo a ni nga i tendeli. I ṱoḓa mutendeli wa vhuvhili.',
        ],
    ],
    'invoices' => [
        'captured' => 'Invoyisi yi tsariwile.',
        'captured_with_variance' => 'Invoyisi yo dzhielwa, fhedzi i fhira odara ya u renga :po nga :variance. Yo farwa u itela ṱhoḓisiso nahone a i nga badelwi u swikela zwenezwo zwo tandululwa.',
        'updated' => 'Invoyisi yi antswisiwile.',
        'removed' => 'Invoyisi yo bviswa, nahone u shumisa masheleni ho vhuyedzedzwa kha mugaganyagwama.',
        'errors' => [
            'duplicate_number' => 'Invoyisi :number yo no dzhielwa kha uyu muṋei. Vulani i re hone nṱhani ha u i dzhenisa luvhili.',
            'order_belongs_to_other_supplier' => 'Odara ya u renga :po yo itelwa muṅwe muṋei, nga zwenezwo heyi invoyisi a i nga tshimbidzani nayo.',
            'pay_with_open_variance' => 'Heyi invoyisi i fhira odara yayo ya u renga nahone yo farwa u itela ṱhoḓisiso. Tandululani phambano ni sa athu i swaya sa yo badelwaho.',
            'pay_with_unverified_banking' => 'Muholo wu yimisiwile hikwalaho ka vuxokoxoko bya bangi bya muphakeri loyi. :reason Tiyisisa akhawunti na muphakeri u nga si hakela.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence a hi xiphemu xa xitsariwa lexi xa muholo. Yi engetela ku tiyisisa ka rhijistara, muxuva, bangi, vun\'wini, ntsengo wa sikoloto na swibohiso eka vulawuri bya vaphakeri — vulavula na Mkhaya ku yi engetela.',
    ],
    'verdict' => [
        'never_assessed' => 'A ku na nkambelo wa vutlhari lowu endliweke eka muphakeri loyi.',
        'cleared' => 'Nkambelo wa vutlhari wu humelerile — khombo :score/100, tinyangwa hinkwato ti basile.',
        'blocked' => 'Nkambelo wa vutlhari a wu heleli — khombo :score/100, swilo swa :count leswi rindzeleke.',
        'sanctions' => 'Ku fambelana na swibohiso leswi tirhaka ku tsariwile eka muphakeri loyi.',
    ],
    'risk' => [
        'assessed' => 'Ku kambelwile: :score eka 100 (:band).',
        'tiered' => 'Ntirho lowu wu eka rivanti :tier — :label. Nkambelo lowu wu lavekaka wu pfuxetiwile.',
        'screened' => 'Ku hlungiwile vanhu va :subjects. Ku fambelana kuntshwa ka :new ku lava xiboho.',
        'screening_unavailable' => 'A ku na xihlovo xa ku hlunga lexi vekiweke, hikwalaho a ku na nchumu lowu kambelweke. Tsala leswi u swi kumaka eka minxaxamelo leyi kandziyisiweke, kumbe veka xihlovo ehansi ka Configure → Data sources.',
        'hit_recorded' => 'Ku fambelana ku tsariwile. Ku hlayiwa ku lwisana na muphakeri loyi ku fikela munhu a endla xiboho.',
        'adjudicated' => 'Xiboho xi tsariwile. A xi nge tlakusiwi nakambe hi ku hlunga ka nkarhi lowu taka.',
        'credit_recorded' => 'Xiviko xa sikoloto xi khomiwile.',
        'errors' => [
            'reason_required' => 'Vula leswaku ha yini ku fambelana loku ku susiwa. Ku ala loku nga hlamuseriwiki a ku nge hambanyisiwi na ku tshova hi xihoxo endzhaku ka lembe.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Minxaxamelo ya swivutiso ya :n yi rhumeriwile eka muphakeri.',
        'recorded' => 'Tinhlamulo ti hlayisiwile — :completeness% ku herile.',
        'reviewed' => 'Nxaxamelo wa swivutiso wu funghiwile tanihi lowu kambelweke.',
    ],
    'alerts' => [
        'subject' => '[:severity] Xitsundzuxo xa muphakeri — :supplier',
        'greeting' => 'Avuxeni,',
        'context' => ':supplier sweswi u eka ntlawa :band.',
        'action' => 'Pfula phasiphoto ya muphakeri',
        'db_title' => 'Xitsundzuxo xa muphakeri: :supplier',
        'routing_saved' => 'Ku rhumela swa switsundzuxo swi hlayisiwile.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Xiboho xi tsariwile. Ku cinca loku a ku nge humeleli nakambe eka bokisi ra leswi nghenaka.',
        'swept' => 'Nkambelo wa :checks wu endliwile nakambe; ku cinca ka :changes ku kumiwile.',
        'no_sources' => 'A ku na xihlovo lexi vekiweke eka nkambelo wihi na wihi lowu a wu fanele, hikwalaho a ku na nchumu lowu endliweke nakambe. Swikala swi tsariwa ku lwisana na muphakeri ku nga ri ku fihliwa.',
        'errors' => [
            'reason_required' => 'Vula leswaku ha yini ku cinca loku ku cukumetiwa. Endzhaku ka lembe, ku ala loku nga hlamuseriwiki a ku nge hambanyisiwi na ku tshova hi xihoxo.',
        ],
    ],
    'cases' => [
        'opened' => 'Docket :reference ri pfuriwile.',
        'moved' => 'Docket ri yisiwile eka :state.',
        'edd_recorded' => 'Nchumu wa nkambelo wo engetelerisiwa wu tsariwile.',
        'blacklisted' => 'Swi engeteleriwile eka nxaxamelo wa ntima. Ku nghenisa sweswi swi ta tsundzuxa hi tlhelo leri, hi vanhu va rona, na hi nchumu wun\'wana ni wun\'wana lowu ri humelelaka eka vun\'wini bya wona.',
        'blacklist_lifted' => 'Ku nghenisiwa eka nxaxamelo wa ntima ku susiwile. Matimu ma tshama eka rhekhodo.',
        'roles_saved' => 'Mintirho ya mpfumelelo yi hlayisiwile.',
        'errors' => [
            'waiver_reason' => 'Vula leswaku ha yini nchumu lowu wu tshikiwa. Phakede ra nkambelo wo engetelerisiwa leri nga tshikiwa hi ku miyela i xisirhelelo xa mahala.',
            'blacklist_slot' => 'Ku nghenisa eka nxaxamelo wa ntima i xiboho xa vafambisi va le henhla. Kombela munhu loyi a vekiweke eka xivandla xa Vafambisi, kumbe tiveke eka xona ku sungula.',
        ],
    ],
    'reputation' => [
        'ran' => 'Swihlovo swa :sources swi lavisisiwile. Leswi kumiweke leswintshwa swa :new; swa :review a swi koti ku fambisiwa na muphakeri loyi naswona swi lava nkambelo.',
        'no_sources' => 'A ku na xihlovo xa mahungu kumbe xa tinhlangano lexi vekiweke, hikwalaho a ku na nchumu lowu lavisisiweke. Muphakeri loyi a nga ri na munhu loyi a nga n\'wi lavisisa a nga fanelanga ku pfilunganyiwa na loyi a ku nga riki na nchumu lowu kumiweke ha yena — veka xihlovo ehansi ka Configure → Data sources.',
        'adjudicated' => 'Xiboho xi tsariwile. Leswi kumiweke a swi nge tlakusiwi nakambe hi ku tirha ka nkarhi lowu taka.',
        'handle_added' => 'Swi engeteleriwile eka leswi languteriwaka eka muphakeri loyi.',
        'handle_removed' => 'Swi susiwile eka leswi languteriwaka.',
        'errors' => [
            'reason_required' => 'Vula leswaku ha yini leswi kumiweke swi susiwa. Ku ala loku nga hlamuseriwiki a ku nge hambanyisiwi na ku tshova hi xihoxo endzhaku ka lembe.',
        ],
    ],
    'ownership' => [
        'added' => 'Xiave xa vun\'wini xi tsariwile.',
        'updated' => 'Xiave xa vun\'wini xi pfuxetiwile.',
        'errors' => [
            'self_ownership' => 'Khampani a yi nge khomi xiave eka yona hi yoxe. Hlawula n\'wini wun\'wana, kumbe khampani yin\'wana leyi yi nga khoma.',
            'holder_required' => 'Vula n\'wini, kumbe hlawula munhu loyi se a ri eka fayili.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check yi tsariwile tanihi :status.',
        'pin_saved' => 'TCS PIN yi hlayisiwile. Xiyimo xa ku landzela milawu ya muxuva sweswi xi nga tiyisisiwa eka muphakeri loyi.',
        'errors' => [
            'no_source' => 'A ku na xihlovo lexi vekiweke eka :check, hikwalaho a yi nge endliwi hi ku tihlanganisa. Tsala nkambelo hi woxe, kumbe veka xihlovo ehansi ka Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Yo wana :score% kha mukano wa ṱhoḓisiso wa :threshold%. Sedzani uyu muṋei ni sa athu ṋea muṅwe mushumo.',
        'flash' => [
            'template_created' => 'Khadi ra tinhlayo ri tumbuluxiwile. Engetela swivutiso leswi ri faneleke ku swi vutisa.',
            'template_saved' => 'Khadi ri hlayisiwile.',
            'template_removed' => 'Khadi ri susiwile.',
            'template_in_use' => 'Khadi leri ri tirhisiwile eka vukambisisi, hikwalaho a ri nge susiwi — veka xiyimo xa rona ri va leri susiweke.',
            'question_added' => 'Xivutiso xi engeteriwile.',
            'question_saved' => 'Xivutiso xi hlayisiwile.',
            'question_removed' => 'Xivutiso xi susiwile.',
            'weight_over' => 'Mintikelo eka khadi leri yi nga va 100% hinkwayo. Ku sele :remaining% leswi faneleke ku averiwa.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Vuhlanganisi lebyi bya portal byi herile nkarhi. Kombela mutirhisani wa wena lebyintshwa.',
    ],
    'budget' => [
        'po_note' => 'Odara ya ku xava :po',
        'invoice_note' => 'Invoyisi ya muphakeri :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier yi hetile ku tsarisa ka yona tanihi muphakeri',
        'mail_body' => ':supplier yi tatile ku tsarisa ka yona naswona yi rindzele ku kambisisiwa.',
        'mail_action' => 'Kambisisa muphakeri',
        'submitted' => 'Ha khensa — vuxokoxoko bya wena byi le ka :company leswaku byi kambisisiwa.',
        'link_expired' => 'Vuhlanganisi lebyi bya ku tsarisa byi herile nkarhi. Kombela mutirhisani wa wena xirhambo lexintshwa.',
        'already_registered' => 'Xirhambo lexi xi tirhisiwile ku tsarisa.',
    ],
];
