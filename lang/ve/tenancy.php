<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'A hu na khamphani yo khethwaho, ngauralo :model heyi a i nga si vhulungwe — i ḓo vha ya muthu a si gale nahone i si vhonale kha mureisi muṅwe na muṅwe. Dzhenani kha khamphani u thoma.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Mulingo u fhela nga maḓuvha a :days — :company',
        'subject_today' => 'Mulingo u fhela ṋamusi — :company',
        'subject_lapsed' => 'Mulingo wo fhela — :company a i tsha dzhena',
        'greeting' => 'Ndaa',
        'body_soon' => "Mulingo wa mahala wa :company u fhela nga :date (nga maḓuvha a :days a ḓaho). Musi u tshi fhela, vhoṱhe kha khamphani vha thivhelwa u swikela mulingo wo engedzwa kana mbadelo yo thoma u shuma.",
        'body_today' => "Mulingo wa mahala wa :company u fhela ṋamusi (:date). Musi u tshi fhela, vhoṱhe kha khamphani vha thivhelwa u swikela mulingo wo engedzwa kana mbadelo yo thoma u shuma.",
        'body_lapsed' => "Mulingo wa mahala wa :company wo fhela nga :date nahone khamphani zwino a i tsha dzhena. Engedzani mulingo u vhuyedzedza u dzhena, kana shumisani mbadelo.",
        'plan_line' => 'Mbekanyamushumo: :plan · Vhashumisi: :users · Vhane ni vha vhidza: :contact',
        'action' => 'Vulani Khamphani',
        'db_title_soon' => 'Mulingo u fhela: :company',
        'db_title_today' => 'Mulingo u fhela ṋamusi: :company',
        'db_title_lapsed' => 'Mulingo wo fhela: :company',
    ],

];
