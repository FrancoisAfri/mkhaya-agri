<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stoppage at a work centre (and optionally one machine), from when it stopped to when it ran again. */
class DowntimeEvent extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_downtime_events';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'work_centre_id', 'asset_id', 'order_operation_id', 'reason_id',
        'started_at', 'ended_at', 'minutes', 'notes', 'reported_by', 'ended_by', 'fault_code_id', 'work_order_id',
    ];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'minutes' => 'decimal:2'];

    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id'));
    }

    public function reason(): BelongsTo { return $this->belongsTo(DowntimeReason::class, 'reason_id'); }

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }

    public function factory(): BelongsTo { return $this->belongsTo(Factory::class, 'factory_id'); }

    public function machine(): BelongsTo { return $this->belongsTo(\App\Modules\Assets\Models\Assets::class, 'asset_id'); }

    public function operation(): BelongsTo { return $this->belongsTo(ProductionOrderOperation::class, 'order_operation_id'); }

    public function faultCode(): BelongsTo { return $this->belongsTo(FaultCode::class, 'fault_code_id'); }

    public function workOrder(): BelongsTo { return $this->belongsTo(\App\Modules\Assets\Models\AssetWorkOrder::class, 'work_order_id'); }

    public function isOpen(): bool { return $this->ended_at === null; }

    /** Minutes so far for an open stoppage, final minutes for a closed one. */
    public function duration(): float
    {
        return $this->ended_at ? (float) $this->minutes : round($this->started_at->diffInSeconds(now()) / 60, 2);
    }
}
