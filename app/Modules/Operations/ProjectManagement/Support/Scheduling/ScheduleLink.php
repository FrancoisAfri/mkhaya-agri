<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use InvalidArgumentException;

/**
 * A dependency between two tasks, with lag (PM-1123).
 *
 *   fs  successor starts after the predecessor finishes   (the usual one)
 *   ss  successor starts after the predecessor starts     (work running alongside)
 *   ff  successor finishes after the predecessor finishes (commissioning trailing installation)
 *   sf  successor finishes after the predecessor starts   (a changeover; rare, but real)
 *
 * Lag is working minutes and may be negative — a lead, meaning the successor may overlap.
 */
final class ScheduleLink
{
    public const TYPES = ['fs', 'ss', 'ff', 'sf'];

    public function __construct(
        public readonly string $predecessorId,
        public readonly string $successorId,
        public readonly string $type = 'fs',
        public readonly int $lagMinutes = 0,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "'{$type}' is not a dependency type. Use one of: " . implode(', ', self::TYPES) . '.'
            );
        }

        if ($predecessorId === $successorId) {
            throw new InvalidArgumentException("A task cannot depend on itself ('{$predecessorId}').");
        }
    }
}
