<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use Carbon\CarbonImmutable;

/** Everything one run of the engine produced. */
final class ScheduleResult
{
    /**
     * @param  array<string, ScheduledDates>  $tasks
     * @param  list<string>  $criticalPath  in order, the longest chain with no float
     * @param  list<ScheduleConflict>  $conflicts
     */
    public function __construct(
        public readonly array $tasks,
        public readonly array $criticalPath,
        public readonly array $conflicts,
        public readonly ?CarbonImmutable $start,
        public readonly ?CarbonImmutable $finish,
    ) {
    }

    public function has(string $taskId): bool
    {
        return isset($this->tasks[$taskId]);
    }
}
