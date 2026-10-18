<?php

namespace App\Modules\Operations\ProjectManagement\Support\Derivation;

/**
 * How evidence becomes a number (PM-1200, PM-1201).
 *
 * Declarative on purpose. These rules are the answer to "why does this task say 62%?", and that
 * answer has to be readable by someone who does not write PHP — a quantity surveyor arguing about a
 * payment certificate should be able to be shown this table.
 *
 * ── Precedence (PM-1201) ────────────────────────────────────────────────────
 *
 * Rules are listed strongest first, and the first one with any evidence behind it wins outright.
 * The order encodes how much the claim is worth:
 *
 *   1. Verified   — someone independent checked it (milestone.verified, inspection.passed)
 *   2. Definitive — the work is recorded as finished or not begun (task.completed, task.started)
 *   3. Measured   — a quantity someone counted (quantity.installed against a planned quantity)
 *   4. Claimed    — a person typed a percentage (progress.reported)
 *
 * A measured 40% beats a claimed 80%, and that is the point. Averaging them, or letting the most
 * recent win regardless of kind, would let anyone raise the reported progress of a job by typing a
 * bigger number than the one the tape measure gave.
 *
 * ── Versioning ──────────────────────────────────────────────────────────────
 *
 * VERSION moves whenever the rules change. Stored values keep the version that produced them, so a
 * change in method never silently reinterprets old numbers: they are recomputed, and until they
 * are, they are honestly labelled as having come from the old one.
 */
final class DerivationRules
{
    /**
     * Bump on ANY change to the rules below — including a confidence figure. A number whose
     * provenance is wrong is worse than a number that is merely stale.
     */
    public const VERSION = '1.0.0';

    /** Strategies a rule may use to turn matching events into a value. */
    public const STRATEGIES = ['constant', 'latest_field', 'sum_field', 'ratio_of', 'latest_time'];

    /**
     * metric => ordered rules, strongest first.
     *
     * `confidence` is how much the module is willing to stand behind a value from this source. It
     * is surfaced, not hidden: a 0.6 on a self-reported percentage tells a reader exactly how much
     * weight to give it.
     *
     * @var array<string, list<array<string,mixed>>>
     */
    public const RULES = [
        'progress_pct' => [
            [
                'kind'       => 'verified',
                'events'     => ['milestone.verified'],
                'strategy'   => 'constant',
                'value'      => 100.0,
                'confidence' => 1.00,
                'because'    => 'A verified milestone is complete by definition.',
            ],
            [
                'kind'       => 'definitive',
                'events'     => ['task.completed'],
                'strategy'   => 'constant',
                'value'      => 100.0,
                'confidence' => 1.00,
                'because'    => 'The task was recorded as finished.',
            ],
            [
                'kind'       => 'measured',
                'events'     => ['quantity.installed'],
                'strategy'   => 'ratio_of',
                'field'      => 'quantity',
                'against'    => 'planned_quantity',
                'confidence' => 0.95,
                'because'    => 'Installed quantity measured against the planned quantity.',
            ],
            [
                'kind'       => 'claimed',
                'events'     => ['progress.reported'],
                'strategy'   => 'latest_field',
                'field'      => 'percent',
                'confidence' => 0.60,
                'because'    => 'Someone reported this percentage; nothing has measured it.',
            ],
            [
                'kind'       => 'definitive',
                'events'     => ['task.started'],
                'strategy'   => 'constant',
                'value'      => 5.0,
                'confidence' => 0.90,
                'because'    => 'Work has started but nothing has been measured yet.',
            ],
        ],

        'actual_cost' => [
            [
                'kind'       => 'definitive',
                'events'     => ['invoice.paid'],
                'strategy'   => 'sum_field',
                'field'      => 'amount',
                'confidence' => 1.00,
                'because'    => 'Money that has actually left the account.',
            ],
            [
                'kind'       => 'measured',
                'events'     => ['invoice.received', 'cost.incurred'],
                'strategy'   => 'sum_field',
                'field'      => 'amount',
                'confidence' => 0.85,
                'because'    => 'Committed cost — invoiced or incurred, not yet paid.',
            ],
        ],

        'actual_hours' => [
            [
                'kind'       => 'verified',
                'events'     => ['time.approved'],
                'strategy'   => 'sum_field',
                'field'      => 'hours',
                'confidence' => 1.00,
                'because'    => 'Approved timesheet hours.',
            ],
            [
                'kind'       => 'claimed',
                'events'     => ['time.logged', 'hours.booked'],
                'strategy'   => 'sum_field',
                'field'      => 'hours',
                'confidence' => 0.70,
                'because'    => 'Hours logged but not yet approved.',
            ],
        ],

        'qty_installed' => [
            [
                'kind'       => 'measured',
                'events'     => ['quantity.installed'],
                'strategy'   => 'sum_field',
                'field'      => 'quantity',
                'confidence' => 0.95,
                'because'    => 'Sum of quantities recorded as installed.',
            ],
        ],

        'last_activity_at' => [
            [
                'kind'       => 'definitive',
                'events'     => ['*'],
                'strategy'   => 'latest_time',
                'confidence' => 1.00,
                'because'    => 'The most recent evidence of any kind on this subject.',
            ],
        ],
    ];

    /** @return list<array<string,mixed>> */
    public static function for(string $metric): array
    {
        return self::RULES[$metric] ?? [];
    }

    /** @return list<string> */
    public static function metrics(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Every event type that feeds any rule — what an incremental recompute watches for.
     *
     * @return list<string>
     */
    public static function watchedEvents(): array
    {
        $events = [];

        foreach (self::RULES as $rules) {
            foreach ($rules as $rule) {
                $events = array_merge($events, $rule['events']);
            }
        }

        return array_values(array_unique(array_filter($events, fn (string $e) => $e !== '*')));
    }
}
