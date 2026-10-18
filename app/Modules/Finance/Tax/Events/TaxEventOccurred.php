<?php

namespace App\Modules\Finance\Tax\Events;

/**
 * What listeners receive when a tax event is delivered from the outbox. `name` is one of the §19
 * events (tax.return.approved, tax.deadline.breached, …); `payload` holds identifiers only.
 * A listener must be idempotent: delivery is at least once.
 */
class TaxEventOccurred
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $name,
        public readonly array $payload,
        public readonly ?int $companyId,
    ) {
    }
}
