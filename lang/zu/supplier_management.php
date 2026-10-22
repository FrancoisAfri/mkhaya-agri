<?php

/**
 * Izintambo zesiZulu zoLawulo Lwabahlinzeki.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'Kulindile',
        1 => 'Kuyasebenza',
        2 => 'Kwenqatshiwe',
        3 => 'I-KYC ilindile',
    ],
    'suppliers' => [
        'activated' => 'Ukwenza kusebenze komhlinzeki kucutshunguliwe.',
        'refused' => 'Umhlinzeki wenqatshiwe, futhi utsheliwe ukuthi kungani.',
        'info_requested' => 'Isicelo solwazi sithunyelwe kumhlinzeki.',
        'portal_link_rotated' => 'Isixhumanisi sesango esisha sikhishiwe. Isixhumanisi sangaphambilini asisasebenzi.',
        'portal_link_reissued' => 'Isixhumanisi sakho sesango sishintshiwe ngenxa yokuphepha. Sebenzisa isixhumanisi esisha esingezansi.',
        'outstanding_documents' => 'Sisadinga lokhu okulandelayo kuwe: :documents',
        'errors' => [
            'duplicate' => 'Le nombolo yokubhalisa noma i-imeyili isivele ingekaka-:supplier (:number). Vula lelo rekhodi esikhundleni sokudala elesibili.',
            'missing_documents' => 'Lo mhlinzeki ngeke enziwe asebenze kuze kube yilapho la mabhuku ayimpoqo esefayeleni futhi esalungile: :documents',
            'blacklisted' => 'Leli qembu lisohlwini olumnyama — :matched. Isizathu esirekhodiwe: :reason. Uma leli yiqembu elehlukile, noma isivimbo kufanele sisuswe, lokho kuyisinqumo sabaphathi ngaphambi kokuthi umhlinzeki angezwe.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Isimemo sithunyelwe. Isixhumanisi sangaphambilini, uma sikhona, asisasebenzi.',
        'mail_subject' => 'Umenyiwe ukuthi ubhalise njengomhlinzeki ku-:company',
        'mail_greeting' => 'Sawubona :name,',
        'mail_intro' => 'I-:company ingathanda ukukufaka ohlwini lwayo lwabahlinzeki abavunyiwe. Ukubhalisa kuthatha imizuzu embalwa.',
        'mail_action' => 'Qala ukubhalisa',
        'mail_expiry' => 'Lesi sixhumanisi sisebenza kuze kube ngu-:date. Uma siphelelwa isikhathi, cela umxhumani wakho akuthumele esisha.',
        'mail_closing' => 'Ukuba namadokhumenti angenhla ngaphambi kokuqala kuzokwenza lokhu kusheshe.',
    ],
    'decisions' => [
        'greeting' => 'Sawubona :name,',
        'comment_label' => 'Amanothi ovivinyayo:',
        'mail_action' => 'Vula isango lakho lomhlinzeki',
        'mail_closing' => 'Phendula le imeyili uma kukhona okubukeka kungalungile lapha.',
        'approved' => [
            'mail_subject' => 'Manje usungumhlinzeki ovunyiwe ku-:company',
            'mail_body' => 'Ukubhalisa kwakho kwamukelwe. I-:company manje ingakhipha ama-oda kuwe.',
        ],
        'refused' => [
            'mail_subject' => 'Ukubhalisa kwakho njengomhlinzeki ku-:company',
            'mail_body' => 'I-:company ayikwazi ukuqhubeka nokubhalisa kwakho okwamanje.',
        ],
        'information_requested' => [
            'mail_subject' => 'I-:company idinga olunye ulwazi kuwe',
            'mail_body' => 'Ukubhalisa kwakho kumisiwe size sithole okwengeziwe kuwe.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Isixhumanisi sakho esisha sesango se-:company',
            'mail_body' => 'Isixhumanisi sakho sesango sishintshiwe ngenxa yokuphepha. Isixhumanisi sangaphambilini asisasebenzi — sebenzisa inkinobho engezansi kusukela manje.',
        ],
    ],
    'kyc' => [
        'approved' => 'I-KYC yamukelwe; umhlinzeki manje uyasebenza.',
        'rejected' => 'I-KYC yenqatshiwe, futhi umhlinzeki utsheliwe ukuthi kungani.',
        'mail_subject' => 'Kudingeka ukubuyekezwa kwe-KYC: :supplier',
        'mail_greeting' => 'Sawubona,',
        'mail_body' => 'I-:supplier ilinde isinqumo se-KYC.',
        'mail_action' => 'Buyekeza ulayini we-KYC',
        'errors' => [
            'already_decided' => 'Lokhu okuthunyelwe sekuvele kwaba :status. Vula kabusha umhlinzeki uma isinqumo sidinga ukubuyekezwa.',
            'self_review' => 'Nguwe owadala lo mhlinzeki, ngakho ngeke uphinde uvume i-KYC yakhe. Kudinga umbuyekezi wesibili.',
        ],
    ],
    'rfx' => [
        'published' => 'Umcimbi ushicilelwe. Abahlinzeki abamenyiwe sebengafaka amabhidi.',
        'closed' => 'Umcimbi uvaliwe. Awekho amanye amabhidi azokwamukelwa.',
        'response_recorded' => 'Ibhidi lirekhodiwe.',
        'evaluated' => 'Ukuhlolwa kwebhidi kwe-AI kuqediwe.',
        'awarded' => 'Umcimbi uklonyelisiwe.',
        'statuses' => [
            'draft' => 'uhlaka',
            'published' => 'kushicilelwe',
            'closed' => 'kuvaliwe',
            'awarded' => 'kuklonyelisiwe',
            'cancelled' => 'kukhanseliwe',
        ],
        'errors' => [
            'invalid_transition' => 'Umcimbi ongu-:from awukwazi ukususwa uye ku-:to.',
            'publish_without_invitations' => 'Mema okungenani umhlinzeki oyedwa ngaphambi kokushicilela — umcimbi okungekho muntu ocelwe ukufaka ibhidi kuwo ngeke uklonyeliswe.',
            'publish_after_closing' => 'Usuku lokuvala selidlulile. Lususe luye phambili ngaphambi kokushicilela.',
            'bid_when_not_published' => 'Amabhidi angarekhodwa kuphela ngenkathi umcimbi ushicilelwe. Lo ungu-:status.',
            'bid_after_closing' => 'Lo mcimbi wavalwa ngo-:date. Ibhidi elifikile emva kwesikhathi ngeke lamukelwe.',
            'bid_from_uninvited' => 'I-:supplier ayizange imenywe kulo mcimbi. Bameme kuqala uma kufanele babambe iqhaza.',
            'award_without_bid' => 'I-:supplier ayizange ifake ibhidi kulo mcimbi, ngakho ngeke iklonyeliswe.',
            'award_reason_required' => 'Leli akulona ibhidi eliphansi kunawo wonke, ngakho umklomelo udinga isizathu esirekhodiwe.',
        ],
    ],
    'orders' => [
        'created' => 'I-oda lokuthenga lidaliwe.',
        'updated' => 'I-oda lokuthenga libuyekeziwe.',
        'removed' => 'I-oda lokuthenga lisusiwe, futhi noma yiziphi izimali ebezibanjiwe sezikhululiwe.',
        'statuses' => [
            'draft' => 'uhlaka',
            'approved' => 'kuvunyiwe',
            'received' => 'kutholiwe',
            'closed' => 'kuvaliwe',
            'cancelled' => 'kukhanseliwe',
        ],
        'errors' => [
            'supplier_not_active' => 'Lo mhlinzeki ungu-:status futhi ngeke athole ama-oda amasha okuthenga kuze kube yilapho esevuselelwe.',
            'expired_documents' => 'Lo mhlinzeki unamadokhumenti ayimpoqo aphelelwe yisikhathi futhi ngeke athole ama-oda amasha: :documents',
            'invalid_transition' => 'I-oda elingu-:from ngeke lisuswe liye ku-:to.',
            'self_approval' => 'Nguwe owakhipha leli oda, ngakho ngeke uphinde ulivume. Lidinga umvumi wesibili.',
        ],
    ],
    'invoices' => [
        'captured' => 'I-invoyisi ithwetshuliwe.',
        'captured_with_variance' => 'I-invoyisi ithwetshuliwe, kodwa ingu-:variance ngaphezu kwe-oda lokuthenga :po. Ibanjelwe ukubuyekezwa futhi ngeke ikhokhwe kuze kube yilapho lokho kuxazululiwe.',
        'updated' => 'I-invoyisi ibuyekeziwe.',
        'removed' => 'I-invoyisi isusiwe, futhi izindleko zibuyiselwe emuva esabelweni.',
        'errors' => [
            'duplicate_number' => 'I-invoyisi :number isivele ithwetshuliwe kulo mhlinzeki. Vula ekhona kunokuyifaka kabili.',
            'order_belongs_to_other_supplier' => 'I-oda lokuthenga :po lakhishelwa omunye umhlinzeki, ngakho le nvoyisi ngeke ihambisane nalo.',
            'pay_with_open_variance' => 'Le nvoyisi ingaphezu kwe-oda layo lokuthenga futhi ibanjelwe ukubuyekezwa. Xazulula umehluko ngaphambi kokuyimaka njengekhokhiwe.',
            'pay_with_unverified_banking' => 'Inkokhelo ibanjwe ngenxa yemininingwane yebhange yalo mhlinzeki. :reason Qinisekisa i-akhawunti kumhlinzeki ngaphambi kokukhokha.',
        ],
    ],
    'addon' => [
        'unavailable' => 'I-Supplier Intelligence & Due Diligence ayiyona ingxenye yalokhu okubhalisiwe. Yengeza ukuqinisekisa korejista, intela, ibhange, ubunikazi, isikweletu nezijeziso ekuphatheni abahlinzeki — khuluma noMkhaya ukuyengeza.',
    ],
    'verdict' => [
        'never_assessed' => 'Ukucwaninga okufanele akwenziwe kulo mhlinzeki.',
        'cleared' => 'Ukucwaninga okufanele kuphumelele — ingozi :score/100, wonke amasango acacile.',
        'blocked' => 'Ukucwaninga okufanele akuphelele — ingozi :score/100, izinto ezingu-:count ezisalindile.',
        'sanctions' => 'Ukufana kwezijeziso okuvuthayo kurekhodiwe kulo mhlinzeki.',
    ],
    'risk' => [
        'assessed' => 'Kuhlolwe: :score kwayi-100 (:band).',
        'tiered' => 'Lo msebenzi ungezinga :tier — :label. Ukuhlolwa okudingekayo kubuyekeziwe.',
        'screened' => 'Kuhlolwe abantu abangu-:subjects. Ukufana okusha okungu-:new kudinga ukunqumelwa.',
        'screening_unavailable' => 'Akukho mthombo wokuhlunga olungiselelwe, ngakho akukho okuhlolwe. Qopha okutholayo kuzo zonke izinhlu ezishicilelwe, noma lungiselela umthombo ngaphansi kweConfigure → Data sources.',
        'hit_recorded' => 'Ukufana kuqoshiwe. Kubalwa ngokumelene nalo mhlinzeki kuze kube kukhona oyinqumelayo.',
        'adjudicated' => 'Isinqumo siqoshiwe. Ngeke siphakanyiswe futhi ngokuhlunga kwakamuva.',
        'credit_recorded' => 'Umbiko wesikweletu uthwebulwe.',
        'errors' => [
            'reason_required' => 'Sho ukuthi kungani lokhu kufana kususwa. Ukwenqaba okungachazwanga ngeke kuhlukaniswe nokuchofoza iphutha ngemuva konyaka.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Amafomu emibuzo angu-:n athunyelwe kumhlinzeki.',
        'recorded' => 'Izimpendulo zilondolozwe — :completeness% kuphelele.',
        'reviewed' => 'Ifomu lemibuzo liphawulwe njengelibuyekeziwe.',
    ],
    'alerts' => [
        'subject' => '[:severity] Isexwayiso somhlinzeki — :supplier',
        'greeting' => 'Sawubona,',
        'context' => 'I-:supplier okwamanje isebandeni :band.',
        'action' => 'Vula ipasipothi yomhlinzeki',
        'db_title' => 'Isexwayiso somhlinzeki: :supplier',
        'routing_saved' => 'Ukuqondisa izexwayiso kulondolozwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Isinqumo siqoshiwe. Lesi shintsho ngeke sivele futhi kubhokisi lokungenayo.',
        'swept' => 'Ukuhlolwa okungu-:checks kuphindwe; izinguquko ezingu-:changes zitholakele.',
        'no_sources' => 'Akukho mthombo olungiselelwe kunoma yikuphi ukuhlolwa okufanele, ngakho akukho okuphindwe. Izikhala ziqoshwa ngokumelene nomhlinzeki esikhundleni sokufihlwa.',
        'errors' => [
            'reason_required' => 'Sho ukuthi kungani lesi shintsho sichithwa. Ngemuva konyaka, ukwenqaba okungachazwanga ngeke kuhlukaniswe nokuchofoza iphutha.',
        ],
    ],
    'cases' => [
        'opened' => 'Icala :reference livuliwe.',
        'moved' => 'Icala lidluliselwe ku-:state.',
        'edd_recorded' => 'Into yokucwaninga okwengeziwe iqoshiwe.',
        'blacklisted' => 'Kwengezwe ohlwini olumnyama. Ukungenisa manje kuzoxwayisa ngaleli qembu, ngabantu balo, nangakho konke okuvela kubunikazi balo.',
        'blacklist_lifted' => 'Ukufakwa ohlwini olumnyama kususiwe. Umlando uhlala erekhodini.',
        'roles_saved' => 'Izindima zokugunyaza zilondolozwe.',
        'errors' => [
            'waiver_reason' => 'Sho ukuthi kungani le nto idedelwa. Iphakethe lokucwaninga okwengeziwe elingadedelwa buthule liwumhlobiso.',
            'blacklist_slot' => 'Ukufaka ohlwini olumnyama kuyisinqumo sabaphathi. Cela umuntu othweswe isikhala soMphathi, noma uzifake kuso kuqala.',
        ],
    ],
    'reputation' => [
        'ran' => 'Kucwaningwe imithombo engu-:sources. Okutholakele okusha okungu-:new; okungu-:review akukwazanga kuxhunywe kulo mhlinzeki futhi kudinga ukubuyekezwa.',
        'no_sources' => 'Akukho mthombo wezindaba noma wezinkundla zokuxhumana olungiselelwe, ngakho akukho okucwaningiwe. Umhlinzeki okungekho muntu obengamcwaninga akumele adidaniswe nalowo okungatholakalanga lutho ngaye — lungiselela umthombo ngaphansi kweConfigure → Data sources.',
        'adjudicated' => 'Isinqumo siqoshiwe. Lokhu okutholakele ngeke kuphakanyiswe futhi ekusebenzeni kwakamuva.',
        'handle_added' => 'Kwengezwe kulokho okubhekwayo kulo mhlinzeki.',
        'handle_removed' => 'Kususwe kulokho okubhekwayo.',
        'errors' => [
            'reason_required' => 'Sho ukuthi kungani lokhu okutholakele kususwa. Ukwenqaba okungachazwanga ngeke kuhlukaniswe nokuchofoza iphutha ngemuva konyaka.',
        ],
    ],
    'ownership' => [
        'added' => 'Isabelo sobunikazi siqoshiwe.',
        'updated' => 'Isabelo sobunikazi sibuyekeziwe.',
        'errors' => [
            'self_ownership' => 'Inkampani ayikwazi ukuphatha isabelo kuyona uqobo. Khetha omunye umphathi, noma enye inkampani ukuyiphatha.',
            'holder_required' => 'Qamba umphathi, noma khetha umuntu osevele esefayeleni.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check iqoshwe njenge-:status.',
        'pin_saved' => 'I-TCS PIN ilondolozwe. Isimo sokuhambisana nentela manje singaqinisekiswa kulo mhlinzeki.',
        'errors' => [
            'no_source' => 'Akukho mthombo olungiselelwe we-:check, ngakho ngeke kwenziwe ngokuzenzakalela. Qopha ukuhlolwa ngokwakho, noma lungiselela umthombo ngaphansi kweConfigure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Ithole u-:score% maqondana nomkhawulo wokubuyekeza ka-:threshold%. Hlola lo mhlinzeki ngaphambi kokunikeza omunye umsebenzi.',
        'flash' => [
            'template_created' => 'Ikhadi lamaphuzu lidaliwe. Faka imibuzo okufanele libuze.',
            'template_saved' => 'Ikhadi lilondoloziwe.',
            'template_removed' => 'Ikhadi lisusiwe.',
            'template_in_use' => 'Leli khadi selisetshenziselwe ukuhlola, ngakho alinakususwa — setha isimo salo kube okuthathe umhlalaphansi.',
            'question_added' => 'Umbuzo wengeziwe.',
            'question_saved' => 'Umbuzo ulondoloziwe.',
            'question_removed' => 'Umbuzo ususiwe.',
            'weight_over' => 'Izisindo kuleli khadi zingaba ngu-100% sezizonke. Kusele u-:remaining% okufanele wabiwe.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Lesi sixhumanisi sesango siphelelwe yisikhathi. Cela umxhumani wakho esisha.',
    ],
    'budget' => [
        'po_note' => 'I-oda lokuthenga :po',
        'invoice_note' => 'I-invoyisi yomhlinzeki :number',
    ],
    'registration' => [
        'mail_subject' => 'I-:supplier iqedile ukubhalisa kwayo njengomhlinzeki',
        'mail_body' => 'I-:supplier igcwalise ukubhalisa kwayo futhi ilinde ukubuyekezwa.',
        'mail_action' => 'Buyekeza umhlinzeki',
        'submitted' => 'Siyabonga — imininingwane yakho ise-:company ukuze ibuyekezwe.',
        'link_expired' => 'Lesi sixhumanisi sokubhalisa siphelelwe yisikhathi. Cela umxhumani wakho isimemo esisha.',
        'already_registered' => 'Lesi simemo sesivele sisetshenzisiwe ukubhalisa.',
    ],
];
