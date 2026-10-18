<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

/**
 * The answer to "is this person free?", with its own honesty about what was checked.
 *
 * `checked` lists what the provider actually consulted — leave, attendance, existing allocations.
 * A UI that says "available" when only PM's own bookings were checked is making a promise the
 * system did not verify, so the caller is given the means to say "available (leave not checked)".
 */
final class AvailabilityResult
{
    /**
     * @param  list<string>  $checked
     * @param  list<string>  $conflicts  human-readable reasons, already translated by the caller
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $checked = [],
        public readonly array $conflicts = [],
    ) {
    }

    public static function unknown(): self
    {
        return new self(true, [], []);
    }
}
