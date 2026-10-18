<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call for help from the floor. It climbs a level if nobody acknowledges it in time. */
class FloorEscalation extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const KINDS = ['MATERIAL', 'MACHINE', 'QUALITY', 'SAFETY', 'INSTRUCTION', 'OTHER'];

    public const OPEN = 'OPEN';
    public const ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const RESOLVED = 'RESOLVED';

    protected $table = 'mfg_floor_escalations';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'order_id', 'order_operation_id', 'work_centre_id', 'kind', 'message', 'status',
        'level', 'raised_by', 'raised_at', 'assigned_to', 'escalated_at', 'acknowledged_by', 'acknowledged_at',
        'resolved_by', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'level' => 'integer', 'raised_at' => 'datetime', 'escalated_at' => 'datetime',
        'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id'));
    }

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'order_id'); }

    public function operation(): BelongsTo { return $this->belongsTo(ProductionOrderOperation::class, 'order_operation_id'); }

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }

    public function raiser(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'raised_by'); }

    public function assignee(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to'); }
}
