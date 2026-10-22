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

    'IDENTITY' => 'Boitshupo',
    'CONTRACTOR' => 'Mokonteraka',
    'SAFETY_FILE' => 'Faele ya pabalesego',
    'COID' => 'COID',
    'INSURANCE' => 'Inshorense',
    'SITE_APPROVAL' => 'Tetla ya lefelo',
    'INDUCTION' => 'Thapiso',
    'COMPETENCY' => 'Bokgoni',
    'MEDICAL' => 'Tlhatlhobo ya botsogo',
    'ACTIVITY' => 'Tshwanelo ya tiro',
    'PPE' => 'Diaparo tsa tshireletso',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Dikwalo',
    'RISK_ASSESSMENT' => 'Tshekatsheko ya kotsi',
    'METHOD_STATEMENT' => 'Polelo ya mokgwa',
    'PERMIT' => 'Tetla ya tiro',
    'EQUIPMENT_CERTIFICATION' => 'Setifikeiti sa didirisiwa',
    'WORKER' => 'Boitshupo',

    'page' => [
        'title_check' => 'Tlhatlhobo ya tsenelelo',
        'title_unknown' => 'Karata e e sa itsiweng',
        'unknown_heading' => 'KARATA E E SA ITSIWENG',
        'unknown_lead' => 'Khoutu eno ga e itsiwe.',
        'unknown_body' => 'E ka nna e emisediwa morago ga go latlhega ga karata, kgotsa ga e nne ya lefelo leno. Kopa gore motho a neelwe karata e ntšha ke yo o laolang bakonteraka fa.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'TETLA E AMOGETSWE',
            'DENIED' => 'TETLA E GANWE',
            'REVIEW_REQUIRED' => 'GA E KA TLHATLHOBIWA',
            'UNKNOWN' => 'GA E TLHATLHOBIWE',
        ],
        'review_heading' => 'Se ga se ka tlhatlhobiwa',
        'review_body' => 'Nngwe ya ditlhatlhobo ga e ka fetswa, ka jalo se ga se tetla mme ga se kgang. O se ka go tsaya tshwetso fa — letsetsa motho yo o laolang bakontraka mo lefelong le pele o letla mongwe.',
        'approved' => 'TSENELELO E LETLELETSWE',
        'denied' => 'TSENELELO E GANNWE',
        'contractor_pass' => 'Karata ya mokonteraka',
        'worker_pass' => 'Karata ya modiri',
        'verified_against' => 'E netefaditswe ka direkoto tsa :company',
        'refused_because' => 'Go gannwe ka gore',
        'checks' => 'Ditlhatlhobo',
        'ppe_heading' => 'Diaparo tsa tshireletso tse tlhokegang mo tirong eno',
        'ppe_note' => 'E tlhatlhobe mo mothong.',
        'advisory_heading' => 'E kwadilwe, ga e gane tsenelelo',
        'footer' => 'E tlhatlhobilwe ka :at. Karabo eno ke boammaaruri fela ka nako eo — khoutu e e tshwanang e tlhatlhobiwa gape nako nngwe le nngwe fa e skeniwa, ka jalo e fetoga ka boyone fa go ikobela melao go fetoga. Skene eno e kwadilwe.',
    ],

    'card' => [
        'title' => 'Karata ya tsenelelo ya lefelo',
        'no_photo' => 'Ga go setshwantsho',
        'employer' => 'Mothapi',
        'site' => 'Lefelo',
        'project' => 'Porojeke',
        'registration' => 'Nomoro ya kwadiso',
        'not_set' => 'Ga e a tlhomiwa',
        'scan' => 'Skena go tlhola',
        'back_title' => 'Karata e ga se tetla ya go dira.',
        'back_body' => 'E supa mong wa yone. Gore a ka dira go arabiwa ka go skena khoutu, nako le nako, mme go ka fetoga mo gare ga shifti. Bega karata e e latlhegileng ka bonako — karata e ntšhwa e emisa e e. Ke thoto ya lefelo le le e ntshitseng; e busetse fa e kopiwa.',
        'issued' => 'E ntshitswe ka :date',
        'valid_until' => 'Thapiso e dira go fitlha :date',
        'print_hint' => 'E gatisiwa ka 85.6 × 54 mm — bogolo jwa karata ya banka. Gatisa ka 100% ntle le go fokotsa letlhare.',
        'print' => 'Gatisa karata',
    ],

];
