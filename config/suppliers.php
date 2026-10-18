<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credential lifetimes
    |--------------------------------------------------------------------------
    |
    | Both of these are links that stand in for a login, so both need a horizon.
    | The onboarding invitation is short because it is a single errand: register,
    | then it is spent. The portal link is long because the supplier goes back to
    | it for the life of the relationship — long enough not to be a nuisance,
    | short enough that a leaked URL eventually stops working on its own.
    |
    | A supplier whose token predates this setting has a null expiry and keeps
    | working; rotating the link is what gives it a date.
    |
    */

    'onboarding_token_hours' => env('SUPPLIER_ONBOARDING_TOKEN_HOURS', 24),

    'portal_token_days' => env('SUPPLIER_PORTAL_TOKEN_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Invoice matching tolerance
    |--------------------------------------------------------------------------
    |
    | How far above its purchase order an invoice may sit and still be treated as
    | a rounding difference rather than something a person should look at. Over
    | this, the invoice is held as a variance and cannot be marked paid until the
    | variance is resolved.
    |
    */

    'invoice_tolerance_percent' => env('SUPPLIER_INVOICE_TOLERANCE_PERCENT', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Performance threshold
    |--------------------------------------------------------------------------
    |
    | An appraisal score at or below this flags the supplier for review. It does
    | not suspend them — a single bad quarter is a conversation, not a divorce —
    | but it raises a risk flag so the next order is a decision somebody makes
    | rather than one that happens by default.
    |
    */

    'appraisal_review_threshold' => env('SUPPLIER_APPRAISAL_REVIEW_THRESHOLD', 50),

];
