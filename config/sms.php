<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-company SMS quota (SaaS)
    |--------------------------------------------------------------------------
    | Each company gets a monthly SMS allowance from its plan (or a per-company
    | override). When exhausted, OTP SMS falls back to email and non-critical
    | notifications are skipped until the company tops up. `default_quota` applies
    | when a plan has no allowance set.
    */
    'quota' => [
        'enabled'        => env('SMS_QUOTA_ENABLED', true),
        'default_quota'  => (int) env('SMS_QUOTA_DEFAULT', 100),
        'rand_per_sms'   => (float) env('SMS_RAND_PER_SMS', 0.30),
        'warn_threshold' => (float) env('SMS_QUOTA_WARN_AT', 0.8),
        'chars_per_segment' => 160,
        // Instant-buy pricing: Rand per pack, and SMS per pack.
        'pack_sms'   => (int) env('SMS_QUOTA_PACK_SMS', 100),
        'pack_price' => (float) env('SMS_QUOTA_PACK_PRICE', 30),
    ],

];
