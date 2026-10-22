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

    'IDENTITY' => 'Boitšhupo',
    'CONTRACTOR' => 'Mokonteraka',
    'SAFETY_FILE' => 'Faele ya polokego',
    'COID' => 'COID',
    'INSURANCE' => 'Inšorense',
    'SITE_APPROVAL' => 'Tumelelo ya lefelo',
    'INDUCTION' => 'Tlhahlo',
    'COMPETENCY' => 'Bokgoni',
    'MEDICAL' => 'Tlhahlobo ya maphelo',
    'ACTIVITY' => 'Maleba a mošomo',
    'PPE' => 'Diaparo tša tšhireletšo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Ditokumente',
    'RISK_ASSESSMENT' => 'Tshekatsheko ya kotsi',
    'METHOD_STATEMENT' => 'Pego ya mokgwa',
    'PERMIT' => 'Tumelelo ya mošomo',
    'EQUIPMENT_CERTIFICATION' => 'Setifikeiti sa didirišwa',
    'WORKER' => 'Boitsebišo',

    'page' => [
        'title_check' => 'Tlhahlobo ya go tsena',
        'title_unknown' => 'Karata yeo e sa tsebjwego',
        'unknown_heading' => 'KARATA YEO E SA TSEBJWEGO',
        'unknown_lead' => 'Khoutu ye ga e tsebjwe.',
        'unknown_body' => 'E ka ba e emišeditšwe ka morago ga go lahlega ga karata, goba ga e be ya lefelo le. Kgopela gore motho a newe karata ye mpsha ke yo a laolago bakonteraka mo.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'TUMELELO E AMOGETŠWE',
            'DENIED' => 'TUMELELO E GANNWE',
            'REVIEW_REQUIRED' => 'GA E KA HLAHLOBJWA',
            'UNKNOWN' => 'GA E HLAHLOBJWE',
        ],
        'review_heading' => 'Se ga se ka hlahlobja',
        'review_body' => 'Ye nngwe ya ditlhahlobo ga e ka phethwa, ka gona se ga se tumelelo le ga se kgano. O se dire sephetho mo — letšetša motho yo a laolago bakontraka lefelong le pele o dumelela mang goba mang.',
        'approved' => 'GO TSENA GO DUMELETŠWE',
        'denied' => 'GO TSENA GO GANNWE',
        'contractor_pass' => 'Karata ya mokonteraka',
        'worker_pass' => 'Karata ya mošomi',
        'verified_against' => 'E netefaditšwe ka direkoto tša :company',
        'refused_because' => 'Go gannwe ka gore',
        'checks' => 'Ditlhahlobo',
        'ppe_heading' => 'Diaparo tša tšhireletšo tšeo di nyakegago mošomong wo',
        'ppe_note' => 'E hlahlobe go motho.',
        'advisory_heading' => 'E ngwadilwe, ga e gane go tsena',
        'footer' => 'E hlahlobilwe ka :at. Karabo ye ke therešo feela ka nako yeo — khoutu ye e swanago e hlahlobja gape nako ye nngwe le ye nngwe ge e skenwa, ka fao e fetoga ka boyona ge go latela melao go fetoga. Skene ye e ngwadilwe.',
    ],

    'card' => [
        'title' => 'Karata ya tsenelelo ya lefelo',
        'no_photo' => 'Ga go seswantšho',
        'employer' => 'Mothwadi',
        'site' => 'Lefelo',
        'project' => 'Porotšeke',
        'registration' => 'Nomoro ya ngwadišo',
        'not_set' => 'Ga e beakantšwe',
        'scan' => 'Skena go hlahloba',
        'back_title' => 'Karata ye ga se tumelelo ya go šoma.',
        'back_body' => 'E hlaola mong wa yona. Gore a ka šoma goba aowa go arabja ka go skena khoutu, ka mehla, gomme go ka fetoga gare ga shifti. Bega karata ye e lobegileng ka pela — karata ye mpsha e emiša ye. Ke thoto ya lefelo leo le e ntšhitšego; e bušetše ge e kgopelwa.',
        'issued' => 'E ntšhitšwe ka :date',
        'valid_until' => 'Tlhahlo e šoma go fihla :date',
        'print_hint' => 'E gatišwa ka 85.6 × 54 mm — bogolo bja karata ya panka. Gatiša ka 100% ntle le go fokotša letlakala.',
        'print' => 'Gatiša karata',
    ],

];
