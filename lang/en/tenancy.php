<?php

/**
 * Server-side strings for multi-tenancy.
 */
return [

    // Shown when a tenant-owned record was about to be written with no company to own it —
    // typically a platform admin acting without having picked a tenant first.
    'no_company_context' => 'No company is selected, so this :model cannot be saved — it would belong to nobody and be invisible to every tenant. Switch into a company first.',

    // Trial countdown warnings sent to platform admins by tenancy:trial-reminders, so a
    // trial is converted or extended before tenancy:expire-trials locks the client out.
    'trial_alert' => [
        'subject_soon' => 'Trial ending in :days day(s) — :company',
        'subject_today' => 'Trial ends today — :company',
        'subject_lapsed' => 'Trial has ended — :company is locked out',
        'greeting' => 'Hello',
        'body_soon' => ":company's free trial ends on :date (:days day(s) from now). When it lapses, everyone at the company is locked out until the trial is extended or the subscription is activated.",
        'body_today' => ":company's free trial ends today (:date). Once it lapses, everyone at the company is locked out until the trial is extended or the subscription is activated.",
        'body_lapsed' => ":company's free trial ended on :date and the company is now locked out. Extend the trial to restore access, or activate the subscription.",
        'plan_line' => 'Plan: :plan · Users: :users · Contact: :contact',
        'action' => 'Open Companies',
        'db_title_soon' => 'Trial ending: :company',
        'db_title_today' => 'Trial ends today: :company',
        'db_title_lapsed' => 'Trial ended: :company',
    ],

];
