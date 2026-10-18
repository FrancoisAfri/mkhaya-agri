<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TIME_LIMIT — the interval between two dates on the subject is within the configured limit
 * (§5.3 "time limits").
 *
 * parameters:
 *   from_field   string        a date field on the subject, or 'now'
 *   to_field     string        a date field on the subject, or 'now'
 *   max_days     int|null      the interval may not exceed this
 *   min_days     int|null      the interval may not be shorter than this
 *
 * Both directions matter and they catch different failures. `max_days` is the overdue check —
 * evaluation must conclude within 14 days of the tender closing. `min_days` is the *advertising
 * period* check — a tender open for three days when the framework requires 21 is a far more serious
 * finding, and it is invisible to any rule that only looks for lateness.
 *
 * A missing date skips rather than fails: the absent date is a REQUIRED_FIELD problem, and
 * reporting it twice in different words helps nobody.
 */
class TimeLimitHandler implements RuleHandler
{
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $fromField = (string) ($parameters['from_field'] ?? '');
        $toField = (string) ($parameters['to_field'] ?? 'now');

        if ($fromField === '') {
            return RuleOutcome::skip('The rule does not say which dates to measure between.');
        }

        $from = $this->resolve($subject, $fromField);
        $to = $this->resolve($subject, $toField);

        if ($from === null || $to === null) {
            $missing = $from === null ? $fromField : $toField;

            return RuleOutcome::skip(Str::headline($missing).' has no date, so the interval cannot be measured.');
        }

        // Whole days, signed: a `to` earlier than `from` is a real data problem and should surface
        // as a negative interval rather than silently reading as zero.
        $days = $from->startOfDay()->diffInDays($to->startOfDay(), false);

        $maxDays = isset($parameters['max_days']) ? (int) $parameters['max_days'] : null;
        $minDays = isset($parameters['min_days']) ? (int) $parameters['min_days'] : null;

        $detail = [
            'from_field' => $fromField,
            'to_field' => $toField,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'max_days' => $maxDays,
            'min_days' => $minDays,
        ];

        if ($maxDays !== null && $days > $maxDays) {
            return RuleOutcome::fail(
                sprintf(
                    '%s to %s took %d days; the limit is %d.',
                    Str::headline($fromField),
                    $toField === 'now' ? 'today' : Str::headline($toField),
                    $days,
                    $maxDays,
                ),
                $detail,
            );
        }

        if ($minDays !== null && $days < $minDays) {
            return RuleOutcome::fail(
                sprintf(
                    '%s to %s is %d days; a minimum of %d is required.',
                    Str::headline($fromField),
                    $toField === 'now' ? 'today' : Str::headline($toField),
                    $days,
                    $minDays,
                ),
                $detail,
            );
        }

        return RuleOutcome::pass(null, $detail);
    }

    private function resolve(RuleSubject $subject, string $field): ?Carbon
    {
        if ($field === 'now' || $field === 'today') {
            return Carbon::now();
        }

        $value = $subject->field($field);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse(is_string($value) ? $value : (string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
