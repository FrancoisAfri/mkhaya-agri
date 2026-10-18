<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One material in a plan run: its time-phased requirement, supply, projection and exceptions. */
class PlanItem extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_plan_items';

    protected $fillable = [
        'company_id', 'run_id', 'stock_item_id', 'level', 'make_or_buy', 'on_hand', 'safety_stock', 'gross', 'scheduled_receipts',
        'net', 'planned', 'projected_end', 'shortage_on', 'available_on', 'timeline', 'demand', 'exceptions',
    ];

    protected $casts = [
        'level' => 'integer', 'on_hand' => 'decimal:4', 'safety_stock' => 'decimal:4', 'gross' => 'decimal:4',
        'scheduled_receipts' => 'decimal:4', 'net' => 'decimal:4', 'planned' => 'decimal:4', 'projected_end' => 'decimal:4',
        'shortage_on' => 'date', 'available_on' => 'date', 'timeline' => 'array', 'demand' => 'array', 'exceptions' => 'array',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }
}
