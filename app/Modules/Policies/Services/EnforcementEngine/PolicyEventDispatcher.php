<?php
namespace App\Modules\Policies\Services\EnforcementEngine;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Models\PolicyEvent;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Support\Str;
class PolicyEventDispatcher {
    public function __construct(
        private readonly RuleEvaluationEngine $evaluationEngine,
        private readonly ViolationRecorder    $violationRecorder,
        private readonly ActionDispatcher     $actionDispatcher,
    ) {}
    public function dispatch(PolicyEventDTO $event): void {
        $record = PolicyEvent::create([
            'module'         => $event->module,
            'action'         => $event->action,
            'actor_id'       => $event->actorId,
            'subject_id'     => $event->subjectId,
            'subject_type'   => $event->subjectType,
            'payload'        => $event->payload,
            'correlation_id' => $event->correlationId ?? Str::uuid()->toString(),
        ]);
        $hydrated = new PolicyEventDTO(
            module: $event->module, action: $event->action, payload: $event->payload,
            actorId: $event->actorId, subjectId: $event->subjectId, subjectType: $event->subjectType,
            correlationId: $record->correlation_id, eventId: $record->id,
        );
        foreach ($this->evaluationEngine->evaluate($hydrated) as $result) {
            if (! $result->passed) {
                $violation = $this->violationRecorder->record($result);
                $this->actionDispatcher->dispatch($result, $violation);
            }
        }
    }
}
