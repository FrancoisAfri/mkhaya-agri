<?php

/**
 * Amagama esiNdebele wokuPhatha abaRhwebi.
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
        'activated' => 'Ukuvulwa komrhwebi kuqedwe.',
        'refused' => 'Umrhwebi wenqatjhiwe, begodu watjelwa ukuthi kubayini.',
        'info_requested' => 'Isibawo solwazi sithunyelwe kumrhwebi.',
        'portal_link_rotated' => 'Isixhumanisi sesango esisha sikhishiwe. Isixhumanisi sangaphambilini asisasebenzi.',
        'portal_link_reissued' => 'Isixhumanisi sakho sesango sishintshiwe ngenxa yokuphepha. Sebenzisa isixhumanisi esisha esingezansi.',
        'outstanding_documents' => 'Sisatlhoga lokhu okulandelako kuwe: :documents',
        'errors' => [
            'duplicate' => 'Le nombolo yokubhalisa noma i-imeyili isivele ingekaka-:supplier (:number). Vula lelo rekhodi esikhundleni sokudala elesibili.',
            'missing_documents' => 'Lo mhlinzeki ngeke enziwe asebenze kuze kube yilapho la mabhuku ayimpoqo esefayeleni futhi esalungile: :documents',
            'blacklisted' => 'Isiqhema lesi sisererhweni elinzima — :matched. Isizathu esitlolweko: :reason. Nange lesi sisiqhema esihlukileko, namkha isivimbo kufuze sisuswe, lokho kusiqunto sabaphathi ngaphambi kobana umnikeli angezelelwe.',
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
            'mail_subject' => 'Ikhambo lakho elitjha lesango le-:company',
            'mail_body' => 'Ikhambo lakho lesango litjhugululwe ngenxa yokuvikeleka. Ikhambo langaphambili alisasebenzi — sebenzisa ithinjana elingaphasi kusukela nje.',
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
            'self_review' => 'Nguwe owenza lo mrhwebi, ngalokho ngeke uphinde uvumele i-KYC yakhe. Kutlhoga umbuyekezi wesibili.',
        ],
    ],
    'rfx' => [
        'published' => 'Umcimbi urhatjhiwe. Abarhwebi abamenyiweko sebangafaka amabhidi.',
        'closed' => 'Umcimbi uvaliwe. Awekho amanye amabhidi azokwamukelwa.',
        'response_recorded' => 'Ibhidi lirekhodiwe.',
        'evaluated' => 'Ukuhlolwa kwebhidi kwe-AI kuqediwe.',
        'awarded' => 'Umcimbi unikelwe.',
        'statuses' => [
            'draft' => 'uhlaka',
            'published' => 'kurhatjhiwe',
            'closed' => 'kuvaliwe',
            'awarded' => 'kunikelwe',
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
        'created' => 'I-oda lokuthenga lenziwe.',
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
        'captured' => 'I-invoyisi ithethiwe.',
        'captured_with_variance' => 'I-invoyisi ithwetshuliwe, kodwa ingu-:variance ngaphezu kwe-oda lokuthenga :po. Ibanjelwe ukubuyekezwa futhi ngeke ikhokhwe kuze kube yilapho lokho kuxazululiwe.',
        'updated' => 'I-invoyisi ibuyekeziwe.',
        'removed' => 'I-invoyisi isusiwe, futhi izindleko zibuyiselwe emuva esabelweni.',
        'errors' => [
            'duplicate_number' => 'I-invoyisi :number isivele ithwetshuliwe kulo mhlinzeki. Vula ekhona kunokuyifaka kabili.',
            'order_belongs_to_other_supplier' => 'I-oda lokuthenga :po lakhishelwa omunye umhlinzeki, ngakho le nvoyisi ngeke ihambisane nalo.',
            'pay_with_open_variance' => 'Le nvoyisi ingaphezu kwe-oda layo lokuthenga futhi ibanjelwe ukubuyekezwa. Xazulula umehluko ngaphambi kokuyimaka njengekhokhiwe.',
            'pay_with_unverified_banking' => 'Umrholo ubanjwe ngenca yemininingwana yebhanga yomnikeli lo. :reason Qinisekisa i-akhawunti kumnikeli ngaphambi kokurhola.',
        ],
    ],
    'addon' => [
        'unavailable' => 'I-Supplier Intelligence & Due Diligence akusiyo ingcenye yalokhu okubhadangisweko. Yengezelela ukuqinisekisa kwerejista, umthelo, ibhanga, ubunikazi, ikweledi neembetho ekuphatheni abanikeli — khuluma noMkhaya ukuyengezelela.',
    ],
    'verdict' => [
        'never_assessed' => 'Ukuphenya okufaneleko akwenziwa kumnikeli lo.',
        'cleared' => 'Ukuphenya okufaneleko kuphumelele — ingozi :score/100, woke amasango acacile.',
        'blocked' => 'Ukuphenya okufaneleko akupheleli — ingozi :score/100, izinto ezingu-:count ezisalindeleko.',
        'sanctions' => 'Ukufana neembetho okusebenzako kutlolwe kumnikeli lo.',
    ],
    'risk' => [
        'assessed' => 'Kuhlolwe: :score ku-100 (:band).',
        'tiered' => 'Umsebenzi lo usezingeni :tier — :label. Ukuhlolwa okutlhogekako kubuyekeziwe.',
        'screened' => 'Kuhlungwe abantu abangu-:subjects. Ukufana okutjha okungu-:new kutlhoga ukuquntwa.',
        'screening_unavailable' => 'Akunamthombo wokuhlunga olungiselelweko, ngalokho akunalitho elihloliweko. Tlola lokho okufumanako eenrherhweni ezigadangisiweko, namkha ulungiselele umthombo ngaphasi kwe-Configure → Data sources.',
        'hit_recorded' => 'Ukufana kutlolwe. Kubalwa ngokuphambene nomnikeli lo bekube kukhona oquntako.',
        'adjudicated' => 'Isiqunto sitlolwe. Angekhe siphakanyiswe godu ngokuhlunga kwakade.',
        'credit_recorded' => 'Umbiko wekweledi uthwebuliwe.',
        'errors' => [
            'reason_required' => 'Tjho bona kubayini ukufana lokhu kususwa. Ukwala okunganahlathululo angekhe kuhlukaniswe nokucindezela iphoso ngemva komnyaka.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Amafomu wemibuzo angu-:n athunyelwe kumnikeli.',
        'recorded' => 'Iimpendulo zibulungwe — :completeness% kupheleleko.',
        'reviewed' => 'Ifomu lemibuzo litjhwaywe njengelibuyekeziweko.',
    ],
    'alerts' => [
        'subject' => '[:severity] Isiyeleliso somnikeli — :supplier',
        'greeting' => 'Lotjhani,',
        'context' => 'I-:supplier kwanjesi isebandeni :band.',
        'action' => 'Vula iphasiphothi yomnikeli',
        'db_title' => 'Isiyeleliso somnikeli: :supplier',
        'routing_saved' => 'Ukukhombisa iimyeleliso kubulungwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Isiqunto sitlolwe. Itjhugululo le angekhe ivele godu ebhoksini elingenako.',
        'swept' => 'Ukuhlolwa okungu-:checks kuphindiwe; iintjhugululo ezingu-:changes zifunyenwe.',
        'no_sources' => 'Akunamthombo olungiselelweko kunanyana ngikuphi ukuhlolwa okufaneleko, ngalokho akunalitho eliphindiweko. Iingaba zitlolwa ngokuphambene nomnikeli esikhundleni sokufihlwa.',
        'errors' => [
            'reason_required' => 'Tjho bona kubayini itjhugululo le ilahlwa. Ngemva komnyaka, ukwala okunganahlathululo angekhe kuhlukaniswe nokucindezela iphoso.',
        ],
    ],
    'cases' => [
        'opened' => 'Ibango :reference livuliwe.',
        'moved' => 'Ibango lidluliselwe ku-:state.',
        'edd_recorded' => 'Into yokuphenya okungezelelweko itlolwe.',
        'blacklisted' => 'Kungezelelwe ererhweni elinzima. Ukungenisa nje kuzokuyelelisa ngesiqhema lesi, ngabantu baso, nangakho koke okuvela ebunikazini baso.',
        'blacklist_lifted' => 'Ukufakwa ererhweni elinzima kususiwe. Umlando uhlala erekhodini.',
        'roles_saved' => 'Iindima zokugunyaza zibulungwe.',
        'errors' => [
            'waiver_reason' => 'Tjho bona kubayini into le idedelwa. Isiphuthulo sokuphenya okungezelelweko esingadedelwa buthule sihlobiso.',
            'blacklist_slot' => 'Ukufaka ererhweni elinzima kusiqunto sabaphathi. Bawa umuntu obekelwe isikhala soMphathi, namkha uzibeke kiso qangi.',
        ],
    ],
    'reputation' => [
        'ran' => 'Kuphenywe imithombo engu-:sources. Okufunyenweko okutjha okungu-:new; okungu-:review akukghonanga ukuhlanganiswa nomnikeli lo begodu kutlhoga ukubuyekezwa.',
        'no_sources' => 'Akunamthombo weendaba namkha wamabandla wokuxhumana olungiselelweko, ngalokho akunalitho eliphenyiweko. Umnikeli ongakghoni ukuphenywa akufuze adidaniswe naloyo okunganafunyenwe litho ngaye — lungiselela umthombo ngaphasi kwe-Configure → Data sources.',
        'adjudicated' => 'Isiqunto sitlolwe. Okufunyenweko lokhu angekhe kuphakanyiswe godu ekusebenzeni kwakade.',
        'handle_added' => 'Kungezelelwe kilokho okuqalwako kumnikeli lo.',
        'handle_removed' => 'Kususwe kilokho okuqalwako.',
        'errors' => [
            'reason_required' => 'Tjho bona kubayini okufunyenweko lokhu kususwa. Ukwala okunganahlathululo angekhe kuhlukaniswe nokucindezela iphoso ngemva komnyaka.',
        ],
    ],
    'ownership' => [
        'added' => 'Isabelo sobunikazi sitlolwe.',
        'updated' => 'Isabelo sobunikazi sibuyekeziwe.',
        'errors' => [
            'self_ownership' => 'Inkampani angekhe iphathe isabelo kiyo ngokwayo. Khetha omunye umphathi, namkha enye inkampani ukuyiphatha.',
            'holder_required' => 'Bita umphathi, namkha khetha umuntu osele asefayeleni.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check itlolwe njenge-:status.',
        'pin_saved' => 'I-TCS PIN ibulungwe. Ubujamo bokuhambisana nomthelo nje bungaqinisekiswa kumnikeli lo.',
        'errors' => [
            'no_source' => 'Akunamthombo olungiselelweko we-:check, ngalokho angekhe kwenziwe ngokuzenzakalela. Tlola ukuhlolwa ngokwakho, namkha lungiselela umthombo ngaphasi kwe-Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Ithole u-:score% maqondana nomkhawulo wokubuyekeza ka-:threshold%. Hlola lo mhlinzeki ngaphambi kokunikeza omunye umsebenzi.',
        'flash' => [
            'template_created' => 'Ikhadi lamaphuzu lenziwe. Ngeza imibuzo okufuze libuze.',
            'template_saved' => 'Ikhadi libulungiwe.',
            'template_removed' => 'Ikhadi lisusiwe.',
            'template_in_use' => 'Ikhadi leli selisetjenziselwe ukuhlola, ngalokho angekhe lisuswe — beka ubujamo balo bube elithethe umhlalaphasi.',
            'question_added' => 'Umbuzo wengeziwe.',
            'question_saved' => 'Umbuzo ubulungiwe.',
            'question_removed' => 'Umbuzo ususiwe.',
            'weight_over' => 'Iinsindo ekhadini leli zingaba ngu-100% zizoke. Kusele u-:remaining% okufuze wabiwe.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Ikhambo leli lesango liphelelwe sikhathi. Bawa umthinteki wakho elitjha.',
    ],
    'budget' => [
        'po_note' => 'I-oda lokuthenga :po',
        'invoice_note' => 'I-invoyisi yomhlinzeki :number',
    ],
    'registration' => [
        'mail_subject' => 'I-:supplier iqedile ukutlolisa kwayo njengomrhwebi',
        'mail_body' => 'I-:supplier izalise ukutlolisa kwayo begodu ilindele ukubuyekezwa.',
        'mail_action' => 'Buyekeza umrhwebi',
        'submitted' => 'Siyathokoza — imininingwana yakho ise-:company bona ibuyekezwe.',
        'link_expired' => 'Ikhambo leli lokutlolisa liphelelwe sikhathi. Bawa umthinteki wakho isimemo esitjha.',
        'already_registered' => 'Isimemo lesi sesisetjenzisiwe ukutlolisa.',
    ],
];
