<?php

namespace App\Modules\Operations\ProjectManagement\Support\Derivation;

use Carbon\CarbonImmutable;

/**
 * One evidence event as the derivation engine sees it.
 *
 * A plain value rather than the Eloquent model, for the same reason the scheduling engine takes
 * plain tasks (PM-ADR-005): the rules can then be tested against a handful of literals, and run
 * against a hypothetical set of events for "what would this say if the survey came back at 40%?".
 */
final class EvidenceRow
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $eventType,
        public readonly CarbonImmutable $occurredAt,
        public readonly array $payload = [],
        public readonly float $confidence = 1.0,
        /** A superseded event is history, not input — see EvidenceLedger::supersede(). */
        public readonly bool $isSuperseded = false,
    ) {
    }

    public function number(string $field): ?float
    {
        $value = $this->payload[$field] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
