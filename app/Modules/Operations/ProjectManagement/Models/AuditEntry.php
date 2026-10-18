<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One line of the PM audit trail (PM-1024).
 *
 * Append-only, like the Evidence Ledger and for the same reason: a record that can be edited after
 * the fact proves nothing. The database trigger is the enforcement; these guards make a mistake
 * surface as a readable exception at the call site instead of an SQLSTATE.
 */
class AuditEntry extends Model
{
    use BelongsToCompany, HasPublicUuid;

    public const UPDATED_AT = null;   // written once; there is no "updated"

    protected $table = 'pm_audit_log';

    protected $fillable = [
        'uuid', 'company_id', 'entity_type', 'entity_id', 'entity_uuid', 'action',
        'actor_id', 'actor_type', 'actor_label', 'before', 'after',
        'ip', 'request_id', 'user_agent', 'route',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('The PM audit log is append-only: entries cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('The PM audit log is append-only: entries cannot be deleted.');
        });
    }

    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeByActor(Builder $query, int $actorId): Builder
    {
        return $query->where('actor_id', $actorId);
    }
}
