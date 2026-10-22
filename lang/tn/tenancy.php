<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Ga go khamphani e e tlhophilweng, ka jalo :model eno ga e ka ke ya bolokwa — e ne e tla nna ya ope fela mme e se bonale mo mohirising mongwe le mongwe. Tsena mo khamphaning pele.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Teko e fela mo malatsing a :days — :company',
        'subject_today' => 'Teko e fela gompieno — :company',
        'subject_lapsed' => 'Teko e feletse — :company e tswetswe kwa ntle',
        'greeting' => 'Dumela',
        'body_soon' => "Teko ya mahala ya :company e fela ka :date (mo malatsing a :days go tswa jaanong). Fa e fela, botlhe mo khamphaning ba tswalelwa kwa ntle go fitlha teko e okediwa kgotsa pego ya dituelo e dira.",
        'body_today' => "Teko ya mahala ya :company e fela gompieno (:date). Fa e fela, botlhe mo khamphaning ba tswalelwa kwa ntle go fitlha teko e okediwa kgotsa pego ya dituelo e dira.",
        'body_lapsed' => "Teko ya mahala ya :company e feletse ka :date mme khamphani gone jaanong e tswetswe kwa ntle. Okeletsa teko go busetsa tsenelelo, kgotsa dirisa pego ya dituelo.",
        'plan_line' => 'Leano: :plan · Badirisi: :users · Mogolagani: :contact',
        'action' => 'Bula Dikhamphani',
        'db_title_soon' => 'Teko e fela: :company',
        'db_title_today' => 'Teko e fela gompieno: :company',
        'db_title_lapsed' => 'Teko e feletse: :company',
    ],

];
