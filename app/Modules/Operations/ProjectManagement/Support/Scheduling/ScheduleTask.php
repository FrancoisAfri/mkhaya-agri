<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One task as the engine sees it (PM-ADR-005).
 *
 * Deliberately not the Eloquent model: the engine takes plain values so it can be run against a
 * hypothetical network for what-if analysis, and so its tests need no database. A service maps
 * between the two.
 */
final class ScheduleTask
{
    /** MS-Project constraint semantics, so an imported plan means the same thing here. */
    public const CONSTRAINTS = ['asap', 'alap', 'snet', 'snlt', 'fnet', 'fnlt', 'mso', 'mfo'];

    public function __construct(
        public readonly string $id,
        public readonly int $durationMinutes,
        public readonly string $scheduleMode = 'auto',
        public readonly ?string $constraintType = null,
        public readonly ?CarbonImmutable $constraintDate = null,
        /** What actually happened. Overrides the plan — a schedule that argues with reality is worthless. */
        public readonly ?CarbonImmutable $actualStart = null,
        public readonly ?CarbonImmutable $actualEnd = null,
        /** Where a manually-scheduled task was put by hand. */
        public readonly ?CarbonImmutable $manualStart = null,
        public readonly ?string $name = null,
    ) {
        if ($durationMinutes < 0) {
            throw new InvalidArgumentException("Task '{$id}' has a negative duration.");
        }

        if (! in_array($scheduleMode, ['auto', 'manual'], true)) {
            throw new InvalidArgumentException("Task '{$id}' has an unknown schedule mode '{$scheduleMode}'.");
        }

        if ($constraintType !== null && ! in_array($constraintType, self::CONSTRAINTS, true)) {
            throw new InvalidArgumentException("Task '{$id}' has an unknown constraint '{$constraintType}'.");
        }

        // A constraint that names no date constrains nothing, and would silently do nothing at all.
        if (in_array($constraintType, ['snet', 'snlt', 'fnet', 'fnlt', 'mso', 'mfo'], true) && $constraintDate === null) {
            throw new InvalidArgumentException(
                "Task '{$id}' has a '{$constraintType}' constraint with no date on it."
            );
        }
    }

    public function isMilestone(): bool
    {
        return $this->durationMinutes === 0;
    }

    public function label(): string
    {
        return $this->name ?: $this->id;
    }
}
