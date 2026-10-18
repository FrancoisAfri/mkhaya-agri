<?php

/**
 * Payroll conventions that differ legitimately between employers.
 */
return [
    /*
     * How a part month is worked out for somebody who joined or left mid-period.
     *
     * "working" counts days that are not weekends and not public holidays, which is what most
     * South African monthly payrolls use — a person starting on the Monday of the last week gets a
     * fifth of the month rather than a quarter of it. "calendar" counts every day. Both are
     * defensible; the employment contract decides which, so it is a setting rather than a rule.
     */
    'pro_rata_basis' => env('PAYROLL_PRO_RATA_BASIS', 'working'),

    /*
     * The least an employee keeps once creditors have been paid.
     *
     * Not a statutory figure — South African law caps particular deductions rather than setting one
     * floor — but taking somebody's pay to zero is never the right answer. Where the deductions
     * would breach this, the lowest-priority ones are trimmed and the shortfall is reported, so a
     * creditor can be told rather than the employee finding out on payday.
     */
    'minimum_take_home_fraction' => (float) env('PAYROLL_MIN_TAKE_HOME', 0.25),
];
