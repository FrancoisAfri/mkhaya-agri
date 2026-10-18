<?php

/*
| Tax Management & Compliance. Thresholds here are engineering defaults; NSEZ's tax representatives
| confirm the values before production reliance (SRS §28).
*/
return [
    // Rand amount above which a reconciliation difference must be explained before sign-off (TAX-REC-007).
    'reconciliation_tolerance' => env('TAX_RECONCILIATION_TOLERANCE', '1.00'),

    // Days before a deadline on which the owner is reminded (§14). Per-deadline overrides are allowed.
    'reminder_days' => [14, 7, 3, 1],

    // How far ahead periods (and their deadlines) are generated for active registrations.
    'generate_months_ahead' => 1,

    // Assessed loss set-off for companies (Income Tax Act s20, years of assessment ending on or after
    // 31 March 2023): the loss brought forward may reduce taxable income by the greater of this amount
    // or this percentage of taxable income before set-off. Confirm before reliance (SRS §28).
    'assessed_loss' => [
        'cap_amount' => env('TAX_ASSESSED_LOSS_CAP_AMOUNT', '1000000.00'),
        'cap_percent' => env('TAX_ASSESSED_LOSS_CAP_PERCENT', '80'),
    ],

    // Provisional tax: share of the year's estimated tax due with the first payment.
    'provisional_first_share' => '50',
];
