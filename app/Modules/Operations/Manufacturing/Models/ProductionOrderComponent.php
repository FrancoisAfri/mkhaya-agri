<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One material the order needs, copied from its BOM revision when the order was created. */
class ProductionOrderComponent extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_production_order_components';

    protected $fillable = [
        'uuid', 'company_id', 'order_id', 'bom_line_id', 'stock_item_id', 'line_kind', 'required_qty', 'uom',
        'issued_qty', 'returned_qty', 'est_unit_cost', 'issued_value',
    ];

    protected $casts = [
        'required_qty' => 'decimal:6', 'issued_qty' => 'decimal:6', 'returned_qty' => 'decimal:6',
        'est_unit_cost' => 'decimal:4', 'issued_value' => 'decimal:2',
    ];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'order_id'); }

    /**
     * The material, whatever business the viewer has selected. The link is a fixed foreign key inside
     * an order this tenant owns; filtering it by the viewer's current business only produced
     * components with no code, priced at zero.
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    /** Consumed so far: issued less returned. */
    public function netIssued(): float
    {
        return round((float) $this->issued_qty - (float) $this->returned_qty, 6);
    }

    public function outstanding(): float
    {
        return max(0.0, round((float) $this->required_qty - $this->netIssued(), 6));
    }

    /** A by-product comes out of the process; it is never issued into it. */
    public function isConsumed(): bool
    {
        return $this->line_kind !== 'BY_PRODUCT';
    }
}
