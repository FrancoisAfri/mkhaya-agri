<?php

namespace App\Modules\Operations\ProjectManagement\Support\Evidence;

/**
 * Checks an event's payload against the contract its type declares (PM-1001).
 *
 * Two rules that look contradictory but are not:
 *
 *  - a **known** type must satisfy its contract, or the event is rejected to the dead-letter table
 *    rather than stored half-formed;
 *  - an **unknown** type is accepted and stored, because a newer client must be able to send an
 *    event this server has not heard of yet without losing it. It simply carries no confidence, so
 *    derivation ignores it until someone declares what it means.
 *
 * Extra fields on a known type are kept for the same reason. The contract says what must be there,
 * not what may not.
 */
class TaxonomyValidator
{
    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>  human-readable problems; empty means acceptable
     */
    public static function validate(string $type, array $payload): array
    {
        $contract = EvidenceTaxonomy::contractFor($type);

        if ($contract === null) {
            // Unknown type: nothing to check against, and that is not an error (forward compatibility).
            return [];
        }

        $errors = [];

        foreach ($contract['requires'] as $field => $expected) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $errors[] = "payload.{$field} is required for {$type}.";

                continue;
            }

            if (! self::isType($payload[$field], $expected)) {
                $errors[] = "payload.{$field} must be {$expected} for {$type}.";
            }
        }

        foreach ($contract['optional'] as $field => $expected) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && ! self::isType($payload[$field], $expected)) {
                $errors[] = "payload.{$field} must be {$expected} when present.";
            }
        }

        return $errors;
    }

    /**
     * Whether a type is known well enough for its evidence to move a number.
     *
     * Used by derivation (PM-1200): an unknown type is history, not input.
     */
    public static function countsTowardsDerivation(string $type): bool
    {
        return EvidenceTaxonomy::knows($type);
    }

    private static function isType(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            // A JSON number arrives as int or float; a decimal string is also acceptable, since
            // money and measurements travel as strings on this platform (PM-ADR-006).
            'number'  => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'array'   => is_array($value),
            default   => is_string($value) || is_numeric($value),
        };
    }
}
