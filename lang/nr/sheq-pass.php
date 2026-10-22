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
    'CONTRACTOR' => 'Umkontraga',
    'SAFETY_FILE' => 'Ifayela lokuphepha',
    'COID' => 'COID',
    'INSURANCE' => 'I-inshorense',
    'SITE_APPROVAL' => 'Imvumo yendawo',
    'INDUCTION' => 'Ukwethulwa',
    'COMPETENCY' => 'Ikghono',
    'MEDICAL' => 'Ezamaphilo',
    'ACTIVITY' => 'Ukufanelana nomsebenzi',
    'PPE' => 'Izivikelo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Amatlhogomelo',
    'RISK_ASSESSMENT' => 'Ukuhlola ubungozi',
    'METHOD_STATEMENT' => 'Isitatimende sendlela',
    'PERMIT' => 'Imvume yomsebenzi',
    'EQUIPMENT_CERTIFICATION' => 'Isitifikeyiti seensetjenziswa',
    'WORKER' => 'Ubunikazi',

    'page' => [
        'title_check' => 'Ukuhlolwa kokungena',
        'title_unknown' => 'Ikarada elingaziwako',
        'unknown_heading' => 'IKARADA ELINGAZIWAKO',
        'unknown_lead' => 'Ikhowudi le ayikaziwa.',
        'unknown_body' => 'Kungenzeka itjhugululwe ngemva kokulahleka kwekarada, namkha ayisiyendawo le. Bawa bona umuntu akhutjhelwe ikarada elitjha ngiloyo ophatha abakontraga lapha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'KUVUMELEKILE UKUNGENA',
            'DENIED' => 'KWANQATJHWA UKUNGENA',
            'REVIEW_REQUIRED' => 'AKUKWAZI UKUHLOLWA',
            'UNKNOWN' => 'AKUHLOLWANGA',
        ],
        'review_heading' => 'Lokhu akukwazanga kuhlolwa',
        'review_body' => 'Okhunye ukuhlolwa akukwazanga ukuqedwa, ngalokho lokhu akusiyo imvume begodu akusikho ukwala. Ungakunqumi lapha — tjhayela umuntu ophatha abosonkontileka endaweni le ngaphambi kobana uvumele umuntu angene.',
        'approved' => 'KUVUMELEKILE UKUNGENA',
        'denied' => 'KWALIWE UKUNGENA',
        'contractor_pass' => 'Ikarada lomkontraga',
        'worker_pass' => 'Ikarada lesisebenzi',
        'verified_against' => 'Kuqinisekisiwe ngamarekhodi we-:company',
        'refused_because' => 'Kwaliwe ngombana',
        'checks' => 'Ukuhlolwa',
        'ppe_heading' => 'Izivikelo ezitlhogekako kumsebenzi lo',
        'ppe_note' => 'Kuhlole emuntwini.',
        'advisory_heading' => 'Kutlolwe, akwali ukungena',
        'footer' => 'Kuhlolwe nge-:at. Ipendulo le iliqiniso ngesikhathi eso kwaphela — ikhowudi efanako ihlolwa godu nangamunye isikhathi iskenwa, ngalokho iyatjhuguluka ngokwayo nange ukuhambisana kutjhuguluka. Ukuskena lokhu kutlolwe.',
    ],

    'card' => [
        'title' => 'Ikarada yokungena endaweni yomsebenzi',
        'no_photo' => 'Akunasithombe',
        'employer' => 'Umqatjhi',
        'site' => 'Indawo yomsebenzi',
        'project' => 'Iphrojekthi',
        'registration' => 'Inomboro yokutlolisa',
        'not_set' => 'Akubekwa',
        'scan' => 'Skena ukuhlola',
        'back_title' => 'Ikarada le ayisimvume yokusebenza.',
        'back_body' => 'Itjengisa umniniyo. Ukuthi angasebenza namkha akunjalo kuphendulwa ngokuskena ikhodi, qobe, begodu kungatjhuguluka hlangana neshifu. Bika ikarada elahlekileko msinyana — ikarada etjha yenza le ingasasebenzi. Kuyipahla yendawo eyikhuphileko; ibuyise nayicelwa.',
        'issued' => 'Ikhutjhwe nge-:date',
        'valid_until' => 'Ifundiso isebenza bekube :date',
        'print_hint' => 'Iprintwa nge-85.6 × 54 mm — ubukhulu bekarada lebhange. Printa nge-100% ngaphandle kokunciphisa ikhasi.',
        'print' => 'Printa ikarada',
    ],

];
