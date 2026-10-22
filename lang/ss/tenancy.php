<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Akukho nkhampani lekhetsiwe, ngako le :model angeke igcinwe — ingaba yemuntfu longekho futsi ingabonakali kuwo wonkhe umcashi. Ngena kucala enkhampanini.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Sikhatsi sekuzama siphela ngemuva kwemalanga langu-:days — :company',
        'subject_today' => 'Sikhatsi sekuzama siphela lamuhla — :company',
        'subject_lapsed' => 'Sikhatsi sekuzama siphelile — i-:company ivalelwe ngaphandle',
        'greeting' => 'Sawubona',
        'body_soon' => "Sikhatsi sekuzama samahhala se-:company siphela mhlaka :date (emalangeni langu-:days letako). Nangabe siphela, wonkhe umuntfu enkhampanini uvalelwa ngaphandle kuze kube sikhatsi sekuzama sandziswe nome umnikelo usebente.",
        'body_today' => "Sikhatsi sekuzama samahhala se-:company siphela lamuhla (:date). Nangabe nje siphela, wonkhe umuntfu enkhampanini uvalelwa ngaphandle kuze kube sikhatsi sekuzama sandziswe nome umnikelo usebente.",
        'body_lapsed' => "Sikhatsi sekuzama samahhala se-:company saphela mhlaka :date futsi inkhampani manje ivalelwe ngaphandle. Yandzisa sikhatsi sekuzama kubuyisa kungena, nome wente umnikelo usebente.",
        'plan_line' => 'Luhlelo: :plan · Basebentisi: :users · Lotsintsana naye: :contact',
        'action' => 'Vula Tinkhampani',
        'db_title_soon' => 'Sikhatsi sekuzama siphela: :company',
        'db_title_today' => 'Sikhatsi sekuzama siphela lamuhla: :company',
        'db_title_lapsed' => 'Sikhatsi sekuzama siphelile: :company',
    ],

];
