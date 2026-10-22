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

    'IDENTITY' => 'Vutivi bya munhu',
    'CONTRACTOR' => 'Nkontiraka',
    'SAFETY_FILE' => 'Fayili ya nsirhelelo',
    'COID' => 'COID',
    'INSURANCE' => 'Inshorense',
    'SITE_APPROVAL' => 'Mpfumelelo wa ndhawu',
    'INDUCTION' => 'Dyondzo ya nsirhelelo',
    'COMPETENCY' => 'Vutivi bya ntirho',
    'MEDICAL' => 'Nkambelo wa rihanyu',
    'ACTIVITY' => 'Ku fanelana na ntirho',
    'PPE' => 'Swiavelo swa nsirhelelo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Matsalwa',
    'RISK_ASSESSMENT' => 'Kamberelo ya khombo',
    'METHOD_STATEMENT' => 'Xiviko xa ndlela',
    'PERMIT' => 'Pfumelelo ya ntirho',
    'EQUIPMENT_CERTIFICATION' => 'Xitifikheti xa switirhisiwa',
    'WORKER' => 'Vutivi',

    'page' => [
        'title_check' => 'Nkambelo wa ku nghena',
        'title_unknown' => 'Khadi leri nga tiviwiki',
        'unknown_heading' => 'KHADI LERI NGA TIVIWIKI',
        'unknown_lead' => 'Khodi leyi a yi tiviwi.',
        'unknown_body' => 'Swi nga endleka yi cinciwile endzhaku ka ku lahleka ka khadi, kumbe a hi ya ndhawu leyi. Kombela leswaku munhu a nyikiwa khadi leritshwa hi loyi a lawulaka vakontiraka laha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'MPFUMELELO WU AMUKERIWE',
            'DENIED' => 'MPFUMELELO WU ARIWE',
            'REVIEW_REQUIRED' => 'A WU KOTI KU KAMBERIWA',
            'UNKNOWN' => 'A WU KAMBERIWI',
        ],
        'review_heading' => 'Leswi a swi kotekangi ku kamberiwa',
        'review_body' => 'Yin\'wana ya tikamberelo a yi hetisiwangi, hikwalaho leswi a hi mpfumelelo naswona a hi ku ala. U nga tekiwi xiboho laha — fonela mutirhisi wa vakontiraka endhawini leyi u nga si pfumelela munhu.',
        'approved' => 'KU NGHENA KU PFUMELERIWILE',
        'denied' => 'KU NGHENA KU ALIWILE',
        'contractor_pass' => 'Khadi ra nkontiraka',
        'worker_pass' => 'Khadi ra mutirhi',
        'verified_against' => 'Swi tiyisisiwile hi tirhekhodo ta :company',
        'refused_because' => 'Ku aliwile hikuva',
        'checks' => 'Nkambelo',
        'ppe_heading' => 'Swiavelo swa nsirhelelo leswi lavekaka eka ntirho lowu',
        'ppe_note' => 'Swi kambele eka munhu.',
        'advisory_heading' => 'Swi tsariwile, a swi ali ku nghena',
        'footer' => 'Ku kambelwile hi :at. Nhlamulo leyi i ntiyiso ntsena hi nkarhi wolowo — khodi leyi fanaka yi kambelwa nakambe nkarhi wun\'wana ni wun\'wana loko yi skenyiwa, hikwalaho yi cinca hi yoxe loko ku landzela milawu ku cinca. Ku skena loku ku tsariwile.',
    ],

    'card' => [
        'title' => 'Khadi ya ku nghena endhawini ya ntirho',
        'no_photo' => 'A ku na foto',
        'employer' => 'Mutholi',
        'site' => 'Ndhawu ya ntirho',
        'project' => 'Projeke',
        'registration' => 'Nomboro ya ku tsarisa',
        'not_set' => 'A yi vekiwi',
        'scan' => 'Skena ku kambela',
        'back_title' => 'Khadi leyi a hi mpfumelelo wa ku tirha.',
        'back_body' => 'Yi kombisa n’wini wa yona. Loko va nga tirha kumbe ku hava swi hlamuriwa hi ku skena khodi, nkarhi ni nkarhi, naswona swi nga cinca exikarhi ka shifi. Vika khadi leri lovekeke hi ku hatlisa — khadi leyintshwa yi yimisa leyi. I nhundzu ya ndhawu leyi yi humeseke; yi tlherisele loko yi kombelwa.',
        'issued' => 'Yi humesiwile hi :date',
        'valid_until' => 'Ndzetelo wu tirha ku fika :date',
        'print_hint' => 'Yi printiwa hi 85.6 × 54 mm — vukulu bya khadi ya bangi. Printa hi 100% handle ka ku hunguta tluka.',
        'print' => 'Printa khadi',
    ],

];
