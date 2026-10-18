<?php

namespace App\Modules\Policies\Services\EnforcementEngine;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyViolation;
use RuntimeException;

class ViolationRecorder
{
    public function record(EvaluationResultDTO $result): PolicyViolation
    {
        $userId = $result->event->actorId ?? $result->rule->created_by;
        if ($userId === null) {
            throw new RuntimeException('Cannot record violation without an actor_id or rule creator.');
        }

        return PolicyViolation::create([
            'policy_id' => $result->rule->policy_id,
            'rule_id' => $result->rule->id,
            'clause_id' => $result->rule->clause_id,
            'event_id' => $result->event->eventId,
            'user_id' => $userId,
            'department_id' => null,
            'severity' => $result->severity,
            'outcome' => $this->mapOutcome($result->outcome),
            'event_snapshot' => $result->event->payload,
            'explanation' => $result->explanation ?? 'Rule breach detected by policy engine.',
            'status' => 'open',
            'investigator_id' => null,
            'resolved_at' => null,
        ]);
    }

    private function mapOutcome(string $outcome): string
    {
        return match ($outcome) {
            'block' => 'blocked',
            'warn' => 'warned',
            'escalate' => 'escalated',
            'log_only' => 'logged',
            default => 'logged',
        };
    }
}
