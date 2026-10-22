<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Akukho nkampani ekhethiweyo, ngoko le :model ayinakugcinwa — ingaba yomntu ongekhoyo kwaye ingabonakali kuwo wonke umqeshisi. Ngena kwinkampani kuqala.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Uvavanyo luphela kwiintsuku ezingama-:days — :company',
        'subject_today' => 'Uvavanyo luphela namhlanje — :company',
        'subject_lapsed' => 'Uvavanyo luphelile — i-:company ivalelwe ngaphandle',
        'greeting' => 'Molo',
        'body_soon' => "Uvavanyo lwasimahla lwe-:company luphela ngomhla :date (kwiintsuku ezingama-:days ezizayo). Xa luphela, wonke umntu kwinkampani uvalelwa ngaphandle de uvavanyo lwandiswe okanye umrhumo usebenze.",
        'body_today' => "Uvavanyo lwasimahla lwe-:company luphela namhlanje (:date). Nje ukuba luphele, wonke umntu kwinkampani uvalelwa ngaphandle de uvavanyo lwandiswe okanye umrhumo usebenze.",
        'body_lapsed' => "Uvavanyo lwasimahla lwe-:company luphela ngomhla :date kwaye inkampani ngoku ivalelwe ngaphandle. Yandisa uvavanyo ukubuyisela ufikelelo, okanye yenza umrhumo usebenze.",
        'plan_line' => 'Isicwangciso: :plan · Abasebenzisi: :users · Oqhagamshelwano: :contact',
        'action' => 'Vula Iinkampani',
        'db_title_soon' => 'Uvavanyo luphela: :company',
        'db_title_today' => 'Uvavanyo luphela namhlanje: :company',
        'db_title_lapsed' => 'Uvavanyo luphelile: :company',
    ],

];
