<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A domain event awaiting delivery (PM-ADR-008).
 *
 * Written inside the same transaction as the state change that produced it, so a rollback takes
 * the event with it and there is no such thing as a notification for something that never
 * happened. Drained by pm:drain-outbox.
 *
 * No LogsActivity: the row is itself the record of the event, and the business mutation that
 * created it is already audited.
 */
class OutboxMessage extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_outbox';

    protected $fillable = [
        'uuid', 'company_id', 'event_type', 'subject_type', 'subject_uuid', 'payload',
        'available_at', 'attempts', 'last_error', 'delivered_at', 'dead_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'available_at' => 'datetime',
        'delivered_at' => 'datetime',
        'dead_at'      => 'datetime',
        'attempts'     => 'integer',
    ];

    /** Attempts before a message is parked as a dead letter for operator attention. */
    public const MAX_ATTEMPTS = 8;

    /** Undelivered, not dead, and due — the drain worker's query. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')
            ->whereNull('dead_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at');
    }

    /** Exponential backoff, capped — a failing consumer must not spin. */
    public function backoffSeconds(): int
    {
        return min(3600, 2 ** max(0, $this->attempts));
    }
}
