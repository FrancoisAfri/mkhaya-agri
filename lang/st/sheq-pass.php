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

    'IDENTITY' => 'Boitsebiso',
    'CONTRACTOR' => 'Mokonteraka',
    'SAFETY_FILE' => 'Faele ya polokeho',
    'COID' => 'COID',
    'INSURANCE' => 'Inshorense',
    'SITE_APPROVAL' => 'Tumello ya setsha',
    'INDUCTION' => 'Thupelo',
    'COMPETENCY' => 'Bokgoni',
    'MEDICAL' => 'Tlhahlobo ya bophelo',
    'ACTIVITY' => 'Tshwanelo ya mosebetsi',
    'PPE' => 'Diaparo tsa tshireletso',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Ditokomane',
    'RISK_ASSESSMENT' => 'Tlhahlobo ya kotsi',
    'METHOD_STATEMENT' => 'Polelo ya mokgwa',
    'PERMIT' => 'Tumello ya mosebetsi',
    'EQUIPMENT_CERTIFICATION' => 'Setifikeiti sa disebediswa',
    'WORKER' => 'Boitsebiso',

    'page' => [
        'title_check' => 'Tlhahlobo ya ho kena',
        'title_unknown' => 'Karete e sa tsejweng',
        'unknown_heading' => 'KARETE E SA TSEJWENG',
        'unknown_lead' => 'Khoutu ena ha e tsejwe.',
        'unknown_body' => 'E ka be e nkelwe sebaka kamora hore karete e lahlehe, kapa ha e be ya setsha sena. Kopa hore motho a fuwe karete e ntjha ke ya tsamaisang bakonteraka mona.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'MONYETLA O AMOHETSWE',
            'DENIED' => 'MONYETLA O HANNWE',
            'REVIEW_REQUIRED' => 'HA E KA HLAHLOBJWA',
            'UNKNOWN' => 'HA E HLAHLOBJWE',
        ],
        'review_heading' => 'Sena se ne se sa ka hlahlobjwa',
        'review_body' => 'E mong wa tlhahlobo ha e ka phethwa, ka hona sena hase tumello le hase kgano. Se nke qeto mona — letsetsa motho ya laolang bakonteraka setsheng sena pele o lumella mang kapa mang.',
        'approved' => 'HO KENA HO DUMELLWE',
        'denied' => 'HO KENA HO HANNWE',
        'contractor_pass' => 'Karete ya mokonteraka',
        'worker_pass' => 'Karete ya mosebetsi',
        'verified_against' => 'Netefalitswe ka direkoto tsa :company',
        'refused_because' => 'Ho hanngwe hobane',
        'checks' => 'Ditlhahlobo',
        'ppe_heading' => 'Diaparo tsa tshireletso tse hlokahalang mosebetsing ona',
        'ppe_note' => 'E hlahlobe ho motho.',
        'advisory_heading' => 'E tlalehilwe, ha e hane ho kena',
        'footer' => 'E hlahlobilwe ka :at. Karabo ena ke nnete feela ka nako eo — khoutu e tshwanang e hlahlobuwa hape nako e nngwe le e nngwe ha e skenngwa, ka hoo e fetoha ka boyona ha tumellano e fetoha. Skene sena se tlalehilwe.',
    ],

    'card' => [
        'title' => 'Karete ea phihlello ea sebaka',
        'no_photo' => 'Ha ho setšoantšo',
        'employer' => 'Mohiri',
        'site' => 'Sebaka',
        'project' => 'Morero',
        'registration' => 'Nomoro ea ngoliso',
        'not_set' => 'Ha e behiloe',
        'scan' => 'Skena ho hlahloba',
        'back_title' => 'Karete ena hase tumello ea ho sebetsa.',
        'back_body' => 'E tsebahatsa mong\'a eona. Hore na a ka sebetsa kapa che ho arabuoa ka ho skena khoutu, nako le nako, mme ho ka fetoha har\'a shifti. Tlalehe karete e lahlehileng hang-hang — karete e ncha e emisa ena. Ke thepa ea sebaka se e fanang; e khutlisetse ha e kopuoa.',
        'issued' => 'E fanoe ka :date',
        'valid_until' => 'Koetliso e sebetsa ho fihlela :date',
        'print_hint' => 'E hatisoa ka 85.6 × 54 mm — boholo ba karete ea banka. Hatisa ka 100% ntle le ho fokotsa leqephe.',
        'print' => 'Hatisa karete',
    ],

];
