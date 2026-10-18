<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The quantity of one stock item held in one warehouse. The sum across warehouses equals the
 * item's qty_on_hand. Tenant-scoped (company_id + RLS).
 */
class FinStockBalance extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_stock_balances';

    protected $fillable = ['entity_id', 'company_id', 'item_id', 'warehouse_id', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id');
    }
}
