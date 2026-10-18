<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a warehouse's configuration history (WMS-004). The database refuses edits. */
class LocationChange extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_location_changes';

    public $timestamps = false;

    protected $fillable = ['company_id', 'warehouse_id', 'bin_id', 'action', 'changes', 'reason', 'actor_id', 'created_at'];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    public const CREATED = 'CREATED';
    public const UPDATED = 'UPDATED';
    public const BLOCKED = 'BLOCKED';
    public const RESTRICTED = 'RESTRICTED';
    public const REOPENED = 'REOPENED';
    public const DEACTIVATED = 'DEACTIVATED';
    public const SYNCED = 'SYNCED';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(FinStockBin::class, 'bin_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
