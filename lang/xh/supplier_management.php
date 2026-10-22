<?php

/**
 * Imitya yesiXhosa yoLawulo lwaBaboneleli.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'Kulindwe',
        1 => 'Iyasebenza',
        2 => 'Yaliwe',
        3 => 'I-KYC ilindile',
    ],
    'suppliers' => [
        'activated' => 'Ukuvulwa komboneleli kuqhutyiwe.',
        'refused' => 'Umboneleli waliwe, kwaye waxelelwa isizathu.',
        'info_requested' => 'Isicelo solwazi sithunyelwe kumboneleli.',
        'portal_link_rotated' => 'Ikhonkco elitsha lesango likhutshiwe. Ikhonkco langaphambili alisasebenzi.',
        'portal_link_reissued' => 'Ikhonkco lakho lesango litshintshiwe ngenxa yokhuseleko. Sebenzisa ikhonkco elitsha elingezantsi.',
        'outstanding_documents' => 'Sisafuna oku kulandelayo kuwe: :documents',
        'errors' => [
            'duplicate' => 'Eli nani lobhaliso okanye i-imeyile sele lilelika-:supplier (:number). Vula loo rekhodi endaweni yokwenza eyesibini.',
            'missing_documents' => 'Lo mboneleli akanakuvulwa de la maxwebhu anyanzelekileyo abekho kwaye asebenza: :documents',
            'blacklisted' => 'Eli qela likwuluhlu olumnyama — :matched. Isizathu esibhaliweyo: :reason. Ukuba eli liqela elahlukileyo, okanye isithintelo kufuneka sisuswe, oko sisigqibo sabalawuli phambi kokuba umthengisi ongezwe.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Isimemo sithunyelwe. Ikhonkco langaphambili, ukuba likhona, alisasebenzi.',
        'mail_subject' => 'Umenyiwe ukuba ubhalise njengomboneleli ku-:company',
        'mail_greeting' => 'Molo :name,',
        'mail_intro' => 'I-:company ingathanda ukukongeza kuluhlu lwayo lwababoneleli abamkelweyo. Ukubhalisa kuthatha imizuzu embalwa.',
        'mail_action' => 'Qala ubhaliso',
        'mail_expiry' => 'Eli khonkco lisebenza de kube ngu-:date. Ukuba liphelelwa, cela umqhagamshelwano wakho akuthumele elitsha.',
        'mail_closing' => 'Ukuba namaxwebhu angentla ngaphambi kokuqala kuya kwenza oku kukhawuleze.',
    ],
    'decisions' => [
        'greeting' => 'Molo :name,',
        'comment_label' => 'Amanqaku omhloli:',
        'mail_action' => 'Vula isango lakho lomboneleli',
        'mail_closing' => 'Phendula le imeyile ukuba kukho into ebonakala ingalunganga apha.',
        'approved' => [
            'mail_subject' => 'Ngoku ungumboneleli owamkelweyo ku-:company',
            'mail_body' => 'Ubhaliso lwakho lwamkelwe. I-:company ngoku inokwenza ii-odolo kuwe.',
        ],
        'refused' => [
            'mail_subject' => 'Ubhaliso lwakho njengomboneleli ku-:company',
            'mail_body' => 'I-:company ayikwazi ukuqhubeka nobhaliso lwakho okwangoku.',
        ],
        'information_requested' => [
            'mail_subject' => 'I-:company ifuna ulwazi olongezelelweyo kuwe',
            'mail_body' => 'Ubhaliso lwakho lumisiwe de sifumane okungakumbi kuwe.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Ikhonkco lakho elitsha lesango le-:company',
            'mail_body' => 'Ikhonkco lakho lesango litshintshiwe ngenxa yokhuseleko. Ikhonkco langaphambili alisasebenzi — sebenzisa iqhosha elingezantsi ukusukela ngoku.',
        ],
    ],
    'kyc' => [
        'approved' => 'I-KYC yamkelwe; umboneleli ngoku uyasebenza.',
        'rejected' => 'I-KYC yaliwe, kwaye umboneleli waxelelwa isizathu.',
        'mail_subject' => 'Kufuneka uphononongo lwe-KYC: :supplier',
        'mail_greeting' => 'Molo,',
        'mail_body' => 'I-:supplier ilindele isigqibo se-KYC.',
        'mail_action' => 'Phonononga umgca we-KYC',
        'errors' => [
            'already_decided' => 'Olu ngeniso sele lwaba :status. Vula kwakhona umboneleli ukuba isigqibo sifuna ukuphononongwa.',
            'self_review' => 'Nguwe owenza lo mboneleli, ngoko awukwazi ukuvuma i-KYC yakhe. Kufuna umphononongi wesibini.',
        ],
    ],
    'rfx' => [
        'published' => 'Umsitho upapashiwe. Ababoneleli abamenyiweyo ngoku bangathumela iibhidi.',
        'closed' => 'Umsitho uvaliwe. Akukho zinye iibhidi eziya kwamkelwa.',
        'response_recorded' => 'Ibhidi irekhodiwe.',
        'evaluated' => 'Uvavanyo lwebhidi lwe-AI lugqityiwe.',
        'awarded' => 'Umsitho unikezelwe.',
        'statuses' => [
            'draft' => 'uyilo',
            'published' => 'upapashiwe',
            'closed' => 'uvaliwe',
            'awarded' => 'unikezelwe',
            'cancelled' => 'urhoxisiwe',
        ],
        'errors' => [
            'invalid_transition' => 'Umsitho ongu-:from awunako ukususwa uye ku-:to.',
            'publish_without_invitations' => 'Mema ubuncinane umboneleli omnye phambi kokupapasha — umsitho ekungekho mntu wacelwa ukuba athumele ibhidi kuwo awunakunikezelwa.',
            'publish_after_closing' => 'Umhla wokuvala sele udlulile. Wususe uye phambili phambi kokupapasha.',
            'bid_when_not_published' => 'Iibhidi zinokurekhodwa kuphela ngelixa umsitho upapashiwe. Lo ngu-:status.',
            'bid_after_closing' => 'Lo msitho wavalwa nge-:date. Ibhidi efike emva kwexesha ayinakwamkelwa.',
            'bid_from_uninvited' => 'I-:supplier ayizange imenywe kulo msitho. Bameme kuqala ukuba kufuneka bathabathe inxaxheba.',
            'award_without_bid' => 'I-:supplier ayizange ithumele ibhidi kulo msitho, ngoko ayinakunikezelwa kuyo.',
            'award_reason_required' => 'Le asiyiyo ibhidi esezantsi, ngoko umnikezelo ufuna isizathu esirekhodiweyo.',
        ],
    ],
    'orders' => [
        'created' => 'I-odolo lokuthenga lenziwe.',
        'updated' => 'I-odolo lokuthenga luhlaziyiwe.',
        'removed' => 'I-odolo lokuthenga lususiwe, kwaye nayiphi na imali ebibanjiwe ikhululiwe.',
        'statuses' => [
            'draft' => 'uyilo',
            'approved' => 'lwamkelwe',
            'received' => 'lufunyenwe',
            'closed' => 'luvaliwe',
            'cancelled' => 'lurhoxisiwe',
        ],
        'errors' => [
            'supplier_not_active' => 'Lo mboneleli ngu-:status kwaye akanakufumana ii-odolo ezintsha zokuthenga de avulwe kwakhona.',
            'expired_documents' => 'Lo mboneleli unamaxwebhu anyanzelekileyo aphelelwe lixesha kwaye akanakufumana ii-odolo ezintsha: :documents',
            'invalid_transition' => 'I-odolo elingu-:from alinakususwa liye ku-:to.',
            'self_approval' => 'Nguwe owenza eli odolo, ngoko awukwazi ukulivuma. Lifuna umvumi wesibini.',
        ],
    ],
    'invoices' => [
        'captured' => 'I-invoyisi ithathiwe.',
        'captured_with_variance' => 'I-invoyisi ithathiwe, kodwa ingu-:variance ngaphezu kwe-odolo lokuthenga :po. Ibanjelwe uphononongo kwaye ayinakuhlawulwa de oko kucombululwe.',
        'updated' => 'I-invoyisi ihlaziyiwe.',
        'removed' => 'I-invoyisi isusiwe, kwaye inkcitho ibuyiselwe kuhlahlo-lwabiwo-mali.',
        'errors' => [
            'duplicate_number' => 'I-invoyisi :number sele ithathiwe kulo mboneleli. Vula ekhoyo endaweni yokuyifaka kabini.',
            'order_belongs_to_other_supplier' => 'I-odolo lokuthenga :po lenzelwe omnye umboneleli, ngoko le nvoyisi ayinakuhambelana nalo.',
            'pay_with_open_variance' => 'Le nvoyisi ingaphezu kwe-odolo layo lokuthenga kwaye ibanjelwe uphononongo. Combulula umahluko phambi kokuyiphawula njengehlawulweyo.',
            'pay_with_unverified_banking' => 'Intlawulo ibanjezelwe ngenxa yeenkcukacha zebhanki zalo mthengisi. :reason Qinisekisa i-akhawunti kumthengisi phambi kokuhlawula.',
        ],
    ],
    'addon' => [
        'unavailable' => 'I-Supplier Intelligence & Due Diligence ayiyinxalenye yolu rhumo. Yongeza ukuqinisekiswa korhejistara, irhafu, ibhanki, ubunini, ityala kunye nezohlwayo kulawulo lwabathengisi — thetha noMkhaya ukuyongeza.',
    ],
    'verdict' => [
        'never_assessed' => 'Akukho uphando lufanelekileyo olwenziweyo kulo mthengisi.',
        'cleared' => 'Uphando olufanelekileyo luphumelele — umngcipheko :score/100, onke amasango acacile.',
        'blocked' => 'Uphando olufanelekileyo alugqibelelanga — umngcipheko :score/100, izinto ezingama-:count ezisalindileyo.',
        'sanctions' => 'Ukufana kwezohlwayo okusebenzayo kubhaliwe kulo mthengisi.',
    ],
    'risk' => [
        'assessed' => 'Kuvavanyiwe: :score kwi-100 (:band).',
        'tiered' => 'Lo msebenzi ukwinqanaba :tier — :label. Uhlolo olufunekayo luhlaziyiwe.',
        'screened' => 'Kuhluzwe abantu abangama-:subjects. Ukufana okutsha okungama-:new kufuna ukugqitywa.',
        'screening_unavailable' => 'Akukho mthombo wokuhluza omiselweyo, ngoko akukho nto ehloliweyo. Bhala oko ukufumanayo kuluhlu olupapashiweyo, okanye misela umthombo phantsi kwe-Configure → Data sources.',
        'hit_recorded' => 'Ukufana kubhalwe. Kubalwa ngokuchasene nalo mthengisi de kubekho umntu ogqibayo.',
        'adjudicated' => 'Isigqibo sibhaliwe. Asizokuvuswa kwakhona luhluzo olulandelayo.',
        'credit_recorded' => 'Ingxelo yetyala ibanjwe.',
        'errors' => [
            'reason_required' => 'Yithi kutheni oku kufana kususwa. Ukwaliwa okungacacisiwe akunakwahlulwa kwicofa eliphosakeleyo emva konyaka.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Amaphepha-mibuzo angama-:n athunyelwe kumthengisi.',
        'recorded' => 'Iimpendulo zigcinwe — :completeness% kugqitywe.',
        'reviewed' => 'Iphepha-mibuzo liphawulwe njengelihloliweyo.',
    ],
    'alerts' => [
        'subject' => '[:severity] Isilumkiso somthengisi — :supplier',
        'greeting' => 'Molo,',
        'context' => 'I-:supplier okwangoku ikwibhanti :band.',
        'action' => 'Vula ipasipoti yomthengisi',
        'db_title' => 'Isilumkiso somthengisi: :supplier',
        'routing_saved' => 'Ukuthumela izilumkiso kugcinwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Isigqibo sibhaliwe. Olu tshintsho alusayi kuvela kwakhona kwibhokisi yeengeniso.',
        'swept' => 'Uhlolo olungama-:checks luphindiwe; utshintsho olungama-:changes lufunyenwe.',
        'no_sources' => 'Akukho mthombo omiselweyo kuluphi na uhlolo olufanelekileyo, ngoko akukho nto ephindiweyo. Izikhewu zibhalwa ngokuchasene nomthengisi endaweni yokufihlwa.',
        'errors' => [
            'reason_required' => 'Yithi kutheni olu tshintsho lulahlwa. Emva konyaka, ukwaliwa okungacacisiwe akunakwahlulwa kwicofa eliphosakeleyo.',
        ],
    ],
    'cases' => [
        'opened' => 'Ityala :reference livulwe.',
        'moved' => 'Ityala lidluliselwe ku-:state.',
        'edd_recorded' => 'Into yophando oluqinisiweyo ibhalwe.',
        'blacklisted' => 'Yongezwe kuluhlu olumnyama. Ukungenisa ngoku luya kulumkisa ngeli qela, ngabantu balo, nangantoni na apho livela kubunini.',
        'blacklist_lifted' => 'Ungeniso kuluhlu olumnyama lususwe. Imbali ihlala kwirekhodi.',
        'roles_saved' => 'Iindima zokuphumeza zigcinwe.',
        'errors' => [
            'waiver_reason' => 'Yithi kutheni le nto iyekelelwa. Ipaki yophando oluqinisiweyo enokuyekelelwa buthule ngumhombiso.',
            'blacklist_slot' => 'Ukufaka kuluhlu olumnyama sisigqibo sabalawuli. Cela umntu obekwe kwisithuba saBalawuli, okanye uzibeke kuso kuqala.',
        ],
    ],
    'reputation' => [
        'ran' => 'Kukhangelwe imithombo engama-:sources. Okufunyenweyo okutsha okungama-:new; okungama-:review akukwazi kunxulunyaniswa nalo mthengisi kwaye kufuna uphononongo.',
        'no_sources' => 'Akukho mthombo weendaba okanye wonxibelelwano omiselweyo, ngoko akukho nto ekhangelweyo. Umthengisi ongenakukhangelwa akufuneki aphazamiswe nalowo akukho nto efunyenwe ngaye — misela umthombo phantsi kwe-Configure → Data sources.',
        'adjudicated' => 'Isigqibo sibhaliwe. Oku kufunyenweyo akusayi kuvuswa kwakhona kusebenzo olulandelayo.',
        'handle_added' => 'Yongezwe koko kubhekiweyo kulo mthengisi.',
        'handle_removed' => 'Isuswe koko kubhekiweyo.',
        'errors' => [
            'reason_required' => 'Yithi kutheni oku kufunyenweyo kususwa. Ukwaliwa okungacacisiwe akunakwahlulwa kwicofa eliphosakeleyo emva konyaka.',
        ],
    ],
    'ownership' => [
        'added' => 'Isabelo sobunini sibhalwe.',
        'updated' => 'Isabelo sobunini sihlaziyiwe.',
        'errors' => [
            'self_ownership' => 'Inkampani ayinakuba nesabelo kuyo ngokwayo. Khetha omnye umnini, okanye enye inkampani yokubamba.',
            'holder_required' => 'Chaza umnini, okanye khetha umntu sele ekwifayile.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check ibhalwe njenge-:status.',
        'pin_saved' => 'I-TCS PIN igcinwe. Ubume bokuthobela irhafu ngoku bunokuqinisekiswa kulo mthengisi.',
        'errors' => [
            'no_source' => 'Akukho mthombo omiselweyo we-:check, ngoko ayinakuqhutywa ngokuzenzekelayo. Bhala uhlolo ngokwakho, okanye misela umthombo phantsi kwe-Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Ifumene u-:score% ngokuchasene nomda wophononongo we-:threshold%. Khangela lo mboneleli phambi kokunika omnye umsebenzi.',
        'flash' => [
            'template_created' => 'Ikhadi lamanqaku lenziwe. Yongeza imibuzo ekufuneka liyibuze.',
            'template_saved' => 'Ikhadi ligciniwe.',
            'template_removed' => 'Ikhadi lisusiwe.',
            'template_in_use' => 'Eli khadi sele lisetyenziselwe uvavanyo, ngoko alinakucinywa — misela isimo salo kube lithathe umhlala-phantsi.',
            'question_added' => 'Umbuzo wongeziwe.',
            'question_saved' => 'Umbuzo ugciniwe.',
            'question_removed' => 'Umbuzo ususiwe.',
            'weight_over' => 'Ubunzima kweli khadi bunokufikelela ku-100% bebonke. Kusele i-:remaining% emayabelwe.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Eli khonkco lesango liphelelwe lixesha. Cela umqhagamshelwano wakho elitsha.',
    ],
    'budget' => [
        'po_note' => 'I-odolo lokuthenga :po',
        'invoice_note' => 'I-invoyisi yomboneleli :number',
    ],
    'registration' => [
        'mail_subject' => 'I-:supplier igqibile ubhaliso lwayo lomboneleli',
        'mail_body' => 'I-:supplier izalisile ubhaliso lwayo kwaye ilindele ukuphononongwa.',
        'mail_action' => 'Phonononga umboneleli',
        'submitted' => 'Enkosi — iinkcukacha zakho zise-:company ukuze ziphononongwe.',
        'link_expired' => 'Eli khonkco lokubhalisa liphelelwe lixesha. Cela umqhagamshelwano wakho isimemo esitsha.',
        'already_registered' => 'Esi simemo sele sisetyenzisiwe ukubhalisa.',
    ],
];
