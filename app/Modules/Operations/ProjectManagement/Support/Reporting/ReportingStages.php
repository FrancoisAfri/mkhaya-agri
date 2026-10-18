<?php

namespace App\Modules\Operations\ProjectManagement\Support\Reporting;

/**
 * The stage words the client uses on their monthly report.
 *
 * Deliberately **not** the module's project stages (initiation, planning, execution, monitoring,
 * closing). Those describe how Project Management thinks about a job; these are what the client's
 * report says, and the two are not the same vocabulary. "Close out/partial" and
 * "Assessment/scoping" are not stages any generic model would offer, and forcing the report onto
 * generic ones would make it a different document.
 *
 * Suggestions, not a closed list: the field stores free text, so a practice can write whatever its
 * report says. These are what the list offers before anybody edits it.
 */
final class ReportingStages
{
    /** @var list<string> */
    private const STAGES = [
        'Inception',
        'Scoping',
        'Assessment/scoping',
        'Preliminary design',
        'Detail design',
        'Construction Drawings & Tender Documentation',
        'Tender',
        'Construction',
        'Redesign',
        'On hold',
        'Closeout',
        'Close out/partial',
    ];

    /**
     * Statuses the client's report uses.
     *
     * Kept as its own field rather than folded into a generic "health", because the client enters
     * the status they use — "On Hold" appears repeatedly in their document — and a red/amber/green
     * chip would be a different question with a different answer.
     *
     * @var list<string>
     */
    private const STATUSES = [
        'On track',
        'On Hold',
        'Delayed',
        'At risk',
        'Completed',
        'Cancelled',
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function stages(): array
    {
        return self::STAGES;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return self::STATUSES;
    }
}
