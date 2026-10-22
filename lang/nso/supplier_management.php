<?php

/**
 * Dithapo tša Sesotho sa Leboa tša Taolo ya Bafani.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'E emetše',
        1 => 'E a šoma',
        2 => 'E gannwe',
        3 => 'KYC e emetše',
    ],
    'suppliers' => [
        'activated' => 'Go bulwa ga mofani go phethilwe.',
        'refused' => 'Mofani o gannwe, gomme o botšwa lebaka.',
        'info_requested' => 'Kgopelo ya tshedimošo e rometšwe go mofani.',
        'portal_link_rotated' => 'Kgokagano e mpsha ya portal e ntšhitšwe. Kgokagano ya pele ga e sa šoma.',
        'portal_link_reissued' => 'Kgokagano ya gago ya portal e fetotšwe ka baka la tšhireletšo. Šomiša kgokagano e mpsha ka fase.',
        'outstanding_documents' => 'Re sa nyaka tše di latelago go wena: :documents',
        'errors' => [
            'duplicate' => 'Nomoro ye ya ngwadišo goba imeile e šetše e le ya :supplier (:number). Bula rekhoto yeo go e na le go hlama ya bobedi.',
            'missing_documents' => 'Mofani yo a ka se bulwe go fihlela ditokumente tše tša kgapeletšo di le gona e bile di sa šoma: :documents',
            'blacklisted' => 'Lehlakore le le ka lenaneong le lesoswana — :matched. Lebaka leo le ngwadilwego: :reason. Ge e ba le ke lehlakore le fapanego, goba thibelo e swanetše go tlošwa, seo ke phetho ya bolaodi pele mofani a ka oketšwa.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Taletšo e rometšwe. Kgokagano ya pele, ge e le gona, ga e sa šoma.',
        'mail_subject' => 'O laleditšwe go ingwadiša bjalo ka mofani go :company',
        'mail_greeting' => 'Thobela :name,',
        'mail_intro' => ':company e rata go go tsenya lenaneong la yona la bafani bao ba amogetšwego. Go ingwadiša go tšea metsotso e mmalwa.',
        'mail_action' => 'Thoma ngwadišo',
        'mail_expiry' => 'Kgokagano ye e šoma go fihla :date. Ge e fela, kgopela mokgokaganyi wa gago a romele e mpsha.',
        'mail_closing' => 'Go ba le ditokumente tša ka godimo pele o thoma go tla akgofiša se.',
    ],
    'decisions' => [
        'greeting' => 'Thobela :name,',
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
            'mail_subject' => 'Kgokagano ya gago e mpsha ya portal ya :company',
            'mail_body' => 'Kgokagano ya gago ya portal e fetotšwe ka baka la tšhireletšo. Kgokagano ya pele ga e sa šoma — šomiša konopo ya ka fase go tloga bjale.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC e amogetšwe; mofani bjale o a šoma.',
        'rejected' => 'KYC e gannwe, gomme mofani o botšwa lebaka.',
        'mail_subject' => 'Tlhahlobo ya KYC e a nyakega: :supplier',
        'mail_greeting' => 'Thobela,',
        'mail_body' => ':supplier e emetše sephetho sa KYC.',
        'mail_action' => 'Hlahloba mothaladi wa KYC',
        'errors' => [
            'already_decided' => 'Tsenyo ye e be e šetše e le :status. Bula mofani gape ge sephetho se nyaka go lekolwa.',
            'self_review' => 'Ke wena o hlamilego mofani yo, ka fao o ka se dumelele KYC ya gagwe. E nyaka mohlahlobi wa bobedi.',
        ],
    ],
    'rfx' => [
        'published' => 'Tiragalo e phatlalatšitšwe. Bafani bao ba laleditšwego bjale ba ka fa dibid.',
        'closed' => 'Tiragalo e tswaletšwe. Ga go dibid tše dingwe tšeo di tla amogelwago.',
        'response_recorded' => 'Bid e begilwe.',
        'evaluated' => 'Tekolo ya AI ya dibid e phethilwe.',
        'awarded' => 'Tiragalo e filwe.',
        'statuses' => [
            'draft' => 'kakanyo',
            'published' => 'e phatlaladitšwe',
            'closed' => 'e tswaletšwe',
            'awarded' => 'e filwe',
            'cancelled' => 'e khanseditšwe',
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
        'created' => 'Oda ya theko e hlamilwe.',
        'updated' => 'Oda ya theko e mpshafaditšwe.',
        'removed' => 'Oda ya theko e tlošitšwe, gomme matlotlo ao e bego e a swere a lokolotšwe.',
        'statuses' => [
            'draft' => 'kakanyo',
            'approved' => 'e amogetšwe',
            'received' => 'e amogetšwe',
            'closed' => 'e tswaletšwe',
            'cancelled' => 'e khanseditšwe',
        ],
        'errors' => [
            'supplier_not_active' => 'Mofani yo ke :status gomme a ka se amogele di-oda tše mpsha tša theko go fihlela a bulwa gape.',
            'expired_documents' => 'Mofani yo o na le ditokumente tša kgapeletšo tšeo di fetilwego ke nako gomme a ka se amogele di-oda tše mpsha: :documents',
            'invalid_transition' => 'Oda ya :from e ka se šuthišwe go :to.',
            'self_approval' => 'Ke wena o hlamilego oda ye, ka fao o ka se e amogele. E nyaka moamogedi wa bobedi.',
        ],
    ],
    'invoices' => [
        'captured' => 'Invoyisi e tsenyeditšwe.',
        'captured_with_variance' => 'Invoyisi e tsenyeditšwe, eupša e feta oda ya theko :po ka :variance. E swerwe bakeng sa tlhahlobo gomme e ka se lefelwe go fihlela seo se rarollwa.',
        'updated' => 'Invoyisi e mpshafaditšwe.',
        'removed' => 'Invoyisi e tlošitšwe, gomme tshenyegelo e bušeditšwe tekanyetšong.',
        'errors' => [
            'duplicate_number' => 'Invoyisi :number e šetše e tsenyeditšwe mofani yo. Bula yeo e lego gona go e na le go e tsenya gabedi.',
            'order_belongs_to_other_supplier' => 'Oda ya theko :po e hlametšwe mofani yo mongwe, ka fao invoyisi ye e ka se sepelelane le yona.',
            'pay_with_open_variance' => 'Invoyisi ye e feta oda ya yona ya theko gomme e swerwe bakeng sa tlhahlobo. Rarolla phapano pele o e swaya bjalo ka e lefetšwego.',
            'pay_with_unverified_banking' => 'Tefo e emišitšwe ka lebaka la dintlha tša panka tša mofani yo. :reason Netefatša akhaonto le mofani pele o lefa.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence ga e be karolo ya ngwadišo ye. E oketša netefatšo ya rejistara, lekgetho, panka, bong, mokitlano le dikotlo go taolo ya bafani — bolela le Mkhaya go e oketša.',
    ],
    'verdict' => [
        'never_assessed' => 'Dinyakišišo tšeo di swanetšego ga di sa dirwa go mofani yo.',
        'cleared' => 'Dinyakišišo tšeo di swanetšego di fetile — kotsi :score/100, dikgoro ka moka di hlwekile.',
        'blocked' => 'Dinyakišišo tšeo di swanetšego ga di phethagale — kotsi :score/100, dilo tše :count tšeo di šetšego.',
        'sanctions' => 'Tshwanelo ya dikotlo yeo e phelago e ngwadilwe go mofani yo.',
    ],
    'risk' => [
        'assessed' => 'E hlahlobilwe: :score go 100 (:band).',
        'tiered' => 'Mošomo wo o mogatong :tier — :label. Ditlhahlobo tšeo o di nyakago di tsošološitšwe.',
        'screened' => 'Batho ba :subjects ba hlahlobilwe. Ditshwanelo tše mpsha tše :new di nyaka phetho.',
        'screening_unavailable' => 'Ga go mothopo wa tlhahlobo wo o beilwego, ka fao ga go selo seo se hlahlobilwego. Ngwala seo o se humanago ka manaaneng ao a phatlaladitšwego, goba bea mothopo ka fase ga Configure → Data sources.',
        'hit_recorded' => 'Tshwanelo e ngwadilwe. E balwa kgahlanong le mofani yo go fihlela motho a dira phetho.',
        'adjudicated' => 'Phetho e ngwadilwe. E ka se tsošwe gape ke tlhahlobo ye e latelago.',
        'credit_recorded' => 'Pego ya mokitlano e swarilwe.',
        'errors' => [
            'reason_required' => 'Bolela gore ke ka lebaka la eng tshwanelo ye e tlošwa. Thibelo yeo e se nago tlhalošo e ka se fapanywe le go kgotla phošo ka morago ga ngwaga.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Manaaneo a dipotšišo a :n a rometšwe go mofani.',
        'recorded' => 'Dikarabo di bolokilwe — :completeness% e phethagetše.',
        'reviewed' => 'Lenaneo la dipotšišo le šupilwe bjalo ka leo le hlahlobilwego.',
    ],
    'alerts' => [
        'subject' => '[:severity] Temošo ya mofani — :supplier',
        'greeting' => 'Dumela,',
        'context' => ':supplier ga bjale o ka sehlopheng :band.',
        'action' => 'Bula pasepoto ya mofani',
        'db_title' => 'Temošo ya mofani: :supplier',
        'routing_saved' => 'Tshepedišo ya ditemošo e bolokilwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Phetho e ngwadilwe. Phetogo ye e ka se tšwelele gape ka lepokising leo le tsenago.',
        'swept' => 'Ditlhahlobo tše :checks di dirilwe gape; diphetogo tše :changes di humanwe.',
        'no_sources' => 'Ga go mothopo wo o beilwego bakeng sa ditlhahlobo tšeo di bego di swanetše, ka fao ga go selo seo se dirilwego gape. Dikgoba di ngwalwa kgahlanong le mofani go e na le go utwa.',
        'errors' => [
            'reason_required' => 'Bolela gore ke ka lebaka la eng phetogo ye e lahlwa. Ka morago ga ngwaga, thibelo yeo e se nago tlhalošo e ka se fapanywe le go kgotla phošo.',
        ],
    ],
    'cases' => [
        'opened' => 'Molato :reference o bulwe.',
        'moved' => 'Molato o išitšwe go :state.',
        'edd_recorded' => 'Selo sa dinyakišišo tšeo di matlafaditšwego se ngwadilwe.',
        'blacklisted' => 'E oketšwe ka lenaneong le lesoswana. Tsenyo e tla temoša ka lehlakore le, ka batho ba lona, le ka selo se sengwe le se sengwe seo le tšwelelago ka bong bja sona.',
        'blacklist_lifted' => 'Tsenyo ka lenaneong le lesoswana e tlošitšwe. Histori e dula ka rekotong.',
        'roles_saved' => 'Dikarolo tša tumelelo di bolokilwe.',
        'errors' => [
            'waiver_reason' => 'Bolela gore ke ka lebaka la eng selo se se lesišwa. Sephuthelwana sa dinyakišišo tšeo di matlafaditšwego seo se ka lesišwago ka setu ke mokgabišo.',
            'blacklist_slot' => 'Go tsenya ka lenaneong le lesoswana ke phetho ya bolaodi. Kgopela motho yo a beilwego ka sekgobeng sa Bolaodi, goba o ipee ka sona pele.',
        ],
    ],
    'reputation' => [
        'ran' => 'Methopo ye :sources e nyakilwe. Dikutollo tše mpsha tše :new; tše :review ga di kgonne go kgokagangwa le mofani yo gomme di nyaka tlhahlobo.',
        'no_sources' => 'Ga go mothopo wa ditaba goba wa leago wo o beilwego, ka fao ga go selo seo se nyakilwego. Mofani yo go se nago motho yo a ka mo nyakago ga a swanela go hlakantšhwa le yo go se nago selo seo se humanwego ka yena — bea mothopo ka fase ga Configure → Data sources.',
        'adjudicated' => 'Phetho e ngwadilwe. Kutollo ye e ka se tsošwe gape ke tshepedišo ye e latelago.',
        'handle_added' => 'E oketšwe go seo se lebelelwago bakeng sa mofani yo.',
        'handle_removed' => 'E tlošitšwe go seo se lebelelwago.',
        'errors' => [
            'reason_required' => 'Bolela gore ke ka lebaka la eng kutollo ye e tlošwa. Thibelo yeo e se nago tlhalošo e ka se fapanywe le go kgotla phošo ka morago ga ngwaga.',
        ],
    ],
    'ownership' => [
        'added' => 'Karolo ya bong e ngwadilwe.',
        'updated' => 'Karolo ya bong e tsošološitšwe.',
        'errors' => [
            'self_ownership' => 'Khamphani e ka se be le karolo ka yona ka boyona. Kgetha mong yo mongwe, goba khamphani ye nngwe yeo e swerwego.',
            'holder_required' => 'Bolela mong, goba kgetha motho yo a šetše a lego ka faeleng.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check e ngwadilwe bjalo ka :status.',
        'pin_saved' => 'TCS PIN e bolokilwe. Seemo sa go latela lekgetho ga bjale se ka netefatšwa bakeng sa mofani yo.',
        'errors' => [
            'no_source' => 'Ga go mothopo wo o beilwego bakeng sa :check, ka fao e ka se dirwe ka boithaupo. Ngwala tlhahlobo ka bowena, goba bea mothopo ka fase ga Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'E hweditše :score% kgahlanong le mollwane wa tlhahlobo wa :threshold%. Hlahloba mofani yo pele o fa mošomo o mongwe.',
        'flash' => [
            'template_created' => 'Karata ya dintlha e hlamilwe. Tsenya dipotšišo tseo e swanetšego go di botšiša.',
            'template_saved' => 'Karata e bolokilwe.',
            'template_removed' => 'Karata e tlošitswe.',
            'template_in_use' => 'Karata ye e šetse e šomišitswe tlhahlobong, ka fao e ka se phumulwe — bea seemo sa yona e le ye e tlošitswego.',
            'question_added' => 'Potšišo e tsentswe.',
            'question_saved' => 'Potšišo e bolokilwe.',
            'question_removed' => 'Potšišo e tlošitswe.',
            'weight_over' => 'Boima karateng ye bo ka ba 100% ka moka. Go šetse :remaining% go abja.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Kgokagano ye ya portal e fetilwe ke nako. Kgopela mokgokaganyi wa gago e mpsha.',
    ],
    'budget' => [
        'po_note' => 'Oda ya theko :po',
        'invoice_note' => 'Invoyisi ya mofani :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier e phethile ngwadišo ya yona ya mofani',
        'mail_body' => ':supplier e tladitše ngwadišo ya yona gomme e emetše go hlahlobja.',
        'mail_action' => 'Hlahloba mofani',
        'submitted' => 'Re a leboga — dintlha tša gago di go :company gore di hlahlobjwe.',
        'link_expired' => 'Kgokagano ye ya ngwadišo e fetilwe ke nako. Kgopela mokgokaganyi wa gago taletšo e mpsha.',
        'already_registered' => 'Taletšo ye e šetše e šomišitšwe go ingwadiša.',
    ],
];
