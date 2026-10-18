<?php

namespace App\Modules\CRM\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Central CRM event bus. A single fire() point fans an event out to both the
 * automation/workflow engine (section 3) and the outbound webhook dispatcher
 * (section 5), so controllers don't have to know about either subsystem.
 */
class CrmEventBus
{
    public function __construct(
        private CrmWorkflowEngine $workflows,
        private CrmWebhookDispatcher $webhooks,
    ) {
    }

    /** Fire an event to both workflows and webhooks. */
    public function fire(string $event, Model $entity, array $context = []): void
    {
        $this->workflows->dispatch($event, $entity, $context);
        $this->webhooks->dispatch($event, $entity);
    }

    /** Fire to webhooks only (events workflows don't model, e.g. deal.won). */
    public function fireWebhook(string $event, Model $entity): void
    {
        $this->webhooks->dispatch($event, $entity);
    }
}
