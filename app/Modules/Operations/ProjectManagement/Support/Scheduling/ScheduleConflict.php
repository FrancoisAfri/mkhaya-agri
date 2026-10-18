<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

/**
 * A clash the schedule cannot resolve, stated in a sentence a site manager can act on (PM-1235).
 *
 * Not an error: the engine still produces a schedule. It says which date it honoured and what that
 * broke, because "constraint violation on task 41" tells nobody what to do about it.
 */
final class ScheduleConflict
{
    public const KINDS = ['constraint_vs_predecessor', 'deadline_missed', 'negative_float'];

    public function __construct(
        public readonly string $taskId,
        public readonly string $kind,
        public readonly string $message,
    ) {
    }
}
