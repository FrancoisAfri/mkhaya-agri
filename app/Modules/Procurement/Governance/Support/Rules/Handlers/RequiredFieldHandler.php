<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Str;

/**
 * REQUIRED_FIELD — the subject must have values for the named fields (§5.3 "mandatory fields").
 *
 * parameters:
 *   fields  list<string>          field names on the subject
 *   labels  array<string,string>  optional display names, so the message reads
 *                                 "Risk assessment is required" rather than "risk_assessment"
 *
 * Deliberately separate from Laravel validation: these are mandatory *to submit for approval*, not
 * mandatory *to save a draft*. Someone must be able to capture half an emergency deviation at
 * 17:00 and finish it in the morning without the form refusing to save.
 */
class RequiredFieldHandler implements RuleHandler
{
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $fields = array_values(array_filter((array) ($parameters['fields'] ?? [])));
        $labels = (array) ($parameters['labels'] ?? []);

        if ($fields === []) {
            return RuleOutcome::skip('The rule names no fields to check.');
        }

        $missing = [];

        foreach ($fields as $field) {
            if ($subject->fieldIsBlank($field)) {
                $missing[] = $labels[$field] ?? Str::headline($field);
            }
        }

        if ($missing === []) {
            return RuleOutcome::pass();
        }

        return RuleOutcome::fail(
            count($missing) === 1
                ? "{$missing[0]} is required."
                : 'These are required: '.implode(', ', $missing).'.',
            ['missing_fields' => $missing],
        );
    }
}
