<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Geen maatskappy is gekies nie, dus kan hierdie :model nie gestoor word nie — dit sou aan niemand behoort en vir elke huurder onsigbaar wees. Skakel eers in \'n maatskappy in.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Proeftydperk eindig oor :days dag(e) — :company',
        'subject_today' => 'Proeftydperk eindig vandag — :company',
        'subject_lapsed' => 'Proeftydperk het geëindig — :company is uitgesluit',
        'greeting' => 'Hallo',
        'body_soon' => ":company se gratis proeftydperk eindig op :date (:days dag(e) van nou af). Wanneer dit verval, word almal by die maatskappy uitgesluit totdat die proeftydperk verleng of die intekening geaktiveer word.",
        'body_today' => ":company se gratis proeftydperk eindig vandag (:date). Sodra dit verval, word almal by die maatskappy uitgesluit totdat die proeftydperk verleng of die intekening geaktiveer word.",
        'body_lapsed' => ":company se gratis proeftydperk het op :date geëindig en die maatskappy is nou uitgesluit. Verleng die proeftydperk om toegang te herstel, of aktiveer die intekening.",
        'plan_line' => 'Plan: :plan · Gebruikers: :users · Kontak: :contact',
        'action' => 'Maak Maatskappye oop',
        'db_title_soon' => 'Proeftydperk eindig: :company',
        'db_title_today' => 'Proeftydperk eindig vandag: :company',
        'db_title_lapsed' => 'Proeftydperk geëindig: :company',
    ],

];
