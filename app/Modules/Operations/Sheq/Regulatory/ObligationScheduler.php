<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use DateInterval;
use Exception;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns a pack's obligation definitions into dated obligations (SHEQ-REG-4, SHEQ-REG-5).
 *
 * The deadline is expressed in the pack as a duration from an anchor event ("P7D from
 * incident.occurred_at"), never as a hard-coded date rule in core. Reminders are *remaining-time*
 * offsets — `P5D` means "when 5 days remain", not "5 days after the anchor" — which reads oddly at
 * first and is exactly what the specification defines. Getting that backwards would fire the last
 * reminder before the first.
 *
 * Timezone matters: a South African site's 7-day clock runs in Africa/Johannesburg, and an
 * obligation due at 14:00 local is not due at 14:00 UTC. The site's timezone is therefore required
 * input, not an ambient default.
 */
class ObligationScheduler
{
    /**
     * @param  list<string>  $obligationIds  as returned by the classifier
     * @return list<ObligationDraft>
     */
    public function schedule(
        PackManifest $pack,
        array $obligationIds,
        Carbon $anchorAt,
        string $timezone = 'Africa/Johannesburg',
    ): array {
        $drafts = [];

        foreach ($obligationIds as $id) {
            $definition = $pack->obligation($id);

            // A classification referencing an obligation the pack does not define is a broken pack.
            // Failing loudly here beats an incident that silently carries no statutory deadline.
            if ($definition === null) {
                throw new InvalidArgumentException(
                    "Regulatory pack {$pack->packId}@{$pack->version} references unknown obligation `{$id}`."
                );
            }

            $drafts[] = $this->draft($id, $definition, $anchorAt->copy()->setTimezone($timezone));
        }

        return $drafts;
    }

    /** @param array<string,mixed> $definition */
    private function draft(string $id, array $definition, Carbon $anchorAt): ObligationDraft
    {
        $businessDays = (bool) ($definition['business_days'] ?? false);
        $dueAt = $this->addDuration($anchorAt, (string) ($definition['due_in'] ?? 'PT0S'), $businessDays);

        // Remaining-time offsets, clamped to the anchor: a reminder cannot fall before the event
        // that started the clock, which a P14D reminder on a P7D deadline would otherwise do.
        $reminders = [];
        foreach ((array) ($definition['reminders'] ?? []) as $offset) {
            $at = $this->subtractDuration($dueAt, (string) $offset);
            $reminders[] = $at->lessThan($anchorAt) ? $anchorAt->copy() : $at;
        }
        usort($reminders, fn (Carbon $a, Carbon $b) => $a <=> $b);

        $escalations = [];
        foreach ((array) ($definition['escalation'] ?? []) as $step) {
            $escalations[] = [
                'after' => $this->addDuration($anchorAt, (string) ($step['after'] ?? 'PT0S'), $businessDays),
                'notify_role' => (string) ($step['notify_role'] ?? ''),
                'severity' => isset($step['severity']) ? (string) $step['severity'] : null,
            ];
        }

        return new ObligationDraft(
            definitionId: $id,
            label: (string) ($definition['label'] ?? $id),
            anchorAt: $anchorAt,
            dueAt: $dueAt,
            responsibleRole: isset($definition['responsible_role']) ? (string) $definition['responsible_role'] : null,
            recipient: isset($definition['recipient']) ? (string) $definition['recipient'] : null,
            evidenceRequired: (bool) ($definition['evidence_required'] ?? false),
            reminderAt: $reminders,
            escalations: $escalations,
            businessDays: $businessDays,
        );
    }

    /**
     * ISO-8601 duration arithmetic. With `business_days`, the *date* portion is counted in working
     * days (Mon–Fri) while the time portion is added as-is — so "P2D" on a Friday lands on Tuesday.
     *
     * Public holidays are deliberately not applied here: they are per-country and per-year, they
     * live in the platform's `public_holidays` table, and a pack that hard-coded them would be
     * wrong within twelve months. Where a jurisdiction counts holidays, the pack sets
     * `business_days: false` and states a duration that already allows for them.
     */
    private function addDuration(Carbon $from, string $duration, bool $businessDays = false): Carbon
    {
        $interval = $this->interval($duration);

        if (! $businessDays) {
            return $from->copy()->add($interval);
        }

        $days = ($interval->y * 365) + ($interval->m * 30) + $interval->d;
        $result = $from->copy();
        for ($i = 0; $i < $days; $i++) {
            $result->addDay();
            while ($result->isWeekend()) {
                $result->addDay();
            }
        }

        return $result->addHours($interval->h)->addMinutes($interval->i)->addSeconds($interval->s);
    }

    private function subtractDuration(Carbon $from, string $duration): Carbon
    {
        return $from->copy()->sub($this->interval($duration));
    }

    private function interval(string $duration): DateInterval
    {
        try {
            return new DateInterval($duration);
        } catch (Exception) {
            throw new InvalidArgumentException("Invalid ISO-8601 duration `{$duration}` in regulatory pack.");
        }
    }
}
