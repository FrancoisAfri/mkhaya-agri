<?php

namespace App\Modules\Operations\ProjectManagement\Support\Derivation;

use InvalidArgumentException;

/**
 * Turns evidence into figures, by the rules in DerivationRules (PM-1200…1202).
 *
 * Pure: events in, numbers out. No database, no clock. That is what makes it possible to answer
 * "what would progress say if this event were withdrawn?" without touching the ledger, and what
 * makes the fixtures worth trusting.
 *
 * The strongest rule with any evidence behind it wins outright — it does not blend with weaker
 * ones. A measured 40% and a claimed 80% do not average to 60%: the tape measure is right and the
 * optimism is noise. Blending would also mean nobody could say which events produced the number,
 * which is the whole point of the drill-down.
 */
final class DerivationEngine
{
    /**
     * @param  list<EvidenceRow>  $evidence
     * @param  array<string,mixed>  $context  subject facts a rule may measure against, e.g. planned_quantity
     * @return array<string, DerivedFigure>  keyed by metric
     */
    public function derive(array $evidence, array $context = [], ?array $metrics = null): array
    {
        // Superseded events are history. A correction that left the original in play would have the
        // ledger arguing with itself, which is precisely what supersede() exists to prevent.
        $usable = array_values(array_filter($evidence, fn (EvidenceRow $e) => ! $e->isSuperseded));

        usort($usable, fn (EvidenceRow $a, EvidenceRow $b) => $a->occurredAt <=> $b->occurredAt);

        $figures = [];

        foreach ($metrics ?? DerivationRules::metrics() as $metric) {
            $figure = $this->deriveOne($metric, $usable, $context);

            if ($figure) {
                $figures[$metric] = $figure;
            }
        }

        return $figures;
    }

    /**
     * @param  list<EvidenceRow>  $evidence  in chronological order
     * @param  array<string,mixed>  $context
     */
    private function deriveOne(string $metric, array $evidence, array $context): ?DerivedFigure
    {
        foreach (DerivationRules::for($metric) as $rule) {
            $matching = $this->matching($evidence, $rule['events']);

            if ($matching === []) {
                continue;
            }

            $figure = $this->apply($metric, $rule, $matching, $context);

            // A rule can match events and still produce nothing — a ratio with no planned quantity
            // to measure against, say. Fall through to the next rule rather than reporting null as
            // an answer, because a weaker source is better than no source.
            if ($figure !== null) {
                return $figure;
            }
        }

        return null;
    }

    /**
     * @param  list<EvidenceRow>  $evidence
     * @param  list<string>  $types
     * @return list<EvidenceRow>
     */
    private function matching(array $evidence, array $types): array
    {
        if (in_array('*', $types, true)) {
            return $evidence;
        }

        return array_values(array_filter($evidence, fn (EvidenceRow $e) => in_array($e->eventType, $types, true)));
    }

    /**
     * @param  array<string,mixed>  $rule
     * @param  list<EvidenceRow>  $matching  chronological
     * @param  array<string,mixed>  $context
     */
    private function apply(string $metric, array $rule, array $matching, array $context): ?DerivedFigure
    {
        $strategy = $rule['strategy'];

        if (! in_array($strategy, DerivationRules::STRATEGIES, true)) {
            throw new InvalidArgumentException("'{$strategy}' is not a derivation strategy.");
        }

        [$value, $text, $used] = match ($strategy) {
            'constant'     => [(float) $rule['value'], null, $matching],
            'latest_field' => $this->latestField($matching, $rule['field']),
            'sum_field'    => $this->sumField($matching, $rule['field']),
            'ratio_of'     => $this->ratio($matching, $rule['field'], $context[$rule['against']] ?? null),
            'latest_time'  => $this->latestTime($matching),
        };

        if ($value === null && $text === null) {
            return null;
        }

        return new DerivedFigure(
            metric: $metric,
            value: $value,
            text: $text,
            // The rule's confidence, reduced by the least confident event behind it: a figure is
            // only as good as the weakest thing it rests on.
            confidence: round((float) $rule['confidence'] * $this->weakest($used), 2),
            kind: $rule['kind'],
            because: $rule['because'],
            evidenceUuids: array_map(fn (EvidenceRow $e) => $e->uuid, $used),
        );
    }

    /**
     * @param  list<EvidenceRow>  $matching
     * @return array{0: float|null, 1: string|null, 2: list<EvidenceRow>}
     */
    private function latestField(array $matching, string $field): array
    {
        // Walk backwards to the most recent event that actually carries the field: an event missing
        // it should not blank a figure a earlier, complete one already established.
        foreach (array_reverse($matching) as $event) {
            $value = $event->number($field);

            if ($value !== null) {
                return [$value, null, [$event]];
            }
        }

        return [null, null, []];
    }

    /**
     * @param  list<EvidenceRow>  $matching
     * @return array{0: float|null, 1: string|null, 2: list<EvidenceRow>}
     */
    private function sumField(array $matching, string $field): array
    {
        $total = 0.0;
        $used = [];

        foreach ($matching as $event) {
            $value = $event->number($field);

            if ($value !== null) {
                $total += $value;
                $used[] = $event;
            }
        }

        return $used === [] ? [null, null, []] : [round($total, 4), null, $used];
    }

    /**
     * @param  list<EvidenceRow>  $matching
     * @return array{0: float|null, 1: string|null, 2: list<EvidenceRow>}
     */
    private function ratio(array $matching, string $field, mixed $against): array
    {
        $planned = is_numeric($against) ? (float) $against : null;

        if ($planned === null || $planned <= 0.0) {
            return [null, null, []];
        }

        [$total, , $used] = $this->sumField($matching, $field);

        if ($total === null) {
            return [null, null, []];
        }

        // Capped at 100: over-delivery against a planned quantity is a real and useful signal, but
        // "127% complete" on a progress bar is not something anyone can act on. The raw quantity
        // stays available as qty_installed.
        return [min(round(($total / $planned) * 100, 2), 100.0), null, $used];
    }

    /**
     * @param  list<EvidenceRow>  $matching
     * @return array{0: float|null, 1: string|null, 2: list<EvidenceRow>}
     */
    private function latestTime(array $matching): array
    {
        $last = end($matching);

        return $last === false
            ? [null, null, []]
            : [null, $last->occurredAt->toDateTimeString(), [$last]];
    }

    /** @param list<EvidenceRow> $used */
    private function weakest(array $used): float
    {
        $lowest = 1.0;

        foreach ($used as $event) {
            $lowest = min($lowest, $event->confidence);
        }

        return $lowest;
    }
}
