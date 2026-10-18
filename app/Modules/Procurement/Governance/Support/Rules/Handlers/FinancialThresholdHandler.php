<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;

/**
 * FINANCIAL_THRESHOLD — the value band dictates how the procurement must be run
 * (§5.3 "financial thresholds").
 *
 * parameters:
 *   required_methods  list<string>  in this band, the method must be one of these
 *   forbidden_methods list<string>  in this band, these methods are not permitted
 *   min_quotes        int|null      minimum competing quotes/bids required in this band
 *   max_value         float|null    a hard ceiling for this route, whatever the method
 *
 * The band itself is the rule's `conditions`, not a parameter — that is what makes the whole
 * threshold table configurable: "up to R30k: three verbal quotes; R30k–R300k: three written
 * quotes; above R300k: open tender" is three rules with three value bands and different
 * `required_methods`, and no code anywhere knows those numbers.
 */
class FinancialThresholdHandler implements RuleHandler
{
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        if ($subject->amount === null) {
            return RuleOutcome::skip('No value has been captured, so the threshold cannot be applied.');
        }

        $required = array_values(array_filter((array) ($parameters['required_methods'] ?? [])));
        $forbidden = array_values(array_filter((array) ($parameters['forbidden_methods'] ?? [])));
        $minQuotes = isset($parameters['min_quotes']) ? (int) $parameters['min_quotes'] : null;
        $maxValue = isset($parameters['max_value']) ? (float) $parameters['max_value'] : null;

        $failures = [];
        $detail = ['amount' => $subject->amount, 'method' => $subject->method];

        if ($maxValue !== null && $subject->amount > $maxValue) {
            $failures[] = sprintf(
                'the value of %s exceeds the %s ceiling for this route',
                number_format($subject->amount, 2),
                number_format($maxValue, 2),
            );
            $detail['ceiling'] = $maxValue;
        }

        if ($required !== []) {
            if ($subject->method === null) {
                $failures[] = 'no procurement method has been recorded, and this value band requires '
                    .implode(' or ', $required);
            } elseif (! $this->contains($required, $subject->method)) {
                $failures[] = sprintf(
                    'this value band requires %s, not %s',
                    implode(' or ', $required),
                    $subject->method,
                );
            }
            $detail['required_methods'] = $required;
        }

        if ($forbidden !== [] && $subject->method !== null && $this->contains($forbidden, $subject->method)) {
            $failures[] = "{$subject->method} is not permitted at this value";
            $detail['forbidden_methods'] = $forbidden;
        }

        if ($minQuotes !== null) {
            // Supplied by the caller when it knows: bidder count for a review, quote count for a
            // requisition. Absent means the subject has no notion of competing quotes.
            $quotes = $subject->meta['quote_count'] ?? null;

            if ($quotes === null) {
                $failures[] = "at least {$minQuotes} competing quotes are required and none have been recorded";
            } elseif ((int) $quotes < $minQuotes) {
                $failures[] = "at least {$minQuotes} competing quotes are required; {$quotes} recorded";
            }
            $detail['min_quotes'] = $minQuotes;
            $detail['quote_count'] = $quotes;
        }

        if ($failures === []) {
            return RuleOutcome::pass();
        }

        return RuleOutcome::fail(ucfirst(implode('; ', $failures)).'.', $detail);
    }

    private function contains(array $haystack, string $needle): bool
    {
        foreach ($haystack as $candidate) {
            if (strcasecmp((string) $candidate, $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
