<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material sent with a subcontract order: issued to the production order, held at the supplier. */
class SubcontractMaterial extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_subcontract_materials';

    protected $fillable = ['company_id', 'subcontract_order_id', 'component_id', 'stock_item_id', 'qty_planned', 'qty_sent', 'qty_returned'];

    protected $casts = ['qty_planned' => 'decimal:6', 'qty_sent' => 'decimal:6', 'qty_returned' => 'decimal:6'];

    public function component(): BelongsTo { return $this->belongsTo(ProductionOrderComponent::class, 'component_id'); }

    public function stockItem(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')->withoutGlobalScopes(); }
}
