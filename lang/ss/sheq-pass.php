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

    'IDENTITY' => 'Bunikati',
    'CONTRACTOR' => 'Umcondrakhi',
    'SAFETY_FILE' => 'Lifayela lekuphepha',
    'COID' => 'COID',
    'INSURANCE' => 'Inshorensi',
    'SITE_APPROVAL' => 'Imvumo yendzawo',
    'INDUCTION' => 'Kwetfulwa',
    'COMPETENCY' => 'Likhono',
    'MEDICAL' => 'Tekwelapha',
    'ACTIVITY' => 'Kufanelana nemsebenti',
    'PPE' => 'Tivikelo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Emadokhumente',
    'RISK_ASSESSMENT' => 'Kuhlola buphahla',
    'METHOD_STATEMENT' => 'Sitatimende sendlela',
    'PERMIT' => 'Imvume yemsebenti',
    'EQUIPMENT_CERTIFICATION' => 'Sitifikethi setitsiva',
    'WORKER' => 'Bunikati',

    'page' => [
        'title_check' => 'Kuhlolwa kwekungena',
        'title_unknown' => 'Likhadi lelingatiwa',
        'unknown_heading' => 'LIKHADI LELINGATIWA',
        'unknown_lead' => 'Leli khodi alikatiwa.',
        'unknown_body' => 'Kungenteka lishintjiwe ngemuva kwekulahleka kwelikhadi, nome alisiyalendzawo. Cela kutsi umuntfu akhishelwe likhadi lelisha ngulowo lophatsa bocondrakhi lapha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'KUVUMELEKILE KUNGENA',
            'DENIED' => 'KWENCATJWE KUNGENA',
            'REVIEW_REQUIRED' => 'AKUKWATI KUHLOLWA',
            'UNKNOWN' => 'AKUHLOLIWE',
        ],
        'review_heading' => 'Loku akukwatanga kuhlolwa',
        'review_body' => 'Lolunye kuhlolwa alukwatanga lucedzwa, ngako loku akusiyo imvume futsi akusiko kwencabana. Ungakunqumi lapha — shayela umuntfu lophetse boskontileka kulendzawo ngaphambi kokuvumela nome ngubani angene.',
        'approved' => 'KUVUMELEKILE KUNGENA',
        'denied' => 'KWALIWE KUNGENA',
        'contractor_pass' => 'Likhadi lemcondrakhi',
        'worker_pass' => 'Likhadi lesisebenti',
        'verified_against' => 'Kucinisekisiwe ngemarekhodi ya-:company',
        'refused_because' => 'Kwaliwe ngenca yekutsi',
        'checks' => 'Kuhlolwa',
        'ppe_heading' => 'Tivikelo letidingekako kulomsebenti',
        'ppe_note' => 'Kuhlole kumuntfu.',
        'advisory_heading' => 'Kubhalwe, akwali kungena',
        'footer' => 'Kuhlolwe nga-:at. Lemphendulo iliciniso kuleso sikhatsi kuphela — likhodi lelifanako lihlolwa futsi njalo nangabe liskenwa, ngako liyashintja ngekwalo nangabe kuhambisana kushintja. Loku kuskena kubhaliwe.',
    ],

    'card' => [
        'title' => 'Likhadi lekungena endzaweni yemsebenti',
        'no_photo' => 'Akukho sitfombe',
        'employer' => 'Umcashi',
        'site' => 'Indzawo yemsebenti',
        'project' => 'Iphrojekthi',
        'registration' => 'Inombolo yekubhalisa',
        'not_set' => 'Akubekwanga',
        'scan' => 'Skena kute uhlole',
        'back_title' => 'Leli khadi akusiyo imvume yekusebenta.',
        'back_body' => 'Likhomba umphatsi walo. Kutsi angasebenta nome cha kuphendulwa ngekuskena likhodi, njalo, futsi kungashintja emkhatsini weshifu. Bika likhadi lelilahlekile ngekushesha — likhadi lelisha lenta leli lingasebenti. Kuyimphahla yendzawo leyilikhiphile; libuyise nangabe licelwa.',
        'issued' => 'Likhishwe nge-:date',
        'valid_until' => 'Kwetfulwa kusebenta kuze kube :date',
        'print_hint' => 'Liphrintwa nge-85.6 × 54 mm — bukhulu bekhadi lasebhange. Phrinta nge-100% ngaphandle kwekuncipisa likhasi.',
        'print' => 'Phrinta likhadi',
    ],

];
