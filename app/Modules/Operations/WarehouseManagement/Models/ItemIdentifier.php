<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A code another party knows the item by: a supplier's part number, a customer's code. */
class ItemIdentifier extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_identifiers';

    protected $fillable = ['uuid', 'company_id', 'stock_item_id', 'kind', 'code', 'supplier_id', 'party_name', 'description'];

    protected $casts = [];

    public const KINDS = ['SUPPLIER', 'CUSTOMER', 'MANUFACTURER', 'EXTERNAL'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id');
    }
}
