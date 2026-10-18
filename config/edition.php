<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    | 'saas'        — multi-tenant; module access & limits come from the company's
    |                 plan/subscription (App\Modules\Tenancy).
    | 'self_hosted' — one customer; module access comes from the signed Ed25519
    |                 license verified by App\Core\License\LicenseService.
    */
    'edition' => env('APP_EDITION', 'saas'),

    // Free-trial length for new SaaS signups.
    'trial_days' => (int) env('TRIAL_DAYS', 30),

    // Days before a trial ends on which platform admins are warned (descending).
    // 0 = the last day. `tenancy:trial-reminders` fires at most one alert per company
    // per threshold, and also once on the day a trial actually lapses.
    'trial_alert_days' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('TRIAL_ALERT_DAYS', '14,7,3,1,0'))),
        fn ($d) => $d >= 0,
    )),

    // Launch promotion: for the first N months from the launch date, plans bill at
    // their launch price. Founding customers who sign up in this window keep that
    // price for `founding_lock_months` (independent of when the window closes).
    'launch_date'          => env('LAUNCH_DATE', '2026-07-01'),
    'launch_months'        => (int) env('LAUNCH_MONTHS', 6),
    'founding_lock_months' => (int) env('FOUNDING_LOCK_MONTHS', 12),
];
