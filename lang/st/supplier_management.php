<?php

/**
 * Likhoele tsa Sesotho tsa Tsamaiso ea Bafani.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'E emetse',
        1 => 'E a sebetsa',
        2 => 'E hanne',
        3 => 'KYC e emetse',
    ],
    'suppliers' => [
        'activated' => 'Ho bulwa ha mofani ho phethiloe.',
        'refused' => 'Mofani o hanne, mme o bolelloa lebaka.',
        'info_requested' => 'Kopo ea tlhahisoleseling e rometsoe ho mofani.',
        'portal_link_rotated' => 'Sehokelo se secha sa portal se fanoe. Sehokelo sa pele ha se sa sebetsa.',
        'portal_link_reissued' => 'Sehokelo sa hau sa portal se fetotsoe bakeng sa tšireletso. Sebelisa sehokelo se secha ka tlase.',
        'outstanding_documents' => 'Re ntse re hloka tse latelang ho uena: :documents',
        'errors' => [
            'duplicate' => 'Nomoro ye ya ngwadišo goba imeile e šetše e le ya :supplier (:number). Bula rekhoto yeo go e na le go hlama ya bobedi.',
            'missing_documents' => 'Mofani yo a ka se bulwe go fihlela ditokumente tše tša kgapeletšo di le gona e bile di sa šoma: :documents',
            'blacklisted' => 'Lehlakore lena le lenaneng le letsho — :matched. Lebaka le tlalehilweng: :reason. Haeba lena ke lehlakore le fapaneng, kapa thibelo e lokela ho tloswa, seo ke qeto ya boetapele pele mofani a ka ekeletswa.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Taletšo e rometšwe. Kgokagano ya pele, ge e le gona, ga e sa šoma.',
        'mail_subject' => 'O laleditšwe go ingwadiša bjalo ka mofani go :company',
        'mail_greeting' => 'Lumela :name,',
        'mail_intro' => ':company e rata go go tsenya lenaneong la yona la bafani bao ba amogetšwego. Go ingwadiša go tšea metsotso e mmalwa.',
        'mail_action' => 'Qala ngoliso',
        'mail_expiry' => 'Kgokagano ye e šoma go fihla :date. Ge e fela, kgopela mokgokaganyi wa gago a romele e mpsha.',
        'mail_closing' => 'Go ba le ditokumente tša ka godimo pele o thoma go tla akgofiša se.',
    ],
    'decisions' => [
        'greeting' => 'Lumela :name,',
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
            'mail_subject' => 'Sehokelo sa hau se secha sa portal sa :company',
            'mail_body' => 'Sehokelo sa hau sa portal se fetotsoe bakeng sa tšireletso. Sehokelo sa pele ha se sa sebetsa — sebelisa konopo e ka tlase ho tloha joale.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC e amogetšwe; mofani bjale o a šoma.',
        'rejected' => 'KYC e gannwe, gomme mofani o botšwa lebaka.',
        'mail_subject' => 'Tlhahlobo ya KYC e a nyakega: :supplier',
        'mail_greeting' => 'Lumela,',
        'mail_body' => ':supplier e emetše sephetho sa KYC.',
        'mail_action' => 'Hlahloba mothaladi wa KYC',
        'errors' => [
            'already_decided' => 'Tsenyo ye e be e šetše e le :status. Bula mofani gape ge sephetho se nyaka go lekolwa.',
            'self_review' => 'Ke wena o hlamilego mofani yo, ka fao o ka se dumelele KYC ya gagwe. E nyaka mohlahlobi wa bobedi.',
        ],
    ],
    'rfx' => [
        'published' => 'Ketsahalo e phatlalalitsoe. Bafani ba memiloeng ba ka fana ka ditlhahiso hona joale.',
        'closed' => 'Ketsahalo e koetsoe. Ha ho ditlhahiso tse ling tse tla amoheloa.',
        'response_recorded' => 'Tlhahiso e tlalehiloe.',
        'evaluated' => 'Tekolo ya AI ya dibid e phethilwe.',
        'awarded' => 'Ketsahalo e fanoe.',
        'statuses' => [
            'draft' => 'moralo',
            'published' => 'e phatlalalitsoe',
            'closed' => 'e koetsoe',
            'awarded' => 'e fanoe',
            'cancelled' => 'e hlakotsoe',
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
        'created' => 'Odara ea theko e entsoe.',
        'updated' => 'Odara ea theko e ntlafalitsoe.',
        'removed' => 'Oda ya theko e tlošitšwe, gomme matlotlo ao e bego e a swere a lokolotšwe.',
        'statuses' => [
            'draft' => 'moralo',
            'approved' => 'e amohetsoe',
            'received' => 'e amohetsoe',
            'closed' => 'e koetsoe',
            'cancelled' => 'e hlakotsoe',
        ],
        'errors' => [
            'supplier_not_active' => 'Mofani yo ke :status gomme a ka se amogele di-oda tše mpsha tša theko go fihlela a bulwa gape.',
            'expired_documents' => 'Mofani yo o na le ditokumente tša kgapeletšo tšeo di fetilwego ke nako gomme a ka se amogele di-oda tše mpsha: :documents',
            'invalid_transition' => 'Oda ya :from e ka se šuthišwe go :to.',
            'self_approval' => 'Ke wena o hlamilego oda ye, ka fao o ka se e amogele. E nyaka moamogedi wa bobedi.',
        ],
    ],
    'invoices' => [
        'captured' => 'Invoyisi e ngolisitsoe.',
        'captured_with_variance' => 'Invoyisi e tsenyeditšwe, eupša e feta oda ya theko :po ka :variance. E swerwe bakeng sa tlhahlobo gomme e ka se lefelwe go fihlela seo se rarollwa.',
        'updated' => 'Invoyisi e ntlafalitsoe.',
        'removed' => 'Invoyisi e tlošitšwe, gomme tshenyegelo e bušeditšwe tekanyetšong.',
        'errors' => [
            'duplicate_number' => 'Invoyisi :number e šetše e tsenyeditšwe mofani yo. Bula yeo e lego gona go e na le go e tsenya gabedi.',
            'order_belongs_to_other_supplier' => 'Oda ya theko :po e hlametšwe mofani yo mongwe, ka fao invoyisi ye e ka se sepelelane le yona.',
            'pay_with_open_variance' => 'Invoyisi ye e feta oda ya yona ya theko gomme e swerwe bakeng sa tlhahlobo. Rarolla phapano pele o e swaya bjalo ka e lefetšwego.',
            'pay_with_unverified_banking' => 'Tefo e thibetswe ka lebaka la dintlha tsa banka tsa mofani ona. :reason Netefatsa akhaonto le mofani pele o lefa.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Supplier Intelligence & Due Diligence ha e le karolo ya ngodiso ena. E eketsa netefatso ya rejistara, lekgetho, banka, bonong, mokitlane le dikotlo taolong ya bafani — bua le Mkhaya hore e eketswe.',
    ],
    'verdict' => [
        'never_assessed' => 'Patlisiso e lokelang ha e so etswe ho mofani ona.',
        'cleared' => 'Patlisiso e lokelang e fetile — kotsi :score/100, menyako yohle e hlwekile.',
        'blocked' => 'Patlisiso e lokelang ha e felle — kotsi :score/100, dintho tse :count tse salletseng.',
        'sanctions' => 'Tshwanelo ya dikotlo e phelang e tlalehilwe ho mofani ona.',
    ],
    'risk' => [
        'assessed' => 'E hlahlobilwe: :score ho 100 (:band).',
        'tiered' => 'Mosebetsi ona o boemong :tier — :label. Ditlhahlobo tseo o di hlokang di ntlafaditswe.',
        'screened' => 'Batho ba :subjects ba hlahlobilwe. Ditshwanelo tse ncha tse :new di hloka qeto.',
        'screening_unavailable' => 'Ha ho mohlodi wa tlhahlobo o behiloeng, ka hoo ha ho letho le hlahlobiloeng. Tlaleha seo o se fumanang manaaneng a phatlalalitsweng, kapa beha mohlodi tlasa Configure → Data sources.',
        'hit_recorded' => 'Tshwanelo e tlalehilwe. E balwa khahlanong le mofani ona ho fihlela motho a etsa qeto.',
        'adjudicated' => 'Qeto e tlalehilwe. E ke ke ya hlahiswa hape ke tlhahlobo e tlang.',
        'credit_recorded' => 'Tlaleho ya mokitlane e tshwailwe.',
        'errors' => [
            'reason_required' => 'Bolela hobaneng tshwanelo ena e tloswa. Thibelo e sa hlalosweng e ke ke ya khetholloa ho tobetso e fosahetseng kamora selemo.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Mananeo a dipotso a :n a rometsweng ho mofani.',
        'recorded' => 'Dikarabo di bolokilwe — :completeness% e felletse.',
        'reviewed' => 'Lenaneo la dipotso le tshwailwe jwaloka le hlahlobiloeng.',
    ],
    'alerts' => [
        'subject' => '[:severity] Temoso ya mofani — :supplier',
        'greeting' => 'Dumela,',
        'context' => ':supplier hona jwale o sehlopheng :band.',
        'action' => 'Bula pasepoto ya mofani',
        'db_title' => 'Temoso ya mofani: :supplier',
        'routing_saved' => 'Tsamaiso ya ditemoso e bolokilwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Qeto e tlalehilwe. Phetoho ena e ke ke ya hlaha hape lebokoseng le kenang.',
        'swept' => 'Ditlhahlobo tse :checks di etsitswe hape; diphetoho tse :changes di fumanwe.',
        'no_sources' => 'Ha ho mohlodi o behiloeng bakeng sa ditlhahlobo tse ne di lokela, ka hoo ha ho letho le etsitsweng hape. Dikheo di tlalehwa khahlanong le mofani ho ena le ho patwa.',
        'errors' => [
            'reason_required' => 'Bolela hobaneng phetoho ena e lahlwa. Kamora selemo, thibelo e sa hlalosweng e ke ke ya khetholloa ho tobetso e fosahetseng.',
        ],
    ],
    'cases' => [
        'opened' => 'Nyewe :reference e butswe.',
        'moved' => 'Nyewe e isitswe ho :state.',
        'edd_recorded' => 'Ntho ya patlisiso e matlafaditsweng e tlalehilwe.',
        'blacklisted' => 'E ekeditswe lenaneng le letsho. Kenyo e tla hlokomelisa ka lehlakore lena, ka batho ba lona, le ka eng kapa eng eo e hlahang bonong ba yona.',
        'blacklist_lifted' => 'Kenyo lenaneng le letsho e tloswe. Nalane e dula rekotong.',
        'roles_saved' => 'Mesebetsi ya tumello e bolokilwe.',
        'errors' => [
            'waiver_reason' => 'Bolela hobaneng ntho ena e lesitswe. Sephuthelwana sa patlisiso e matlafaditsweng se ka lesitsoang ka thoko ke mokgabiso.',
            'blacklist_slot' => 'Ho kenya lenaneng le letsho ke qeto ya boetapele. Kopa motho ya behiloeng sebakeng sa Boetapele, kapa beha wena pele.',
        ],
    ],
    'reputation' => [
        'ran' => 'Mehlodi e :sources e batlilwe. Diphumano tse ncha tse :new; tse :review ha di ka hokahanngwa le mofani ona mme di hloka tlhahlobo.',
        'no_sources' => 'Ha ho mohlodi wa litaba kapa wa marang-rang o behiloeng, ka hoo ha ho letho le batlilweng. Mofani eo ho se motho ya ka mo batlang ha a lokela ho ferekanngwa le eo ho se letho le fumanweng ka yena — beha mohlodi tlasa Configure → Data sources.',
        'adjudicated' => 'Qeto e tlalehilwe. Phumano ena e ke ke ya hlahiswa hape ke tshebetso e tlang.',
        'handle_added' => 'E ekeditswe ho seo se shebellwang bakeng sa mofani ona.',
        'handle_removed' => 'E tloswa ho seo se shebellwang.',
        'errors' => [
            'reason_required' => 'Bolela hobaneng phumano ena e tloswa. Thibelo e sa hlalosweng e ke ke ya khetholloa ho tobetso e fosahetseng kamora selemo.',
        ],
    ],
    'ownership' => [
        'added' => 'Karolo ya bonong e tlalehilwe.',
        'updated' => 'Karolo ya bonong e ntlafaditswe.',
        'errors' => [
            'self_ownership' => 'Khampani e ke ke ya ba le karolo ka boyona. Kgetha mong e mong, kapa khampani e nngwe eo e tla ba le karolo ho yona.',
            'holder_required' => 'Bolela mong, kapa kgetha motho ya se ntse a le faeleng.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check e tlalehilwe jwaloka :status.',
        'pin_saved' => 'TCS PIN e bolokilwe. Boemo ba tumellano ya lekgetho hona jwale bo ka netefatswa bakeng sa mofani ona.',
        'errors' => [
            'no_source' => 'Ha ho mohlodi o behiloeng bakeng sa :check, ka hoo e ke ke ya etswa ka boiketsetso. Tlaleha tlhahlobo ka bowena, kapa beha mohlodi tlasa Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'E hweditše :score% kgahlanong le mollwane wa tlhahlobo wa :threshold%. Hlahloba mofani yo pele o fa mošomo o mongwe.',
        'flash' => [
            'template_created' => 'Karete ya dintlha e thehilwe. Kenya dipotso tseo e lokelang ho di botsa.',
            'template_saved' => 'Karete e bolokilwe.',
            'template_removed' => 'Karete e tlositswe.',
            'template_in_use' => 'Karete ena e se e sebedisitswe tlhahlobong, kahoo e ke ke ya hlakolwa — beha boemo ba yona e le e tlositsweng.',
            'question_added' => 'Potso e kentswe.',
            'question_saved' => 'Potso e bolokilwe.',
            'question_removed' => 'Potso e tlositswe.',
            'weight_over' => 'Boima karateng ena bo ka ba 100% kaofela. Ho setse :remaining% ho abuwa.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Sehokelo sena sa portal se felile. Kopa moikopanyi oa hau se secha.',
    ],
    'budget' => [
        'po_note' => 'Odara ea theko :po',
        'invoice_note' => 'Invoyisi ea mofani :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier e phethile ngoliso ea eona ea mofani',
        'mail_body' => ':supplier e tlatsitse ngoliso ea eona mme e emetse ho hlahlojoa.',
        'mail_action' => 'Hlahloba mofani',
        'submitted' => 'Rea leboha — dintlha tsa hau di ho :company bakeng sa tlhahlobo.',
        'link_expired' => 'Sehokelo sena sa ngoliso se felile. Kopa moikopanyi oa hau memo e ncha.',
        'already_registered' => 'Memo ena e se e sebelisitsoe ho ngolisa.',
    ],
];
