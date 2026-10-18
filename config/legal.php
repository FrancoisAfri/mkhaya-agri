<?php

/*
|--------------------------------------------------------------------------
| Legal pages
|--------------------------------------------------------------------------
| Who operates this installation, for the public privacy policy and data
| deletion pages at /privacy and /data-deletion.
|
| Facebook and LinkedIn both require a reachable privacy policy URL before an
| app leaves development mode, and Facebook additionally requires a data
| deletion URL. Their reviewers read the page, so anything not configured here
| is LEFT OUT of the document rather than printed as a placeholder — a policy
| with "[Your Company Address]" in it reads as unfinished, which is worse than
| a policy with one fewer line.
|
| The company name, support address and logo come from the company identity in
| Settings → Setup and need nothing here. The rest are the details a data
| protection notice needs and nothing else in the system records.
*/

return [

    // Registered company number, e.g. '2019/123456/07'. Printed beside the name.
    'registration_number' => env('LEGAL_REGISTRATION_NUMBER'),

    // Street or postal address, one line or several separated by commas.
    'address' => env('LEGAL_ADDRESS'),

    // Public contact telephone. Optional — the email address is the one that matters.
    'phone' => env('LEGAL_PHONE'),

    /*
     * The Information Officer, which POPIA s55 requires every responsible party to
     * appoint and register with the Information Regulator. Where no separate officer
     * is named the head of the organisation holds the role by default, so leaving
     * this unset is not an omission in the policy — the duty simply sits with them.
     */
    'information_officer'       => env('LEGAL_INFORMATION_OFFICER'),
    'information_officer_email' => env('LEGAL_INFORMATION_OFFICER_EMAIL'),

    // Where privacy requests go. Falls back to the support address in company identity.
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL'),

    /*
     * The date the current wording took effect. Shown on the page, because a policy
     * without one cannot be relied on. Update it when the wording changes materially.
     */
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-10-07'),

    // Working days to answer a data subject request. POPIA sets no fixed period for
    // most requests; 30 days is the commitment made in the published policy.
    'request_days' => (int) env('LEGAL_REQUEST_DAYS', 30),

];
