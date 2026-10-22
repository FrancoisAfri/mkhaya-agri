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

    'IDENTITY' => 'Ubunikazi',
    'CONTRACTOR' => 'Usonkontileka',
    'SAFETY_FILE' => 'Ifayela lokuphepha',
    'COID' => 'COID',
    'INSURANCE' => 'Umshwalense',
    'SITE_APPROVAL' => 'Imvume yendawo',
    'INDUCTION' => 'Ukwethulwa',
    'COMPETENCY' => 'Ikhono',
    'MEDICAL' => 'Ezempilo',
    'ACTIVITY' => 'Ukufaneleka komsebenzi',
    'PPE' => 'Izivikelo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Amadokhumenti',
    'RISK_ASSESSMENT' => 'Ukuhlola ubungozi',
    'METHOD_STATEMENT' => 'Isitatimende sendlela',
    'PERMIT' => 'Imvume yomsebenzi',
    'EQUIPMENT_CERTIFICATION' => 'Isitifiketi sezinsiza',
    'WORKER' => 'Ubunikazi',

    'page' => [
        'title_check' => 'Ukuhlolwa kokungena',
        'title_unknown' => 'Ikhadi elingaziwa',
        'unknown_heading' => 'IKHADI ELINGAZIWA',
        'unknown_lead' => 'Le khodi ayiqashelwa.',
        'unknown_body' => 'Kungenzeka ishintshwe emva kokulahleka kwekhadi, noma ingewale ndawo. Cela ukuthi umuntu akhishelwe ikhadi elisha ngubani ophatha osonkontileka lapha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'KUVUMELEKILE UKUNGENA',
            'DENIED' => 'KWENQATSHIWE UKUNGENA',
            'REVIEW_REQUIRED' => 'AKUKWAZI UKUHLOLWA',
            'UNKNOWN' => 'AKUHLOLIWE',
        ],
        'review_heading' => 'Lokhu akukwazanga kuhlolwa',
        'review_body' => 'Olunye uhlolo alukwazanga lwaqedwa, ngakho lokhu akuyona imvume futhi akuyona ukwenqaba. Ungakunqumi lapha — shayela umuntu ophatha osonkontileka kule ndawo ngaphambi kokuvumela noma ubani angene.',
        'approved' => 'KUVUMELEKILE UKUNGENA',
        'denied' => 'KWENQATSHIWE UKUNGENA',
        'contractor_pass' => 'Ikhadi likasonkontileka',
        'worker_pass' => 'Ikhadi lesisebenzi',
        'verified_against' => 'Kuqinisekiswe ngamarekhodi ka-:company',
        'refused_because' => 'Kwenqatshwe ngenxa yokuthi',
        'checks' => 'Ukuhlolwa',
        'ppe_heading' => 'Izivikelo ezidingekayo kulo msebenzi',
        'ppe_note' => 'Kuhlole kumuntu.',
        'advisory_heading' => 'Kuqoshiwe, akwenqabi ukungena',
        'footer' => 'Kuhlolwe ngo-:at. Le mpendulo iyiqiniso kuleso sikhathi kuphela — ikhodi efanayo ihlolwa futhi njalo lapho iskenwa, ngakho iyashintsha ngokwayo lapho ukuhambisana kushintsha. Lokhu ukuskena kuqoshiwe.',
    ],

    'card' => [
        'title' => 'Ikhadi lokungena esizeni',
        'no_photo' => 'Asikho isithombe',
        'employer' => 'Umqashi',
        'site' => 'Isiza',
        'project' => 'Iphrojekthi',
        'registration' => 'Inombolo yokubhalisa',
        'not_set' => 'Akubekwanga',
        'scan' => 'Skena ukuhlola',
        'back_title' => 'Leli khadi alilona imvume yokusebenza.',
        'back_body' => 'Likhomba umphathi walo. Ukuthi angasebenza yini kuphendulwa ngokuskena ikhodi, njalo, futhi kungashintsha phakathi neshifu. Bika ikhadi elilahlekile ngokushesha — ikhadi elisha lenza leli lingasasebenzi. Liyimpahla yesiza elikhiphile; libuyise uma licelwa.',
        'issued' => 'Likhishwe ngo-:date',
        'valid_until' => 'Isethulo sisebenza kuze kube :date',
        'print_hint' => 'Liprintwa ngo-85.6 × 54 mm — usayizi wekhadi lasebhange. Printa ngo-100% ngaphandle kokunciphisa ikhasi.',
        'print' => 'Printa ikhadi',
    ],

];
