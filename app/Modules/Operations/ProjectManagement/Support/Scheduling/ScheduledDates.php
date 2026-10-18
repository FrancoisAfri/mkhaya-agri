<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use Carbon\CarbonImmutable;

/**
 * What the engine worked out for one task.
 *
 * Total float is how long it can slip before the *project* slips; free float is how long before
 * anything else has to move. They differ, and conflating them is how a manager is told a task has
 * a fortnight spare and then finds three crews idle when they use it.
 */
final class ScheduledDates
{
    public function __construct(
        public readonly CarbonImmutable $earlyStart,
        public readonly CarbonImmutable $earlyFinish,
        public readonly CarbonImmutable $lateStart,
        public readonly CarbonImmutable $lateFinish,
        public readonly int $totalFloatMinutes,
        public readonly int $freeFloatMinutes,
        public readonly bool $isCritical,
    ) {
    }
}
