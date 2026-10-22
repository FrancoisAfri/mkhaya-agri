<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Ga go khamphani yeo e kgethilwego, ka fao :model ye e ka se bolokwe — e be e tla ba ya motho yo a se gona gomme e se bonagale go mohiriši yo mongwe le yo mongwe. Tsena ka khamphaning pele.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Teko e fela ka matšatši a :days — :company',
        'subject_today' => 'Teko e fela lehono — :company',
        'subject_lapsed' => 'Teko e feletše — :company e tswaletšwe ka ntle',
        'greeting' => 'Dumela',
        'body_soon' => "Teko ya mahala ya :company e fela ka :date (ka matšatši a :days go tloga ga bjale). Ge e fela, bohle ka khamphaning ba tswalelwa ka ntle go fihlela teko e okeditšwe goba ngwadišo ya ditefo e šoma.",
        'body_today' => "Teko ya mahala ya :company e fela lehono (:date). Ge e fela, bohle ka khamphaning ba tswalelwa ka ntle go fihlela teko e okeditšwe goba ngwadišo ya ditefo e šoma.",
        'body_lapsed' => "Teko ya mahala ya :company e feletše ka :date gomme khamphani ga bjale e tswaletšwe ka ntle. Okeletša teko go bušetša phihlelelo, goba šomiša ngwadišo ya ditefo.",
        'plan_line' => 'Leano: :plan · Bašomiši: :users · Mogokgo: :contact',
        'action' => 'Bula Dikhamphani',
        'db_title_soon' => 'Teko e fela: :company',
        'db_title_today' => 'Teko e fela lehono: :company',
        'db_title_lapsed' => 'Teko e feletše: :company',
    ],

];
