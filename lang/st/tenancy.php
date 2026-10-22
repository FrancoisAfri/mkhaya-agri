<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'Ha ho khampani e kgethilweng, ka hoo :model ena e ke ke ya bolokwa — e ne e tla ba ya motho e mong le ha e le mang mme e be e sa bonahale ho mohiri e mong le e mong. Kena khampaning pele.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Teko e fela ka matsatsi a :days — :company',
        'subject_today' => 'Teko e fela kajeno — :company',
        'subject_lapsed' => 'Teko e felile — :company e koaletswe ka ntle',
        'greeting' => 'Dumela',
        'body_soon' => "Teko ya mahala ya :company e fela ka :date (ka matsatsi a :days ho tloha jwale). Ha e fella, bohle khampaning ba koalelwa ka ntle ho fihlela teko e atoloswa kapa ngodiso e sebetsa.",
        'body_today' => "Teko ya mahala ya :company e fela kajeno (:date). Ha e fella, bohle khampaning ba koalelwa ka ntle ho fihlela teko e atoloswa kapa ngodiso e sebetsa.",
        'body_lapsed' => "Teko ya mahala ya :company e felile ka :date mme khampani hona jwale e koaletswe ka ntle. Atolosa teko ho busetsa phihlello, kapa sebetsisa ngodiso.",
        'plan_line' => 'Morero: :plan · Basebedisi: :users · Mohokahanyi: :contact',
        'action' => 'Bula Dikhampani',
        'db_title_soon' => 'Teko e fela: :company',
        'db_title_today' => 'Teko e fela kajeno: :company',
        'db_title_lapsed' => 'Teko e felile: :company',
    ],

];
