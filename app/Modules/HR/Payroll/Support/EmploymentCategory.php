<?php

namespace App\Modules\HR\Payroll\Support;

/**
 * What kind of employment somebody is on, for payroll purposes.
 *
 * One vocabulary shared by both engines, deliberately separate from the in-house
 * `hr_people.employment_type` integer — that field is employment-equity reporting and has exactly
 * two values, permanent and temporary. Overloading it would have been quicker and would have quietly
 * changed what every existing EE report meant.
 *
 * The categories that matter here are the ones the Employment Tax Incentive is written for: an
 * intern and a learner are the reason the incentive exists, and they are the people a payroll
 * administrator is asked to watch milestones on.
 */
class EmploymentCategory
{
    public const PERMANENT   = 'permanent';
    public const FIXED_TERM  = 'fixed_term';
    public const INTERN      = 'intern';
    public const LEARNERSHIP = 'learnership';
    public const CASUAL      = 'casual';

    public const ALL = [
        self::PERMANENT,
        self::FIXED_TERM,
        self::INTERN,
        self::LEARNERSHIP,
        self::CASUAL,
    ];

    /**
     * The categories that start ETI tracking the moment somebody is added.
     *
     * Being one of these does not by itself earn the incentive — age and remuneration still decide
     * that, run by run. It means the employer is opted in and the clock is started, so nobody has to
     * remember to tick a box on the day an intern joins. Missing that tick is how a client loses
     * months of a benefit nobody notices was never claimed.
     */
    public const ETI_TRACKED = [self::INTERN, self::LEARNERSHIP];

    public static function tracksEti(?string $category): bool
    {
        return in_array($category, self::ETI_TRACKED, true);
    }

    public static function isValid(?string $category): bool
    {
        return $category === null || in_array($category, self::ALL, true);
    }

    /** For a picker: the value and something a person would recognise. */
    public static function options(): array
    {
        return [
            ['value' => self::PERMANENT,   'title' => 'Permanent'],
            ['value' => self::FIXED_TERM,  'title' => 'Fixed term'],
            ['value' => self::INTERN,      'title' => 'Intern'],
            ['value' => self::LEARNERSHIP, 'title' => 'Learnership'],
            ['value' => self::CASUAL,      'title' => 'Casual'],
        ];
    }
}
