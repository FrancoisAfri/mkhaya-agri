<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The producer side of the transactional outbox (PM-ADR-008).
 *
 * `publish()` must be called inside the transaction that performs the state change. That is not a
 * convention here, it is checked: publishing outside a transaction throws, because an event that
 * can commit independently of its cause is precisely the bug the outbox pattern exists to prevent.
 *
 * The consumer side is the pm:drain-outbox command.
 */
class Outbox
{
    public function __construct(private readonly CurrentCompany $currentCompany)
    {
    }

    /**
     * Record a domain event for delivery.
     *
     * @param  string  $eventType    e.g. 'pm.evidence.appended', 'pm.task.rescheduled'
     * @param  array<string,mixed>  $payload  Everything a consumer needs without calling back.
     */
    public function publish(
        string $eventType,
        string $subjectType,
        ?string $subjectUuid,
        array $payload,
        ?int $companyId = null,
    ): OutboxMessage {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "Outbox event '{$eventType}' was published outside a transaction. It must be written "
                . 'in the same transaction as the change that caused it (PM-ADR-008).'
            );
        }

        return OutboxMessage::create([
            'company_id'   => $companyId ?? $this->currentCompany->id(),
            'event_type'   => $eventType,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'payload'      => $payload,
            'available_at' => now(),
        ]);
    }
}
