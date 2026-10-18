<?php

namespace App\Modules\Operations\ProjectManagement\Support\Derivation;

/**
 * One derived number, with everything needed to defend it (PM-1202).
 *
 * `evidenceUuids` is the drill-down: the exact events behind the figure, so "why does this say 62%?"
 * is answered by a list rather than by rerunning the engine and hoping it lands the same way.
 */
final class DerivedFigure
{
    /**
     * @param  list<string>  $evidenceUuids
     */
    public function __construct(
        public readonly string $metric,
        public readonly ?float $value,
        public readonly ?string $text,
        public readonly float $confidence,
        public readonly string $kind,
        public readonly string $because,
        public readonly array $evidenceUuids,
        public readonly string $methodVersion = DerivationRules::VERSION,
    ) {
    }

    public function count(): int
    {
        return count($this->evidenceUuids);
    }
}
