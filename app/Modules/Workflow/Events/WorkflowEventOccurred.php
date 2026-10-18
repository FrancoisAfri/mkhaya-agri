<?php

namespace App\Modules\Workflow\Events;

/** A workflow event delivered from the outbox. Listeners must be idempotent: delivery is at least once. */
class WorkflowEventOccurred
{
    public function __construct(
        public readonly int $outboxId,
        public readonly string $name,
        public readonly array $payload,
        public readonly ?int $companyId,
        public readonly ?string $correlationId,
    ) {
    }
}
