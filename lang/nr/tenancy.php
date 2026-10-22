<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Akunankampani ekhethiweko, ngalokho i-:model le angekhe ibulungwe — ingaba yomuntu ongekho begodu ingabonakali kuwo woke umqatjhi. Ngena qangi enkampanini.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Isikhathi sokulinga siphela ngemva kwamalanga angu-:days — :company',
        'subject_today' => 'Isikhathi sokulinga siphela namhlanjesi — :company',
        'subject_lapsed' => 'Isikhathi sokulinga siphelile — i-:company ivalelwe ngaphandle',
        'greeting' => 'Lotjhani',
        'body_soon' => "Isikhathi sokulinga samahhala se-:company siphela mhlaka :date (emalangeni angu-:days azako). Nange siphela, woke umuntu enkampanini uvalelwa ngaphandle bekube isikhathi sokulinga sandiswa namkha umnikelo usebenze.",
        'body_today' => "Isikhathi sokulinga samahhala se-:company siphela namhlanjesi (:date). Nange nje siphela, woke umuntu enkampanini uvalelwa ngaphandle bekube isikhathi sokulinga sandiswa namkha umnikelo usebenze.",
        'body_lapsed' => "Isikhathi sokulinga samahhala se-:company saphela mhlaka :date begodu inkampani nje ivalelwe ngaphandle. Yandisa isikhathi sokulinga ukubuyisa ukungena, namkha wenze umnikelo usebenze.",
        'plan_line' => 'Ihlelo: :plan · Abasebenzisi: :users · Othintanwa naye: :contact',
        'action' => 'Vula Iinkampani',
        'db_title_soon' => 'Isikhathi sokulinga siphela: :company',
        'db_title_today' => 'Isikhathi sokulinga siphela namhlanjesi: :company',
        'db_title_lapsed' => 'Isikhathi sokulinga siphelile: :company',
    ],

];
