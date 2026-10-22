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

    'IDENTITY' => 'Identiteit',
    'CONTRACTOR' => 'Kontrakteur',
    'SAFETY_FILE' => 'Veiligheidslêer',
    'COID' => 'COID',
    'INSURANCE' => 'Versekering',
    'SITE_APPROVAL' => 'Terreingoedkeuring',
    'INDUCTION' => 'Induksie',
    'COMPETENCY' => 'Bevoegdheid',
    'MEDICAL' => 'Mediese',
    'ACTIVITY' => 'Aktiwiteitsgeskiktheid',
    'PPE' => 'PBT',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Dokumente',
    'RISK_ASSESSMENT' => 'Risiko-assessering',
    'METHOD_STATEMENT' => 'Metodeverklaring',
    'PERMIT' => 'Werkpermit',
    'EQUIPMENT_CERTIFICATION' => 'Toerustingsertifikaat',
    'WORKER' => 'Identiteit',

    'page' => [
        'title_check' => 'Toegangsnagaan',
        'title_unknown' => 'Onbekende kaart',
        'unknown_heading' => 'ONBEKENDE KAART',
        'unknown_lead' => 'Hierdie kode word nie erken nie.',
        'unknown_body' => 'Dit kon vervang gewees het nadat \'n kaart verlore geraak het, of dit behoort nie aan hierdie terrein nie. Vra dat die persoon \'n nuwe kaart uitgereik word deur wie ook al kontrakteurs hier bestuur.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'TOEGANG GOEDGEKEUR',
            'DENIED' => 'TOEGANG GEWEIER',
            'REVIEW_REQUIRED' => 'KAN NIE NAGEGAAN WORD NIE',
            'UNKNOWN' => 'NIE NAGEGAAN NIE',
        ],
        'review_heading' => 'Dit kon nie nagegaan word nie',
        'review_body' => 'Een van die kontroles kon nie voltooi word nie, dus is dit nie goedkeuring of weiering nie. Besluit dit nie hier nie — bel die persoon wat kontrakteurs op hierdie terrein bestuur voordat u iemand deurlaat.',
        'approved' => 'TOEGANG GOEDGEKEUR',
        'denied' => 'TOEGANG GEWEIER',
        'contractor_pass' => 'Kontrakteurkaart',
        'worker_pass' => 'Werkerkaart',
        'verified_against' => 'Nagegaan teen :company se rekords',
        'refused_because' => 'Geweier omdat',
        'checks' => 'Nagaan',
        'ppe_heading' => 'PBT vereis vir hierdie werk',
        'ppe_note' => 'Gaan dit op die persoon na.',
        'advisory_heading' => 'Aangeteken, weier nie toegang nie',
        'footer' => 'Nagegaan :at. Hierdie antwoord is slegs op daardie oomblik waar — dieselfde kode word elke keer wanneer dit geskandeer word weer nagegaan, dus verander dit vanself wanneer nakoming verander. Hierdie skandering is aangeteken.',
    ],

    'card' => [
        'title' => 'Terreintoegangskaart',
        'no_photo' => 'Geen foto',
        'employer' => 'Werkgewer',
        'site' => 'Terrein',
        'project' => 'Projek',
        'registration' => 'Registrasie',
        'not_set' => 'Nie gestel nie',
        'scan' => 'Skandeer om na te gaan',
        'back_title' => 'Hierdie kaart is nie toestemming om te werk nie.',
        'back_body' => 'Dit identifiseer die houer. Of die houer mag werk, word elke keer deur die kode te skandeer beantwoord, en dit kan tydens ’n skof verander. Rapporteer ’n verlore kaart onmiddellik — ’n vervanging laat hierdie een ophou werk. Eiendom van die uitreikende terrein; besorg dit op aanvraag terug.',
        'issued' => 'Uitgereik :date',
        'valid_until' => 'Induksie geldig tot :date',
        'print_hint' => 'Druk op 85.6 × 54 mm — die grootte van ’n bankkaart. Druk teen 100% sonder bladskalering.',
        'print' => 'Druk kaart',
    ],

];
