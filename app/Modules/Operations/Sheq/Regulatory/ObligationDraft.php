<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use Illuminate\Support\Carbon;

/**
 * A statutory obligation the pack says this record triggers, with its clock already computed
 * (SHEQ-REG-4, SHEQ-REG-5). Persisted as a `sheq_statutory_obligation` row with its own lifecycle.
 *
 * A draft, not a record: the engine produces these from pack definitions, and the caller decides
 * whether it is instantiating them for real or previewing a pack in the sandbox (SHEQ-REG-8).
 */
final class ObligationDraft
{
    /**
     * @param  list<Carbon>  $reminderAt  when to nudge, soonest first
     * @param  list<array{after:Carbon,notify_role:string,severity:?string}>  $escalations
     */
    public function __construct(
        public readonly string $definitionId,
        public readonly string $label,
        public readonly Carbon $anchorAt,
        public readonly Carbon $dueAt,
        public readonly ?string $responsibleRole,
        public readonly ?string $recipient,
        public readonly bool $evidenceRequired,
        public readonly array $reminderAt = [],
        public readonly array $escalations = [],
        public readonly bool $businessDays = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'obligation_def_id' => $this->definitionId,
            'label' => $this->label,
            'anchor_at' => $this->anchorAt->toIso8601String(),
            'due_at' => $this->dueAt->toIso8601String(),
            'responsible_role' => $this->responsibleRole,
            'recipient' => $this->recipient,
            'evidence_required' => $this->evidenceRequired,
            'reminders' => array_map(fn (Carbon $at) => $at->toIso8601String(), $this->reminderAt),
            'escalations' => array_map(fn (array $e) => [
                'after' => $e['after']->toIso8601String(),
                'notify_role' => $e['notify_role'],
                'severity' => $e['severity'],
            ], $this->escalations),
        ];
    }
}
