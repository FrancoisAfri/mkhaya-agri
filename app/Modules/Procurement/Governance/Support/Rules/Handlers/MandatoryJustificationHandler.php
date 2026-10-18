<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Str;

/**
 * MANDATORY_JUSTIFICATION — the narrative fields say something (§5.4 "required justification").
 *
 * parameters:
 *   fields          list<string>  default ['business_justification']
 *   min_length      int           default 100 characters
 *   min_words       int|null      an alternative measure
 *   forbid_phrases  list<string>  reject placeholders like "n/a", "as discussed", "see attached"
 *
 * A separate rule from REQUIRED_FIELD because the failure mode is different and much more common:
 * the field is not empty, it says "urgent". A justification is the document an auditor reads when
 * they want to know why a competitive process was bypassed, and a length floor plus a placeholder
 * blacklist is a crude but genuinely effective filter — crude on purpose, because judging the
 * *quality* of a justification is a human's job and the system should not pretend otherwise.
 */
class MandatoryJustificationHandler implements RuleHandler
{
    private const DEFAULT_PLACEHOLDERS = ['n/a', 'na', 'none', 'tbc', 'tbd', 'as discussed', 'see attached', '-', '.'];

    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $fields = array_values(array_filter((array) ($parameters['fields'] ?? ['business_justification'])));
        $minLength = (int) ($parameters['min_length'] ?? 100);
        $minWords = isset($parameters['min_words']) ? (int) $parameters['min_words'] : null;
        $forbidden = array_map(
            'mb_strtolower',
            array_values(array_filter((array) ($parameters['forbid_phrases'] ?? self::DEFAULT_PLACEHOLDERS)))
        );

        $problems = [];

        foreach ($fields as $field) {
            $label = Str::headline($field);
            $value = trim((string) ($subject->field($field) ?? ''));

            if ($value === '') {
                $problems[] = "{$label} is empty";

                continue;
            }

            if (in_array(mb_strtolower($value), $forbidden, true)) {
                $problems[] = "{$label} is a placeholder (\"{$value}\")";

                continue;
            }

            if ($minLength > 0 && mb_strlen($value) < $minLength) {
                $problems[] = sprintf('%s is %d characters; at least %d are required', $label, mb_strlen($value), $minLength);

                continue;
            }

            if ($minWords !== null && str_word_count($value) < $minWords) {
                $problems[] = sprintf('%s is %d words; at least %d are required', $label, str_word_count($value), $minWords);
            }
        }

        if ($problems === []) {
            return RuleOutcome::pass();
        }

        return RuleOutcome::fail(
            ucfirst(implode('; ', $problems)).'.',
            ['fields' => $fields, 'min_length' => $minLength, 'min_words' => $minWords],
        );
    }
}
