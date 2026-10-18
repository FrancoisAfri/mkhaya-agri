<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single checklist item on a work order.
 */
class AssetWorkOrderTask extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_work_order_tasks';

    protected $fillable = [
        'uuid',
        'company_id',
        'work_order_id',
        'description',
        'sequence',
        'is_done',
        'done_by',
        'done_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_done'  => 'boolean',
        'done_at'  => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(AssetWorkOrder::class, 'work_order_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
