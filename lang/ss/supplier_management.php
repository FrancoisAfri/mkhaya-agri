<?php

/**
 * Emavi esiSwati ekuPhatfwa kwebaHlinteki.
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
        'activated' => 'Kuvulwa kwemhlinteki kucedziwe.',
        'refused' => 'Umhlinteki wentiwe, futsi utjeliwe kutsi kungani.',
        'info_requested' => 'Sicelo selwati sitfunyelwe kumhlinteki.',
        'portal_link_rotated' => 'Isixhumanisi sesango esisha sikhishiwe. Isixhumanisi sangaphambilini asisasebenzi.',
        'portal_link_reissued' => 'Isixhumanisi sakho sesango sishintshiwe ngenxa yokuphepha. Sebenzisa isixhumanisi esisha esingezansi.',
        'outstanding_documents' => 'Sisadzinga loku lokulandzelako kuwe: :documents',
        'errors' => [
            'duplicate' => 'Le nombolo yokubhalisa noma i-imeyili isivele ingekaka-:supplier (:number). Vula lelo rekhodi esikhundleni sokudala elesibili.',
            'missing_documents' => 'Lo mhlinzeki ngeke enziwe asebenze kuze kube yilapho la mabhuku ayimpoqo esefayeleni futhi esalungile: :documents',
            'blacklisted' => 'Leli licembu lisehlwini lelimnyama — :matched. Sizatfu lesibhalwe: :reason. Nangabe leli licembu lelehlukile, nome sivimbo kufanele sisuswe, loko kusincumo sebaphatsi ngaphambi kwekutsi umhlinzeki angetwe.',
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
            'mail_subject' => 'Luchumano lwakho lolusha lwelisango lwe-:company',
            'mail_body' => 'Luchumano lwakho lwelisango lushintjiwe ngenca yekuphepha. Luchumano lwangaphambilini alusasebenti — sebentisa inkhinobho lengentansi kusukela nyalo.',
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
            'self_review' => 'Nguwe lowenta lomhlinteki, ngako ngeke uphindze uvumele i-KYC yakhe. Kudzinga umbuyeketi wesibili.',
        ],
    ],
    'rfx' => [
        'published' => 'Umcimbi ushicilelwe. Bahlinteki labamenyiwe sebangafaka emabhidi.',
        'closed' => 'Umcimbi uvaliwe. Awekho amanye amabhidi azokwamukelwa.',
        'response_recorded' => 'Libhidi lirekhodiwe.',
        'evaluated' => 'Ukuhlolwa kwebhidi kwe-AI kuqediwe.',
        'awarded' => 'Umcimbi uniketiwe.',
        'statuses' => [
            'draft' => 'uhlaka',
            'published' => 'kushicilelwe',
            'closed' => 'kuvaliwe',
            'awarded' => 'kuniketiwe',
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
        'created' => 'I-oda yekutsenga yentiwe.',
        'updated' => 'I-oda yekutsenga ibuyeketiwe.',
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
        'captured' => 'I-invoyisi itsatsiwe.',
        'captured_with_variance' => 'I-invoyisi ithwetshuliwe, kodwa ingu-:variance ngaphezu kwe-oda lokuthenga :po. Ibanjelwe ukubuyekezwa futhi ngeke ikhokhwe kuze kube yilapho lokho kuxazululiwe.',
        'updated' => 'I-invoyisi ibuyeketiwe.',
        'removed' => 'I-invoyisi isusiwe, futhi izindleko zibuyiselwe emuva esabelweni.',
        'errors' => [
            'duplicate_number' => 'I-invoyisi :number isivele ithwetshuliwe kulo mhlinzeki. Vula ekhona kunokuyifaka kabili.',
            'order_belongs_to_other_supplier' => 'I-oda lokuthenga :po lakhishelwa omunye umhlinzeki, ngakho le nvoyisi ngeke ihambisane nalo.',
            'pay_with_open_variance' => 'Le nvoyisi ingaphezu kwe-oda layo lokuthenga futhi ibanjelwe ukubuyekezwa. Xazulula umehluko ngaphambi kokuyimaka njengekhokhiwe.',
            'pay_with_unverified_banking' => 'Inkhokhelo ibanjwe ngenca yemininingwane yelibhange yalomhlinzeki. :reason Cinisekisa i-akhawunti kumhlinzeki ngaphambi kwekukhokha.',
        ],
    ],
    'addon' => [
        'unavailable' => 'I-Supplier Intelligence & Due Diligence ayisiyo incenye yaloku lokubhalisiwe. Yengeta kucinisekisa kwelirejista, intela, libhange, bunikati, likhwelede netijeziso ekuphatseni bahlinzeki — khuluma naMkhaya kuyengeta.',
    ],
    'verdict' => [
        'never_assessed' => 'Kuphenya lokufanele akwentiwa kulomhlinzeki.',
        'cleared' => 'Kuphenya lokufanele kuphumelele — ingoti :score/100, onkhe emasango acacile.',
        'blocked' => 'Kuphenya lokufanele akuphelele — ingoti :score/100, tintfo letingu-:count letisalindile.',
        'sanctions' => 'Kufanana netijeziso lokusebentako kubhaliwe kulomhlinzeki.',
    ],
    'risk' => [
        'assessed' => 'Kuhlolwe: :score ku-100 (:band).',
        'tiered' => 'Lomsebenti usezingeni :tier — :label. Kuhlolwa lokudingekako kubuketiwe.',
        'screened' => 'Kuhlungwe bantfu labangu-:subjects. Kufanana lokusha lokungu-:new kudinga kuncunywa.',
        'screening_unavailable' => 'Akukho mtsombo wekuhlunga lolungiselelwe, ngako akukho lokuhlolwe. Bhala loko lokutfolako etinhlwini letishicilelwe, nome ulungiselele umtsombo ngaphansi kwe-Configure → Data sources.',
        'hit_recorded' => 'Kufanana kubhaliwe. Kubalwa ngekumelene nalomhlinzeki kuze kube kukhona lokuncumako.',
        'adjudicated' => 'Sincumo sibhaliwe. Angeke siphakanyiswe futsi ngekuhlunga kwakamuva.',
        'credit_recorded' => 'Umbiko welikhwelede utfwebuliwe.',
        'errors' => [
            'reason_required' => 'Sho kutsi kungani loku kufanana kususwa. Kwala lokungachazwanga angeke kwehlukaniswe nekucindzetela liphutsa ngemuva kwemnyaka.',
        ],
    ],
    'questionnaires' => [
        'sent' => 'Emafomu yemibuto langu-:n atsunyelwe kumhlinzeki.',
        'recorded' => 'Timphendvulo tigcinwe — :completeness% kuphelele.',
        'reviewed' => 'Lifomu lemibuto libhalwe njengalelibuketiwe.',
    ],
    'alerts' => [
        'subject' => '[:severity] Secwayiso semhlinzeki — :supplier',
        'greeting' => 'Sawubona,',
        'context' => 'I-:supplier njengamanje isebandleni :band.',
        'action' => 'Vula liphasiphothi lemhlinzeki',
        'db_title' => 'Secwayiso semhlinzeki: :supplier',
        'routing_saved' => 'Kucondzisa tecwayiso kugcinwe.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Sincumo sibhaliwe. Lesishintjo angeke sivele futsi ebhokisini lelingenako.',
        'swept' => 'Kuhlolwa lokungu-:checks kuphindziwe; tingucuko letingu-:changes titfolakele.',
        'no_sources' => 'Akukho mtsombo lolungiselelwe kunome ngukuphi kuhlolwa lokufanele, ngako akukho lokuphindziwe. Tikhala tibhalwa ngekumelene nemhlinzeki esikhundleni sekufihlwa.',
        'errors' => [
            'reason_required' => 'Sho kutsi kungani lesishintjo sichitfwa. Ngemuva kwemnyaka, kwala lokungachazwanga angeke kwehlukaniswe nekucindzetela liphutsa.',
        ],
    ],
    'cases' => [
        'opened' => 'Licala :reference livuliwe.',
        'moved' => 'Licala lendluliselwe ku-:state.',
        'edd_recorded' => 'Intfo yekuphenya lokwengetiwe ibhaliwe.',
        'blacklisted' => 'Kwengetwe ehlwini lelimnyama. Kungenisa manje kutawecwayisa ngalelicembu, ngebantfu balo, nangako konkhe lokuvela ebunikatini balo.',
        'blacklist_lifted' => 'Kufakwa ehlwini lelimnyama kususiwe. Umlandvo uhlala elirekhodini.',
        'roles_saved' => 'Tindima tekugunyata tigcinwe.',
        'errors' => [
            'waiver_reason' => 'Sho kutsi kungani lentfo idedelwa. Liphakethe lekuphenya lokwengetiwe lelingadedelwa buthule lisihlobiso.',
            'blacklist_slot' => 'Kufaka ehlwini lelimnyama kusincumo sebaphatsi. Cela umuntfu lofakwe esikhaleni seMphatsi, nome utifake kuso kucala.',
        ],
    ],
    'reputation' => [
        'ran' => 'Kucwaningwe imitsombo lengu-:sources. Lokutfolakele lokusha lokungu-:new; lokungu-:review akukhonanga kuhlunyaniswa nalomhlinzeki futsi kudinga kubuketwa.',
        'no_sources' => 'Akukho mtsombo wetindaba nome wetinkhundla tekuchumana lolungiselelwe, ngako akukho lokucwaningiwe. Umhlinzeki longakhoni kucwaningwa akumele adidaniswe nalowo lokungatfolakalanga lutfo ngaye — lungiselela umtsombo ngaphansi kwe-Configure → Data sources.',
        'adjudicated' => 'Sincumo sibhaliwe. Loku lokutfolakele angeke kuphakanyiswe futsi ekusebenteni kwakamuva.',
        'handle_added' => 'Kwengetwe kuloko lokubhekwako kulomhlinzeki.',
        'handle_removed' => 'Kususwe kuloko lokubhekwako.',
        'errors' => [
            'reason_required' => 'Sho kutsi kungani loku lokutfolakele kususwa. Kwala lokungachazwanga angeke kwehlukaniswe nekucindzetela liphutsa ngemuva kwemnyaka.',
        ],
    ],
    'ownership' => [
        'added' => 'Sabelo sebunikati sibhaliwe.',
        'updated' => 'Sabelo sebunikati sibuketiwe.',
        'errors' => [
            'self_ownership' => 'Inkhampani angeke iphatse sabelo kuyo ngekwayo. Khetsa lomunye umphatsi, nome lenye inkhampani kuyiphatsa.',
            'holder_required' => 'Bita umphatsi, nome khetsa umuntfu losevele asefayeleni.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check ibhalwe njenge-:status.',
        'pin_saved' => 'I-TCS PIN igcinwe. Simo sekuhambisana nentela manje singacinisekiswa kulomhlinzeki.',
        'errors' => [
            'no_source' => 'Akukho mtsombo lolungiselelwe we-:check, ngako angeke kwentiwe ngekutentakalela. Bhala kuhlolwa ngekwakho, nome lungiselela umtsombo ngaphansi kwe-Configure → Data sources.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Ithole u-:score% maqondana nomkhawulo wokubuyekeza ka-:threshold%. Hlola lo mhlinzeki ngaphambi kokunikeza omunye umsebenzi.',
        'flash' => [
            'template_created' => 'Likhadi lemaphuzu lidaliwe. Ngeta imibuto lokufanele libute.',
            'template_saved' => 'Likhadi ligciniwe.',
            'template_removed' => 'Likhadi lisusiwe.',
            'template_in_use' => 'Leli khadi selisetjentiswe ekuhloleni, ngako alikhoni kususwa — beka simo salo kube lelitsatse umhlalaphansi.',
            'question_added' => 'Umbuto wengetiwe.',
            'question_saved' => 'Umbuto ugciniwe.',
            'question_removed' => 'Umbuto ususiwe.',
            'weight_over' => 'Tisindvo kuleli khadi tingaba ngu-100% totonkhe. Kusele :remaining% lokufanele kwabiwe.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Lolu luchumano lwelisango luphelelwe sikhatsi. Cela umtsintjwa wakho lolusha.',
    ],
    'budget' => [
        'po_note' => 'I-oda lokuthenga :po',
        'invoice_note' => 'I-invoyisi yomhlinzeki :number',
    ],
    'registration' => [
        'mail_subject' => 'I-:supplier icedzile kubhalisa kwayo njengemhlinteki',
        'mail_body' => 'I-:supplier igcwalisile kubhalisa kwayo futsi ilindze kubuyeketwa.',
        'mail_action' => 'Buyeketa umhlinteki',
        'submitted' => 'Siyabonga — imininingwane yakho ise-:company kuze ibuyeketwe.',
        'link_expired' => 'Lolu luchumano lwekubhalisa luphelelwe sikhatsi. Cela umtsintjwa wakho simemo lesisha.',
        'already_registered' => 'Lesi simemo sesisetjentisiwe kubhalisa.',
    ],
];
