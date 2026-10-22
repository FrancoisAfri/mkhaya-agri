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

    'IDENTITY' => 'Vhuṋe',
    'CONTRACTOR' => 'Mukonteraka',
    'SAFETY_FILE' => 'Faela ya tsireledzo',
    'COID' => 'COID',
    'INSURANCE' => 'Inshuransi',
    'SITE_APPROVAL' => 'Thendelo ya fhethu',
    'INDUCTION' => 'Pfunzo ya tsireledzo',
    'COMPETENCY' => 'Vhukoni',
    'MEDICAL' => 'Ṱhoḓisiso ya mutakalo',
    'ACTIVITY' => 'U teaho ha mushumo',
    'PPE' => 'Zwiṱirisiswa zwa tsireledzo',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Maṅwalo',
    'RISK_ASSESSMENT' => 'Ṱhoḓisiso ya khombo',
    'METHOD_STATEMENT' => 'Muvhigo wa nḓila',
    'PERMIT' => 'Thendelo ya mushumo',
    'EQUIPMENT_CERTIFICATION' => 'Tsha u khwaṱhisedza zwishumiswa',
    'WORKER' => 'Vhuṋe',

    'page' => [
        'title_check' => 'Ṱhoḓisiso ya u dzhena',
        'title_unknown' => 'Karata i sa ḓivhei',
        'unknown_heading' => 'KARATA I SA ḒIVHEI',
        'unknown_lead' => 'Khoudu heyi a i ḓivhei.',
        'unknown_body' => 'Zwi nga kha ḓi vha yo dzhielwa fhethu nga murahu ha u xela ha karata, kana a si ya fhethu hafhu. Humbelani uri muthu a ṋewe karata ntswa nga ane a langula vhakonteraka hafha.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'U SWIKELELA HO TENDELWA',
            'DENIED' => 'U SWIKELELA HO HANWA',
            'REVIEW_REQUIRED' => 'A HU KONI U ṰOLWA',
            'UNKNOWN' => 'A HO NGO ṰOLWA',
        ],
        'review_heading' => 'Hezwi a zwo ngo koni u ṱolwa',
        'review_body' => 'Nyingwe ya khaṱhulo a yo ngo fhedziswa, ngauralo hezwi a si thendelo na a si u hana. Ni songo dzhia tsheo hafha — vhidzani muthu ane a langa vhakontiraka kha ḽino fhethu ni sa athu u tendela muṅwe muthu.',
        'approved' => 'U DZHENA HO TENDELWA',
        'denied' => 'U DZHENA HO HANWA',
        'contractor_pass' => 'Karata ya mukonteraka',
        'worker_pass' => 'Karata ya mushumi',
        'verified_against' => 'Ho khwaṱhisedzwa nga rekhodo dza :company',
        'refused_because' => 'Ho hanwa ngauri',
        'checks' => 'Ṱhoḓisiso',
        'ppe_heading' => 'Zwiṱirisiswa zwa tsireledzo zwine zwi ṱoḓea kha mushumo uyu',
        'ppe_note' => 'Zwi ṱolisiseni kha muthu.',
        'advisory_heading' => 'Zwo ṅwalwa, a zwi hani u dzhena',
        'footer' => 'Ho ṱolisiswa nga :at. Phindulo heyi ndi ngoho fhedzi nga tshenetsho tshifhinga — khoudu i fanaho i ṱolisiswa hafhu tshifhinga tshiṅwe na tshiṅwe musi i tshi sikenwa, ngauralo i shanduka nga yoṱhe musi u tevhedza milayo hu tshi shanduka. U sikena uhu ho ṅwalwa.',
    ],

    'card' => [
        'title' => 'Khadi ya u dzhena fhethu ha mushumo',
        'no_photo' => 'A hu na tshifanyiso',
        'employer' => 'Muṋe wa mushumo',
        'site' => 'Fhethu ha mushumo',
        'project' => 'Phurodzheke',
        'registration' => 'Nomboro ya ṅwaliso',
        'not_set' => 'A ho ngo vhewa',
        'scan' => 'Skena u ṱola',
        'back_title' => 'Khadi iyi a si thendelo ya u shuma.',
        'back_body' => 'I sumbedza muṋe wayo. Uri vha nga shuma kana hu si ngauralo hu fhindulwa nga u skena khoudu, tshifhinga tshoṱhe, nahone hu nga shanduka vhukati ha shifiti. Vhigani khadi yo xelaho ni si lenge — khadi ntswa i ima iyi. Ndi thundu ya fhethu ho i bvisaho; i vhuyedzedzeni musi hu khou humbelwa.',
        'issued' => 'Yo bviswa nga :date',
        'valid_until' => 'Pfunzo i shuma u swika :date',
        'print_hint' => 'I gandiswa nga 85.6 × 54 mm — vhuhulu ha khadi ya bannga. Gandisani nga 100% hu si na u fhungudza siaṱari.',
        'print' => 'Gandisa khadi',
    ],

];
