<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\WorkflowSettings;

/**
 * The engine's front door for modules (PRS §13, §21): a module reports that something happened to
 * one of its records, and the engine starts the published workflow(s) listening for it.
 *
 * Usage, in a module's submit method:
 *     if (app(Workflows::class)->handles('finance.claims')) {
 *         app(Workflows::class)->emit('finance.claims.submitted', 'finance.claims', $claim->uuid, $claim->employee_id);
 *     }
 */
class Workflows
{
    public function __construct(
        private readonly WorkflowSettings $settings,
        private readonly WorkflowRunner $runner,
        private readonly ProviderRegistry $providers,
    ) {
    }

    /** Whether this company has switched the module's approvals over to the engine and has a workflow for it. */
    public function handles(string $provider, ?int $companyId = null): bool
    {
        $provider = $this->providers->get($provider);
        if (! $provider || ! $this->settings->moduleEnabled($provider->key(), $companyId)) {
            return false;
        }

        return $this->listeners($provider->submitEvent())->isNotEmpty();
    }

    /**
     * Start every published workflow listening for the event. The same event for the same record
     * starts nothing new (§5 idempotency): the record's existing open instance is returned instead.
     *
     * @return list<WorkflowInstance>
     */
    public function emit(string $event, string $provider, string $recordId, ?int $requesterId, array $data = [], ?string $eventId = null): array
    {
        $started = [];
        foreach ($this->listeners($event) as $definition) {
            if (! \App\Modules\Workflow\Support\Conditions::evaluate($definition->trigger_config['filter'] ?? null, $data + ['record' => $this->snapshot($provider, $recordId)])) {
                continue;
            }
            $open = WorkflowInstance::query()->where('definition_id', $definition->id)->where('subject_type', $provider)->where('subject_id', $recordId)
                ->whereIn('status', WorkflowInstance::OPEN)->first();
            $started[] = $open ?? $this->runner->start($definition, $data, $requesterId, ['type' => $provider, 'id' => $recordId],
                $eventId ? "evt:{$definition->id}:{$eventId}" : null);
        }

        return $started;
    }

    /** The open workflow instance for a record, if any. */
    public function openFor(string $provider, string $recordId): ?WorkflowInstance
    {
        return WorkflowInstance::query()->where('subject_type', $provider)->where('subject_id', $recordId)->whereIn('status', WorkflowInstance::OPEN)->latest('id')->first();
    }

    private function listeners(?string $event)
    {
        return $event ? WorkflowDefinition::query()->where('status', 'published')->where('trigger_type', 'record_event')
            ->where('trigger_config->event', $event)->get() : collect();
    }

    private function snapshot(string $provider, string $recordId): array
    {
        $p = $this->providers->get($provider);
        $record = $p?->find($recordId);

        return $record ? $p->snapshot($record) : [];
    }
}
