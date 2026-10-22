<?php

/*
 * Labels for the scanned access pass page (CON-12, CON-13).
 *
 * In a translation file rather than the Vue locales because the verification page is plain
 * server-rendered HTML — it has to load on a guard's phone in one request, with no bundle. The
 * check keys are the keys the service returns, so a check added without a label fails the test
 * that asserts every one of them resolves.
 *
 * The guard scanning this has no session, so the language comes from their phone's
 * Accept-Language via SetLocale. That is the only place in the product where the reader's own
 * device chooses the language, which is why the page chrome is translated here too rather than
 * left in the Blade template.
 */
return [

    'IDENTITY' => 'Isazisi',
    'CONTRACTOR' => 'Umqhubi wekhontrakthi',
    'SAFETY_FILE' => 'Ifayile yokhuseleko',
    'COID' => 'COID',
    'INSURANCE' => 'I-inshorensi',
    'SITE_APPROVAL' => 'Imvume yendawo',
    'INDUCTION' => 'Ukwaziswa',
    'COMPETENCY' => 'Ubuchule',
    'MEDICAL' => 'Ezonyango',
    'ACTIVITY' => 'Ukufaneleka komsebenzi',
    'PPE' => 'Izixhobo zokhuselo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Amaxwebhu',
    'RISK_ASSESSMENT' => 'Uvavanyo lomngcipheko',
    'METHOD_STATEMENT' => 'Ingxelo yendlela',
    'PERMIT' => 'Imvume yomsebenzi',
    'EQUIPMENT_CERTIFICATION' => 'Isatifikethi sezixhobo',
    'WORKER' => 'Ubuqu',

    'page' => [
        'title_check' => 'Uhlolo lokufikelela',
        'title_unknown' => 'Ikhadi elingaziwayo',
        'unknown_heading' => 'IKHADI ELINGAZIWAYO',
        'unknown_lead' => 'Le khowudi ayaziwa.',
        'unknown_body' => 'Inokuba itshintshiwe emva kokulahleka kwekhadi, okanye ayingeyale ndawo. Cela ukuba umntu akhutshelwe ikhadi elitsha ngulowo ulawula abaqhubi bekhontrakthi apha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'UFIKELELO LUVUMELEKILE',
            'DENIED' => 'UFIKELELO LUKHANYELWE',
            'REVIEW_REQUIRED' => 'AYINAKUJONGWA',
            'UNKNOWN' => 'AYIJONGWANGA',
        ],
        'review_heading' => 'Oku akukwazanga kujongwa',
        'review_body' => 'Olunye uvavanyo alukwazanga lugqibezelwa, ngoko oku akuyomvume kwaye akuyokwaliwa. Ungagqibi apha — fowunela umntu olawula oosokontraka kule ndawo ngaphambi kokuvumela nabani na.',
        'approved' => 'UFIKELELO LUVUMELEKILE',
        'denied' => 'UFIKELELO LWALIWE',
        'contractor_pass' => 'Ikhadi lomqhubi wekhontrakthi',
        'worker_pass' => 'Ikhadi lomsebenzi',
        'verified_against' => 'Kuqinisekiswe ngeerekhodi zika-:company',
        'refused_because' => 'Kwaliwe ngenxa yokuba',
        'checks' => 'Uhlolo',
        'ppe_heading' => 'Izixhobo zokhuselo ezifunekayo kulo msebenzi',
        'ppe_note' => 'Yihlole kumntu.',
        'advisory_heading' => 'Kuqwalaselwe, akwaleli ukungena',
        'footer' => 'Kuhlolwe nge-:at. Le mpendulo iyinyani ngelo xesha kuphela — ikhowudi efanayo ihlolwa kwakhona ngalo lonke ixesha iskeniweyo, ngoko iyatshintsha ngokwayo xa uthobelo lutshintsha. Olu skeni lubhalwe.',
    ],

    'card' => [
        'title' => 'Ikhadi lokungena kwindawo yomsebenzi',
        'no_photo' => 'Akukho mfanekiso',
        'employer' => 'Umqeshi',
        'site' => 'Indawo yomsebenzi',
        'project' => 'Iprojekthi',
        'registration' => 'Inombolo yobhaliso',
        'not_set' => 'Akubekwanga',
        'scan' => 'Skena ukuze ujonge',
        'back_title' => 'Eli khadi ayilo mvume yokusebenza.',
        'back_body' => 'Libonisa umniniyo. Ukuba angasebenza na kuphendulwa ngokuskena ikhowudi, ngalo lonke ixesha, kwaye kungatshintsha phakathi kwetshifu. Xela ikhadi elilahlekile ngoko nangoko — ikhadi elitsha lenza eli lingasebenzi. Lelempahla yendawo eyilikhuphileyo; libuyise xa licelwa.',
        'issued' => 'Likhutshwe nge-:date',
        'valid_until' => 'Unyuselo lusebenza kude kube :date',
        'print_hint' => 'Liprintwa nge-85.6 × 54 mm — ubungakanani bekhadi lebhanki. Printa nge-100% ngaphandle kokucutha iphepha.',
        'print' => 'Printa ikhadi',
    ],

];
