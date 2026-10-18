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

    'IDENTITY' => 'Identity',
    'CONTRACTOR' => 'Contractor',
    'SAFETY_FILE' => 'Safety file',
    'COID' => 'COID',
    'INSURANCE' => 'Insurance',
    'SITE_APPROVAL' => 'Site approval',
    'INDUCTION' => 'Induction',
    'COMPETENCY' => 'Competency',
    'MEDICAL' => 'Medical',
    'ACTIVITY' => 'Activity eligibility',
    'PPE' => 'PPE',

    /*
     * The checks the compliance engine returns (COM-1). A check emitted without a label
     * here renders as a raw key on the one screen a guard reads at a boom, and
     * AccessPassTest asserts every key the service emits resolves to something.
     */
    'DOCUMENTS' => 'Documents',
    'RISK_ASSESSMENT' => 'Risk assessment',
    'METHOD_STATEMENT' => 'Method statement',
    'PERMIT' => 'Permit to work',
    'EQUIPMENT_CERTIFICATION' => 'Equipment certificate',
    'WORKER' => 'Identity',

    'page' => [
        'title_check' => 'Access check',
        'title_unknown' => 'Unknown pass',
        'unknown_heading' => 'UNKNOWN PASS',
        'unknown_lead' => 'This code is not recognised.',
        'unknown_body' => 'It may have been replaced after a card was lost, or it may not belong to this site. Ask the person to be issued a new pass by whoever manages contractors here.',
        /*
         * Three answers, not two (COM-3). 'Could not be checked' is neither an approval
         * nor a refusal, and a guard shown DENIED for it turns away a crew over a
         * register that was briefly unreachable.
         */
        'outcome' => [
            'ALLOWED' => 'ACCESS APPROVED',
            'DENIED' => 'ACCESS DENIED',
            'REVIEW_REQUIRED' => 'CANNOT BE CHECKED',
            'UNKNOWN' => 'NOT CHECKED',
        ],
        'review_heading' => 'This could not be checked',
        'review_body' => 'One of the checks could not be completed, so this is neither an approval nor a refusal. Do not decide it here — call whoever manages contractors on this site before letting anybody through.',
        'approved' => 'ACCESS APPROVED',
        'denied' => 'ACCESS DENIED',
        'contractor_pass' => 'Contractor pass',
        'worker_pass' => 'Worker pass',
        'verified_against' => 'Verified against :company\'s records',
        'refused_because' => 'Refused because',
        'checks' => 'Checks',
        'ppe_heading' => 'PPE required for this work',
        'ppe_note' => 'Check it on the person.',
        'advisory_heading' => 'Noted, not refusing entry',
        'footer' => 'Checked :at. This answer is true at that moment only — the same code is checked again every time it is scanned, so it changes by itself when compliance does. This scan has been recorded.',
    ],

    /*
     * The printable access card (CON-11). Here and not in the Vue locales for the same reason
     * the scan page is: the card is plain Blade so it prints identically from any browser with no
     * bundle, and whoever prints it may well be on a machine that cannot reach the build.
     */
    'card' => [
        'title' => 'Site access card',
        'no_photo' => 'No photograph',
        'employer' => 'Employer',
        'site' => 'Site',
        'project' => 'Project',
        'registration' => 'Registration',
        'not_set' => 'Not set',
        'scan' => 'Scan to check',
        'back_title' => 'This card is not permission to work.',
        'back_body' => 'It identifies the holder. Whether they may work is answered by scanning the code, every time, and can change during a shift. Report a lost card immediately — a replacement stops this one working. Property of the issuing site; return it on request.',
        'issued' => 'Issued :date',
        'valid_until' => 'Induction valid until :date',
        'print_hint' => 'Prints at 85.6 × 54 mm — the size of a bank card. Print at 100% with no page scaling.',
        'print' => 'Print card',
    ],

];
