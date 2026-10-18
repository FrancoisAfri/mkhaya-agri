<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work order — the richer execution layer over a scheduled maintenance:
 * task checklist + labour + parts + a status flow.
 */
class AssetWorkOrder extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_work_orders';

    protected $fillable = [
        'uuid',
        'company_id',
        'work_order_number',
        'asset_id',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'assigned_to',
        'requested_by',
        'scheduled_for',
        'started_at',
        'completed_at',
        'labor_hours',
        'labor_cost',
        'parts_cost',
        'total_cost',
        'resolution_notes',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'labor_hours'   => 'decimal:2',
        'labor_cost'    => 'decimal:2',
        'parts_cost'    => 'decimal:2',
        'total_cost'    => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relations ─────────────────────────────────────────────────────────────
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AssetWorkOrderTask::class, 'work_order_id')->orderBy('sequence');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(AssetWorkOrderPart::class, 'work_order_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isEditable(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    public function allTasksDone(): bool
    {
        return $this->tasks()->where('is_done', false)->doesntExist();
    }
}
