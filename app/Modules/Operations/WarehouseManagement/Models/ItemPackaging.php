<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One packaging level -- each, inner, carton, pallet -- and how many base units it holds. */
class ItemPackaging extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_packaging';

    protected $fillable = ['uuid', 'company_id', 'stock_item_id', 'level', 'name', 'uom_id', 'qty_in_base', 'gtin', 'length_cm', 'width_cm', 'height_cm', 'gross_weight_kg', 'is_active'];

    protected $casts = ['qty_in_base' => 'decimal:4', 'is_active' => 'boolean', 'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2', 'height_cm' => 'decimal:2', 'gross_weight_kg' => 'decimal:3'];

    public const LEVELS = ['EACH', 'INNER', 'CARTON', 'CASE', 'LAYER', 'PALLET', 'OTHER'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Operations\Manufacturing\Models\Uom::class, 'uom_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id');
    }
}
