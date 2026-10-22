<?php

/**
 * Dithapo tsa Setswana tsa Taolo ya Barekisi.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'E letile',
        1 => 'E a bereka',
        2 => 'E gannwe',
        3 => 'KYC e letile',
    ],
    'suppliers' => [
        'activated' => 'Go bulwa ga morekisi go weditswe.',
        'refused' => 'Morekisi o gannwe, mme o bolelelwa lebaka.',
        'info_requested' => 'Kopo ya tshedimosetso e rometswe kwa morekising.',
        'portal_link_rotated' => 'Kgolagano e ntšhwa ya portal e ntshitswe. Kgolagano ya pele ga e sa bereke.',
        'portal_link_reissued' => 'Kgokagano ya gago ya portal e fetotšwe ka baka la tšhireletšo. Šomiša kgokagano e mpsha ka fase.',
        'outstanding_documents' => 'Re santse re tlhoka tse di latelang go wena: :documents',
        'errors' => [
            'duplicate' => 'Nomoro ye ya ngwadišo goba imeile e šetše e le ya :supplier (:number). Bula rekhoto yeo go e na le go hlama ya bobedi.',
            'missing_documents' => 'Mofani yo a ka se bulwe go fihlela ditokumente tše tša kgapeletšo di le gona e bile di sa šoma: :documents',
            'blacklisted' => 'Letlhakore leno le mo lenaneng le lentsho — :matched. Lebaka le le kwadilweng: :reason. Fa leno ke letlhakore le sele, kgotsa thibelo e tshwanetse go tloswa, seo ke tshwetso ya botsamaisi pele morekisi a ka okediwa.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Taletšo e rometšwe. Kgokagano ya pele, ge e le gona, ga e sa šoma.',
        'mail_subject' => 'O laleditšwe go ingwadiša bjalo ka mofani go :company',
        'mail_greeting' => 'Dumela :name,',
        'mail_intro' => ':company e rata go go tsenya lenaneong la yona la bafani bao ba amogetšwego. Go ingwadiša go tšea metsotso e mmalwa.',
        'mail_action' => 'Simolola kwadiso',
        'mail_expiry' => 'Kgokagano ye e šoma go fihla :date. Ge e fela, kgopela mokgokaganyi wa gago a romele e mpsha.',
        'mail_closing' => 'Go ba le ditokumente tša ka godimo pele o thoma go tla akgofiša se.',
    ],
    'decisions' => [
        'greeting' => 'Dumela :name,',
        'comment_label' => 'Dintlha tša mohlahlobi:',
        'mail_action' => 'Bula portal ya gago ya mofani',
        'mail_closing' => 'Araba imeile ye ge go na le seo se bonagalago se fošagetše.',
        'approved' => [
            'mail_subject' => 'Bjale o mofani yo a amogetšwego go :company',
            'mail_body' => 'Ngwadišo ya gago e amogetšwe. :company bjale e ka dira di-oda go wena.',
        ],
        'refused' => [
            'mail_subject' => 'Ngwadišo ya gago ya mofani go :company',
            'mail_body' => ':company ga e kgone go tšwela pele le ngwadišo ya gago mo nakong ye.',
        ],
        'information_requested' => [
            'mail_subject' => ':company e nyaka tshedimošo ye nngwe go wena',
            'mail_body' => 'Ngwadišo ya gago e emišitšwe go fihlela re hwetša tše dingwe go wena.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Kgolagano ya gago e ntšhwa ya portal ya :company',
            'mail_body' => 'Kgolagano ya gago ya portal e fetotswe ka ntlha ya tshireletso. Kgolagano ya pele ga e sa bereke — dirisa konopo e e fa tlase go tloga jaanong.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC e amogetšwe; mofani bjale o a šoma.',
        'rejected' => 'KYC e gannwe, gomme mofani o botšwa lebaka.',
        'mail_subject' => 'Tlhahlobo ya KYC e a nyakega: :supplier',
        'mail_greeting' => 'Dumela,',
        'mail_body' => ':supplier e emetše sephetho sa KYC.',
        'mail_action' => 'Hlahloba mothaladi wa KYC',
        'errors' => [
            'already_decided' => 'Tsenyo ye e be e šetše e le :status. Bula mofani gape ge sephetho se nyaka go lekolwa.',
            'self_review' => 'Ke wena o hlamilego mofani yo, ka fao o ka se dumelele KYC ya gagwe. E nyaka mohlahlobi wa bobedi.',
        ],
    ],
    'rfx' => [
        'published' => 'Tiragalo e phasaladitswe. Barekisi ba ba laleditsweng ba ka naya ditshupo jaanong.',
        'closed' => 'Tiragalo e tswetswe. Ga go ditshupo tse dingwe tse di tla amogelwang.',
        'response_recorded' => 'Tshupo e kwadilwe.',
        'evaluated' => 'Tekolo ya AI ya dibid e phethilwe.',
        'awarded' => 'Tiragalo e neilwe.',
        'statuses' => [
            'draft' => 'kakanyo',
            'published' => 'e phasaladitswe',
            'closed' => 'e tswetswe',
            'awarded' => 'e neilwe',
            'cancelled' => 'e khanseditswe',
        ],
        'errors' => [
            'invalid_transition' => 'Tiragalo yeo e lego :from e ka se šuthišwe go :to.',
            'publish_without_invitations' => 'Laletša bonyane mofani o tee pele o phatlalatša — tiragalo yeo go se nago motho yo a kgopetšwego go fa bid go yona e ka se fiwe.',
            'publish_after_closing' => 'Letšatši la go tswalela le šetše le fetile. Le šuthiše pele o phatlalatša.',
            'bid_when_not_published' => 'Dibid di ka begwa fela ge tiragalo e phatlaladitšwe. Ye ke :status.',
            'bid_after_closing' => 'Tiragalo ye e tswaletšwe ka :date. Bid ya morago ga nako e ka se amogelwe.',
            'bid_from_uninvited' => ':supplier ga se a laleletšwa tiragalong ye. Ba laletše pele ge ba swanetše go tšea karolo.',
            'award_without_bid' => ':supplier ga se a fa bid tiragalong ye, ka fao e ka se fiwe go bona.',
            'award_reason_required' => 'Ye ga se bid ya tlase kudu, ka fao go fa go hloka lebaka leo le begilwego.',
        ],
    ],
    'orders' => [
        'created' => 'Oto ya theko e dirilwe.',
        'updated' => 'Oto ya theko e ntlafaditswe.',
        'removed' => 'Oda ya theko e tlošitšwe, gomme matlotlo ao e bego e a swere a lokolotšwe.',
        'statuses' => [
            'draft' => 'kakanyo',
            'approved' => 'e amogetswe',
            'received' => 'e amogetswe',
            'closed' => 'e tswetswe',
            'cancelled' => 'e khanseditswe',
        ],
        'errors' => [
            'supplier_not_active' => 'Mofani yo ke :status gomme a ka se amogele di-oda tše mpsha tša theko go fihlela a bulwa gape.',
            'expired_documents' => 'Mofani yo o na le ditokumente tša kgapeletšo tšeo di fetilwego ke nako gomme a ka se amogele di-oda tše mpsha: :documents',
            'invalid_transition' => 'Oda ya :from e ka se šuthišwe go :to.',
            'self_approval' => 'Ke wena o hlamilego oda ye, ka fao o ka se e amogele. E nyaka moamogedi wa bobedi.',
        ],
    ],
    'invoices' => [
        'captured' => 'Invoyisi e kwadilwe.',
        'captured_with_variance' => 'Invoyisi e tsenyeditšwe, eupša e feta oda ya theko :po ka :variance. E swerwe bakeng sa tlhahlobo gomme e ka se lefelwe go fihlela seo se rarollwa.',
        'updated' => 'Invoyisi e ntlafaditswe.',
        'removed' => 'Invoyisi e tlošitšwe, gomme tshenyegelo e bušeditšwe tekanyetšong.',
        'errors' => [
            'duplicate_number' => 'Invoyisi :number e šetše e tsenyeditšwe mofani yo. Bula yeo e lego gona go e na le go e tsenya gabedi.',
            'order_belongs_to_other_supplier' => 'Oda ya theko :po e hlametšwe mofani yo mongwe, ka fao invoyisi ye e ka se sepelelane le yona.',
            'pay_with_open_variance' => 'Invoyisi ye e feta oda ya yona ya theko gomme e swerwe bakeng sa tlhahlobo. Rarolla phapano pele o e swaya bjalo ka e lefetšwego.',
            'pay_with_unverified_banking' => 'Tuelo e emisitswe ka ntlha ya dintlha tsa banka tsa morekisi yono. :reason Netefatsa akhaonto le morekisi pele o duela.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence ga e se karolo ya pego eno ya dituelo. E oketsa netefatso ya rejisetara, lekgetho, banka, boeteledipele jwa boneng, mokitlano le dikotlhao mo taolong ya barekisi — bua le Mkhaya go e oketsa.',
    ],
    'verdict' => [
        'never_assessed' => 'Patlisiso e e tshwanetseng ga e ise e dirwe mo morekising yono.',
        'cleared' => 'Patlisiso e e tshwanetseng e fetile — kotsi :score/100, dikgoro tsotlhe di phepa.',
        'blocked' => 'Patlisiso e e tshwanetseng ga e feleletse — kotsi :score/100, dilo tse :count tse di setseng.',
        'sanctions' => 'Tshwanelo ya dikotlhao e e tshelang e kwadilwe mo morekising yono.',
    ],
    'risk' => [
        'assessed' => 'E tlhatlhobilwe: :score go 100 (:band).',
        'tiered' => 'Tiro eno e mo legatong :tier — :label. Ditlhatlhobo tse e di tlhokang di tsosolositswe.',
        'screened' => 'Batho ba :subjects ba tlhatlhobilwe. Ditshwanelo tse disha tse :new di tlhoka tshwetso.',
        'screening_unavailable' => 'Ga go motswedi wa tlhatlhobo o o beilweng, ka jalo ga go sepe se se tlhatlhobilweng. Kwala se o se bonang mo manaaneng a a phasaladitsweng, kgotsa baya motswedi ka fa tlase ga Configure → Data sources.',
        'hit_recorded' => 'Tshwanelo e kwadilwe. E balwa kgatlhanong le morekisi yono go fitlha mongwe a dira tshwetso.',
        'adjudicated' => 'Tshwetso e kwadilwe. Ga e tla tsosiwa gape ke tlhatlhobo e e latelang.',
        'credit_recorded' => 'Pego ya mokitlano e tshwailwe.',
        'errors' => [
            'reason_required' => 'Bolela gore goreng tshwanelo eno e tloswa. Thibelo e e se nang tlhaloso ga e ka ke ya farologanngwa le go tobetsa phoso morago ga ngwaga.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Manaane a dipotso a :n a rometswe mo morekising.',
        'recorded' => 'Dikarabo di bolokilwe — :completeness% e feleletse.',
        'reviewed' => 'Lenaane la dipotso le tshwailwe jaaka le tlhatlhobilwe.',
    ],
    'alerts' => [
        'subject' => '[:severity] Tlhagiso ya morekisi — :supplier',
        'greeting' => 'Dumela,',
        'context' => ':supplier gone jaanong o mo setlhopheng :band.',
        'action' => 'Bula pasepoto ya morekisi',
        'db_title' => 'Tlhagiso ya morekisi: :supplier',
        'routing_saved' => 'Tsamaiso ya ditlhagiso e bolokilwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Tshwetso e kwadilwe. Phetogo eno ga e tla tlhaga gape mo lebokoseng le le tsenang.',
        'swept' => 'Ditlhatlhobo tse :checks di dirilwe gape; diphetogo tse :changes di bonwe.',
        'no_sources' => 'Ga go motswedi o o beilweng bakeng sa ditlhatlhobo tse di tshwanetseng, ka jalo ga go sepe se se dirilweng gape. Diphatlha di kwadiwa kgatlhanong le morekisi go na le go fitlhwa.',
        'errors' => [
            'reason_required' => 'Bolela gore goreng phetogo eno e latlhwa. Morago ga ngwaga, thibelo e e se nang tlhaloso ga e ka ke ya farologanngwa le go tobetsa phoso.',
        ],
    ],
    'cases' => [
        'opened' => 'Molato :reference o bulwe.',
        'moved' => 'Molato o isitswe go :state.',
        'edd_recorded' => 'Selo sa patlisiso e e nonotshitsweng se kwadilwe.',
        'blacklisted' => 'Se oketswe mo lenaneng le lentsho. Tsenyo e tla tlhagisa ka letlhakore leno, ka batho ba lone, le ka sengwe le sengwe se le tlhagang mo boneng jwa sone.',
        'blacklist_lifted' => 'Tsenyo mo lenaneng le lentsho e tloswa. Hisitori e nna mo rekotong.',
        'roles_saved' => 'Diabe tsa tetlanyo di bolokilwe.',
        'errors' => [
            'waiver_reason' => 'Bolela gore goreng selo seno se lesiwa. Sephuthelwana sa patlisiso e e nonotshitsweng se se ka lesiwang ka tidimalo ke mokgabiso.',
            'blacklist_slot' => 'Go tsenya mo lenaneng le lentsho ke tshwetso ya botsamaisi. Kopa mongwe yo o beilweng mo sebakeng sa Botsamaisi, kgotsa o ipaye mo sone pele.',
        ],
    ],
    'reputation' => [
        'ran' => 'Metswedi e :sources e batlilwe. Diphitlhelelo tse disha tse :new; tse :review ga di a kgona go golaganngwa le morekisi yono mme di tlhoka tlhatlhobo.',
        'no_sources' => 'Ga go motswedi wa dikgang kgotsa wa seoposengwe o o beilweng, ka jalo ga go sepe se se batlilweng. Morekisi yo o se nang ope yo o ka mo batlang ga a a tshwanela go tlhakatlhakanngwa le yo go se nang sepe se se bonweng ka ene — baya motswedi ka fa tlase ga Configure → Data sources.',
        'adjudicated' => 'Tshwetso e kwadilwe. Phitlhelelo eno ga e tla tsosiwa gape ke tiro e e latelang.',
        'handle_added' => 'E oketswe mo se se lebelelwang bakeng sa morekisi yono.',
        'handle_removed' => 'E tloswa mo se se lebelelwang.',
        'errors' => [
            'reason_required' => 'Bolela gore goreng phitlhelelo eno e tloswa. Thibelo e e se nang tlhaloso ga e ka ke ya farologanngwa le go tobetsa phoso morago ga ngwaga.',
        ],
    ],
    'ownership' => [
        'added' => 'Karolo ya boneng e kwadilwe.',
        'updated' => 'Karolo ya boneng e tsosolositswe.',
        'errors' => [
            'self_ownership' => 'Khamphani ga e ka ke ya nna le karolo mo yone ka boyone. Tlhopha mong yo mongwe, kgotsa khamphani e sele e e tshwerweng.',
            'holder_required' => 'Bolela mong, kgotsa tlhopha mongwe yo o setse a mo faeleng.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check e kwadilwe jaaka :status.',
        'pin_saved' => 'TCS PIN e bolokilwe. Seemo sa go ikobela lekgetho gone jaanong se ka netefadiwa bakeng sa morekisi yono.',
        'errors' => [
            'no_source' => 'Ga go motswedi o o beilweng bakeng sa :check, ka jalo e ka se dirwe ka boithaopo. Kwala tlhatlhobo ka bowena, kgotsa baya motswedi ka fa tlase ga Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'E hweditše :score% kgahlanong le mollwane wa tlhahlobo wa :threshold%. Hlahloba mofani yo pele o fa mošomo o mongwe.',
        'flash' => [
            'template_created' => 'Karata ya dintlha e tlhamilwe. Tsenya dipotso tse e tshwanetseng go di botsa.',
            'template_saved' => 'Karata e bolokilwe.',
            'template_removed' => 'Karata e tlositswe.',
            'template_in_use' => 'Karata e e setse e dirisitswe mo tshekatshekong, ka jalo ga e kgone go phimolwa — baya seemo sa yona e le e e tlositsweng.',
            'question_added' => 'Potso e tsentswe.',
            'question_saved' => 'Potso e bolokilwe.',
            'question_removed' => 'Potso e tlositswe.',
            'weight_over' => 'Bokete mo karateng e bo ka nna 100% gotlhe. Go setse :remaining% go abiwa.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Kgolagano eno ya portal e fedile. Kopa mogolagani wa gago e ntšhwa.',
    ],
    'budget' => [
        'po_note' => 'Oto ya theko :po',
        'invoice_note' => 'Invoyisi ya morekisi :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier e feditse kwadiso ya yona ya morekisi',
        'mail_body' => ':supplier e tladitse kwadiso ya yona mme e letile go sekasekwa.',
        'mail_action' => 'Sekaseka morekisi',
        'submitted' => 'Re a leboga — dintlha tsa gago di kwa go :company gore di sekasekwe.',
        'link_expired' => 'Kgolagano eno ya kwadiso e fedile. Kopa mogolagani wa gago taletso e ntšhwa.',
        'already_registered' => 'Taletso eno e setse e dirisitswe go kwadisa.',
    ],
];
