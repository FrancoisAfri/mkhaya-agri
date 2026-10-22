<?php

/**
 * Afrikaanse stringe vir Verskafferbestuur.
 *
 * Generated from the English file, which is the shape of record. Any key not yet
 * translated keeps its English text so the file always works; the untranslated ones are
 * visible in a diff against lang/en/supplier_management.php.
 */
return [

    'statuses' => [
        0 => 'Hangende',
        1 => 'Aktief',
        2 => 'Geweier',
        3 => 'KYC hangende',
    ],
    'suppliers' => [
        'activated' => 'Verskafferaktivering verwerk.',
        'refused' => 'Verskaffer geweier, en ingelig waarom.',
        'info_requested' => 'Versoek om inligting aan die verskaffer gestuur.',
        'portal_link_rotated' => '’n Nuwe portaalskakel is uitgereik. Die vorige skakel werk nie meer nie.',
        'portal_link_reissued' => 'Jou portaalskakel is om sekuriteitsredes vervang. Gebruik die nuwe skakel hieronder.',
        'outstanding_documents' => 'Ons benodig steeds die volgende van jou: :documents',
        'errors' => [
            'duplicate' => 'Hierdie registrasienommer of e-pos behoort reeds aan :supplier (:number). Maak daardie rekord oop eerder as om ’n tweede te skep.',
            'missing_documents' => 'Hierdie verskaffer kan nie geaktiveer word voordat hierdie verpligte dokumente op lêer en geldig is nie: :documents',
            'blacklisted' => 'Hierdie party is op die swartlys — :matched. Rede op rekord: :reason. As dit \'n ander party is, of die verbod behoort opgehef te word, is dit \'n uitvoerende besluit voordat die verskaffer bygevoeg kan word.',
        ],
    ],
    'onboarding' => [
        'invitation_sent' => 'Uitnodiging gestuur. Die vorige skakel, indien enige, werk nie meer nie.',
        'mail_subject' => 'Jy is genooi om as verskaffer by :company te registreer',
        'mail_greeting' => 'Hallo :name,',
        'mail_intro' => ':company wil jou by hul goedgekeurde verskafferlys voeg. Registrasie neem ’n paar minute.',
        'mail_action' => 'Begin registrasie',
        'mail_expiry' => 'Hierdie skakel werk tot :date. As dit verval, vra jou kontak vir ’n nuwe een.',
        'mail_closing' => 'As jy die dokumente hierbo byderhand het voordat jy begin, sal dit vinniger gaan.',
    ],
    'decisions' => [
        'greeting' => 'Hallo :name,',
        'comment_label' => 'Notas van die beoordelaar:',
        'mail_action' => 'Maak jou verskafferportaal oop',
        'mail_closing' => 'Antwoord op hierdie e-pos as iets hier verkeerd lyk.',
        'approved' => [
            'mail_subject' => 'Jy is nou ’n goedgekeurde verskaffer by :company',
            'mail_body' => 'Jou registrasie is goedgekeur. :company kan nou bestellings by jou plaas.',
        ],
        'refused' => [
            'mail_subject' => 'Jou verskafferregistrasie by :company',
            'mail_body' => ':company kan op hierdie stadium nie met jou registrasie voortgaan nie.',
        ],
        'information_requested' => [
            'mail_subject' => ':company benodig meer inligting van jou',
            'mail_body' => 'Jou registrasie is op wag totdat ons ’n bietjie meer van jou ontvang.',
        ],
        'portal_reissued' => [
            'mail_subject' => 'Jou nuwe portaalskakel vir :company',
            'mail_body' => 'Jou portaalskakel is om sekuriteitsredes vervang. Die vorige skakel werk nie meer nie — gebruik voortaan die knoppie hieronder.',
        ],
    ],
    'kyc' => [
        'approved' => 'KYC goedgekeur; die verskaffer is nou aktief.',
        'rejected' => 'KYC geweier, en die verskaffer is ingelig waarom.',
        'mail_subject' => 'KYC-hersiening nodig: :supplier',
        'mail_greeting' => 'Hallo,',
        'mail_body' => ':supplier wag op ’n KYC-besluit.',
        'mail_action' => 'Hersien die KYC-wagry',
        'errors' => [
            'already_decided' => 'Hierdie indiening was reeds :status. Heropen die verskaffer as die besluit heroorweeg moet word.',
            'self_review' => 'Jy het hierdie verskaffer geskep, dus kan jy nie ook sy KYC goedkeur nie. Dit benodig ’n tweede beoordelaar.',
        ],
    ],
    'rfx' => [
        'published' => 'Geleentheid gepubliseer. Genooide verskaffers kan nou bie.',
        'closed' => 'Geleentheid gesluit. Geen verdere bod sal aanvaar word nie.',
        'response_recorded' => 'Bod aangeteken.',
        'evaluated' => 'KI-bodevaluering voltooi.',
        'awarded' => 'Geleentheid toegeken.',
        'statuses' => [
            'draft' => 'konsep',
            'published' => 'gepubliseer',
            'closed' => 'gesluit',
            'awarded' => 'toegeken',
            'cancelled' => 'gekanselleer',
        ],
        'errors' => [
            'invalid_transition' => '’n Geleentheid wat :from is, kan nie na :to geskuif word nie.',
            'publish_without_invitations' => 'Nooi ten minste een verskaffer voordat jy publiseer — ’n geleentheid waarvoor niemand gevra is om te bie nie, kan nie toegeken word nie.',
            'publish_after_closing' => 'Die sluitingsdatum is reeds verby. Skuif dit vorentoe voordat jy publiseer.',
            'bid_when_not_published' => 'Bod kan slegs aangeteken word terwyl ’n geleentheid gepubliseer is. Hierdie een is :status.',
            'bid_after_closing' => 'Hierdie geleentheid het op :date gesluit. ’n Laat bod kan nie aanvaar word nie.',
            'bid_from_uninvited' => ':supplier is nie na hierdie geleentheid genooi nie. Nooi hulle eers as hulle moet deelneem.',
            'award_without_bid' => ':supplier het nie op hierdie geleentheid gebie nie, dus kan dit nie aan hulle toegeken word nie.',
            'award_reason_required' => 'Dit is nie die laagste bod nie, dus benodig die toekenning ’n rede op rekord.',
        ],
    ],
    'orders' => [
        'created' => 'Aankooporder geskep.',
        'updated' => 'Aankooporder bygewerk.',
        'removed' => 'Aankooporder verwyder, en enige fondse wat dit gehou het, is vrygestel.',
        'statuses' => [
            'draft' => 'konsep',
            'approved' => 'goedgekeur',
            'received' => 'ontvang',
            'closed' => 'gesluit',
            'cancelled' => 'gekanselleer',
        ],
        'errors' => [
            'supplier_not_active' => 'Hierdie verskaffer is :status en kan nie nuwe aankooporders ontvang voordat dit heraktiveer is nie.',
            'expired_documents' => 'Hierdie verskaffer het verstreke verpligte dokumente en kan nie nuwe bestellings ontvang nie: :documents',
            'invalid_transition' => '’n :from-bestelling kan nie na :to geskuif word nie.',
            'self_approval' => 'Jy het hierdie bestelling geskep, dus kan jy dit nie ook goedkeur nie. Dit benodig ’n tweede goedkeurder.',
        ],
    ],
    'invoices' => [
        'captured' => 'Faktuur vasgelê.',
        'captured_with_variance' => 'Faktuur vasgelê, maar dit is :variance meer as aankooporder :po. Dit word vir hersiening gehou en kan nie betaal word voordat dit opgelos is nie.',
        'updated' => 'Faktuur bygewerk.',
        'removed' => 'Faktuur verwyder, en die besteding van die begroting afgehaal.',
        'errors' => [
            'duplicate_number' => 'Faktuur :number is reeds vir hierdie verskaffer vasgelê. Maak die bestaande een oop eerder as om dit twee keer in te voer.',
            'order_belongs_to_other_supplier' => 'Aankooporder :po is vir ’n ander verskaffer geskep, dus kan hierdie faktuur nie daarmee gepaar word nie.',
            'pay_with_open_variance' => 'Hierdie faktuur oorskry sy aankooporder en word vir hersiening gehou. Los die verskil op voordat jy dit as betaal merk.',
            'pay_with_unverified_banking' => 'Betaling word opgehou op hierdie verskaffer se bankbesonderhede. :reason Verifieer die rekening teen die verskaffer voordat u betaal.',
        ],
    ],
    'addon' => [
        'unavailable' => 'Verskaffer-intelligensie en Omsigtigheidsondersoek is nie deel van hierdie intekening nie. Dit voeg register-, belasting-, bank-, eienaarskap-, krediet- en sanksieverifikasie by verskafferbestuur — praat met Mkhaya om dit by te voeg.',
    ],
    'verdict' => [
        'never_assessed' => 'Omsigtigheidsondersoek is nog nie op hierdie verskaffer uitgevoer nie.',
        'cleared' => 'Omsigtigheidsondersoek geslaag — risiko :score/100, elke hek skoon.',
        'blocked' => 'Omsigtigheidsondersoek onvolledig — risiko :score/100, :count uitstaande item(s).',
        'sanctions' => '\'n Lewendige sanksie-ooreenstemming is teen hierdie verskaffer aangeteken.',
    ],
    'risk' => [
        'assessed' => 'Geassesseer: :score uit 100 (:band).',
        'tiered' => 'Hierdie aanstelling is vlak :tier — :label. Die nagaan wat dit vereis, is bygewerk.',
        'screened' => ':subjects persoon(e) gesif. :new nuwe ooreenstemming(s) moet beoordeel word.',
        'screening_unavailable' => 'Geen siftingsbron is opgestel nie, dus is niks nagegaan nie. Teken aan wat u op die gepubliseerde lyste vind, of stel \'n bron op onder Konfigureer → Databronne.',
        'hit_recorded' => 'Ooreenstemming aangeteken. Dit tel teen hierdie verskaffer totdat iemand dit beoordeel.',
        'adjudicated' => 'Besluit aangeteken. Dit sal nie weer deur \'n latere sifting geopper word nie.',
        'credit_recorded' => 'Kredietverslag vasgelê.',
        'errors' => [
            'reason_required' => 'Sê waarom hierdie ooreenstemming skoongemaak word. \'n Onverklaarde afwysing kan oor \'n jaar nie van \'n verkeerde klik onderskei word nie.',
        ],
    ],
    'questionnaires' => [
        'sent' => ':n vraelys(te) aan die verskaffer gestuur.',
        'recorded' => 'Antwoorde gestoor — :completeness% volledig.',
        'reviewed' => 'Vraelys as nagegaan gemerk.',
    ],
    'alerts' => [
        'subject' => '[:severity] Verskafferwaarskuwing — :supplier',
        'greeting' => 'Hallo,',
        'context' => ':supplier is tans in band :band.',
        'action' => 'Maak die verskafferpaspoort oop',
        'db_title' => 'Verskafferwaarskuwing: :supplier',
        'routing_saved' => 'Waarskuwingsroetering gestoor.',
    ],
    'monitoring' => [
        'change_reviewed' => 'Besluit aangeteken. Hierdie verandering sal nie weer in die inkassie verskyn nie.',
        'swept' => ':checks nagaan weer uitgevoer; :changes verandering(s) opgespoor.',
        'no_sources' => 'Geen bron is vir enige van die nagaan wat verskuldig was opgestel nie, dus is niks weer uitgevoer nie. Die gapings word teen die verskaffer aangeteken eerder as weggesteek.',
        'errors' => [
            'reason_required' => 'Sê waarom hierdie verandering verwerp word. Oor \'n jaar kan \'n onverklaarde afwysing nie van \'n verkeerde klik onderskei word nie.',
        ],
    ],
    'cases' => [
        'opened' => 'Saak :reference geopen.',
        'moved' => 'Saak na :state geskuif.',
        'edd_recorded' => 'Verhoogde omsigtigheidsitem aangeteken.',
        'blacklisted' => 'By die swartlys gevoeg. Aanboording sal nou oor hierdie party waarsku, oor sy mense, en oor enigiets waarin dit in die eienaarskap verskyn.',
        'blacklist_lifted' => 'Swartlysinskrywing opgehef. Die geskiedenis bly op rekord.',
        'roles_saved' => 'Goedkeuringsrolle gestoor.',
        'errors' => [
            'waiver_reason' => 'Sê waarom hierdie item afgesien word. \'n Verhoogde omsigtigheidspak wat stilweg afgesien kan word, is versiering.',
            'blacklist_slot' => 'Swartlysting is \'n uitvoerende besluit. Vra iemand wat aan die Uitvoerende slot toegewys is, of wys eers uself daaraan toe.',
        ],
    ],
    'reputation' => [
        'ran' => ':sources bron(ne) deursoek. :new nuwe bevinding(s); :review kon nie aan hierdie verskaffer verbind word nie en moet nagegaan word.',
        'no_sources' => 'Geen nuus- of sosiale bron is opgestel nie, dus is niks deursoek nie. \'n Verskaffer wat niemand kon deursoek nie, moet nie verwar word met een waaroor niks gevind is nie — stel \'n bron op onder Konfigureer → Databronne.',
        'adjudicated' => 'Besluit aangeteken. Hierdie bevinding sal nie weer deur \'n latere lopie geopper word nie.',
        'handle_added' => 'By wat vir hierdie verskaffer dopgehou word, gevoeg.',
        'handle_removed' => 'Verwyder van wat dopgehou word.',
        'errors' => [
            'reason_required' => 'Sê waarom hierdie bevinding skoongemaak word. \'n Onverklaarde afwysing kan oor \'n jaar nie van \'n verkeerde klik onderskei word nie.',
        ],
    ],
    'ownership' => [
        'added' => 'Eienaarskapsbelang aangeteken.',
        'updated' => 'Eienaarskapsbelang bygewerk.',
        'errors' => [
            'self_ownership' => '\'n Maatskappy kan nie \'n belang in homself hou nie. Kies \'n ander houer, of \'n ander maatskappy om te hou.',
            'holder_required' => 'Noem die houer, of kies iemand wat reeds op lêer is.',
        ],
    ],
    'verifications' => [
        'ran' => ':check: :status.',
        'recorded' => ':check aangeteken as :status.',
        'pin_saved' => 'TCS-PIN gestoor. Belastingnakomingstatus kan nou vir hierdie verskaffer geverifieer word.',
        'errors' => [
            'no_source' => 'Geen bron is vir :check opgestel nie, dus kan dit nie outomaties uitgevoer word nie. Teken die nagaan self aan, of stel \'n bron op onder Konfigureer → Databronne.',
        ],
    ],
    'appraisals' => [
        'poor_score_flag' => 'Het :score% behaal teen ’n hersieningsdrempel van :threshold%. Kontroleer hierdie verskaffer voordat jy verdere werk plaas.',
        'flash' => [
            'template_created' => 'Puntekaart geskep. Voeg die vrae by wat dit moet vra.',
            'template_saved' => 'Puntekaart gestoor.',
            'template_removed' => 'Puntekaart verwyder.',
            'template_in_use' => 'Hierdie puntekaart is vir ’n beoordeling gebruik en kan nie geskrap word nie — stel die status eerder op afgetree.',
            'question_added' => 'Vraag bygevoeg.',
            'question_saved' => 'Vraag gestoor.',
            'question_removed' => 'Vraag verwyder.',
            'weight_over' => 'Die gewigte op hierdie puntekaart mag altesaam 100% wees. Daar is :remaining% oor om toe te ken.',
        ],
    ],
    'portal' => [
        'link_expired' => 'Hierdie portaalskakel het verval. Vra jou kontak vir ’n nuwe een.',
    ],
    'budget' => [
        'po_note' => 'Aankooporder :po',
        'invoice_note' => 'Verskafferfaktuur :number',
    ],
    'registration' => [
        'mail_subject' => ':supplier het hul verskafferregistrasie voltooi',
        'mail_body' => ':supplier het hul registrasie ingevul en wag om hersien te word.',
        'mail_action' => 'Hersien die verskaffer',
        'submitted' => 'Dankie — jou besonderhede is by :company vir hersiening.',
        'link_expired' => 'Hierdie registrasieskakel het verval. Vra jou kontak vir ’n nuwe uitnodiging.',
        'already_registered' => 'Hierdie uitnodiging is reeds gebruik om te registreer.',
    ],
];
