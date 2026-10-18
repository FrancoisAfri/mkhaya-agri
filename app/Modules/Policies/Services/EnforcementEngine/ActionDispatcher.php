<?php

namespace App\Modules\Policies\Services\EnforcementEngine;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyViolation;
use Illuminate\Support\Facades\DB;

class ActionDispatcher
{
    public function dispatch(EvaluationResultDTO $result, PolicyViolation $violation): void
    {
        $configuredAction = $result->rule->actions->sortBy('sort_order')->first();

        DB::table('enforcement_actions')->insert([
            'violation_id' => $violation->id,
            'rule_id' => $result->rule->id,
            'event_id' => $result->event->eventId,
            'action_taken' => $this->mapActionTaken($result->outcome),
            'message_delivered' => $configuredAction?->message_template,
            'escalated_to' => data_get($configuredAction?->config, 'escalated_to'),
            'metadata' => json_encode([
                'engine_outcome' => $result->outcome,
                'rule_action_type' => $configuredAction?->type,
                'severity' => $result->severity,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mapActionTaken(string $outcome): string
    {
        return match ($outcome) {
            'block' => 'blocked',
            'warn' => 'allowed_with_warning',
            'escalate' => 'escalated',
            'log_only' => 'logged',
            default => 'logged',
        };
    }
}
