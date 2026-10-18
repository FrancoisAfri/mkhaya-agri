<?php

namespace App\Modules\Procurement\Governance\Support\Rules;

/**
 * Decides whether a configured rule, approval chain or chain step applies to a subject.
 *
 * One vocabulary is shared by all three (§5.2 selects approval chains by "value, department,
 * procurement category, deviation type, risk level"; §5.3 narrows rules the same way), so the
 * matching lives in one place and cannot drift between them:
 *
 *   {
 *     "min_value": 500000, "max_value": 1000000,
 *     "categories": ["ICT","Construction"],
 *     "company_unit_ids": [12, 14],
 *     "methods": ["SOLE_SOURCE"],
 *     "subtypes": ["emergency"],
 *     "risk_levels": ["HIGH","CRITICAL"],
 *     "statuses": ["SUBMITTED"]
 *   }
 *
 * Every clause present must match; absent clauses do not constrain. `min_value` is inclusive and
 * `max_value` exclusive, so adjacent bands (0–500k, 500k–1m) tile without overlap or gap — bands
 * written with both ends inclusive are the classic source of "two chains matched" bugs.
 *
 * A null amount does not satisfy a value band. A rule that says "above R1m" must not fire on a
 * subject whose value nobody has captured yet; the missing value is caught by a REQUIRED_FIELD
 * rule instead, which reports the real problem.
 */
class ConditionMatcher
{
    public function matches(?array $conditions, RuleSubject $subject): bool
    {
        if (empty($conditions)) {
            return true;
        }

        if (! $this->matchesValueBand($conditions, $subject->amount)) {
            return false;
        }

        $listChecks = [
            'categories' => $subject->category,
            'company_unit_ids' => $subject->companyUnitId,
            'methods' => $subject->method,
            'subtypes' => $subject->subtype,
            'risk_levels' => $subject->riskLevel,
            'statuses' => $subject->status,
        ];

        foreach ($listChecks as $key => $value) {
            if (! $this->matchesList($conditions[$key] ?? null, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How specific a set of conditions is, used to pick between several matching approval chains.
     * More clauses, and narrower value bands, win. Deterministic so the same subject always routes
     * the same way — an approval chain that varies between two identical requests is indefensible
     * at audit.
     */
    public function specificity(?array $conditions): int
    {
        if (empty($conditions)) {
            return 0;
        }

        $score = 0;

        foreach (['categories', 'company_unit_ids', 'methods', 'subtypes', 'risk_levels', 'statuses'] as $key) {
            if (! empty($conditions[$key])) {
                // A one-value list is more specific than a five-value one.
                $score += 10 + max(0, 5 - count((array) $conditions[$key]));
            }
        }

        if (isset($conditions['min_value'])) {
            $score += 10;
        }

        if (isset($conditions['max_value'])) {
            $score += 10;
        }

        return $score;
    }

    private function matchesValueBand(array $conditions, ?float $amount): bool
    {
        $hasMin = isset($conditions['min_value']) && $conditions['min_value'] !== '';
        $hasMax = isset($conditions['max_value']) && $conditions['max_value'] !== '';

        if (! $hasMin && ! $hasMax) {
            return true;
        }

        if ($amount === null) {
            return false;   // see the class docblock
        }

        if ($hasMin && $amount < (float) $conditions['min_value']) {
            return false;
        }

        if ($hasMax && $amount >= (float) $conditions['max_value']) {
            return false;
        }

        return true;
    }

    private function matchesList(mixed $allowed, mixed $value): bool
    {
        if (empty($allowed)) {
            return true;
        }

        if ($value === null) {
            return false;
        }

        // Loose comparison on purpose: ids arrive as ints from the subject and as strings from
        // JSON-configured conditions, and a strict check would silently never match.
        foreach ((array) $allowed as $candidate) {
            if (is_string($candidate) && is_string($value)) {
                if (strcasecmp($candidate, $value) === 0) {
                    return true;
                }

                continue;
            }

            if ((string) $candidate === (string) $value) {
                return true;
            }
        }

        return false;
    }
}
