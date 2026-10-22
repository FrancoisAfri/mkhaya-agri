<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Ayikho inkampani ekhethiwe, ngakho le :model ayikwazi ukulondolozwa — ingaba eyomuntu ongekho futhi ingabonakali kuwo wonke umqashi. Ngena kuqala enkampanini.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Isikhathi sokuzama siphela ngemuva kwezinsuku ezingu-:days — :company',
        'subject_today' => 'Isikhathi sokuzama siphela namuhla — :company',
        'subject_lapsed' => 'Isikhathi sokuzama siphelile — i-:company ivalelwe ngaphandle',
        'greeting' => 'Sawubona',
        'body_soon' => "Isikhathi sokuzama samahhala se-:company siphela ngomhla :date (ezinsukwini ezingu-:days ezizayo). Lapho siphela, wonke umuntu enkampanini uvalelwa ngaphandle kuze kube isikhathi sokuzama sandiswe noma umnikelo usebenze.",
        'body_today' => "Isikhathi sokuzama samahhala se-:company siphela namuhla (:date). Lapho nje siphela, wonke umuntu enkampanini uvalelwa ngaphandle kuze kube isikhathi sokuzama sandiswe noma umnikelo usebenze.",
        'body_lapsed' => "Isikhathi sokuzama samahhala se-:company saphela ngomhla :date futhi inkampani manje ivalelwe ngaphandle. Yandisa isikhathi sokuzama ukubuyisa ukufinyelela, noma yenza umnikelo usebenze.",
        'plan_line' => 'Uhlelo: :plan · Abasebenzisi: :users · Oxhumana naye: :contact',
        'action' => 'Vula Izinkampani',
        'db_title_soon' => 'Isikhathi sokuzama siphela: :company',
        'db_title_today' => 'Isikhathi sokuzama siphela namuhla: :company',
        'db_title_lapsed' => 'Isikhathi sokuzama siphelile: :company',
    ],

];
