<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A barcode registered against an item, optionally for one packaging level (WMS-012). */
class ItemBarcode extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_barcodes';

    protected $fillable = ['uuid', 'company_id', 'stock_item_id', 'barcode', 'symbology', 'source', 'packaging_id', 'supplier_id', 'is_primary', 'is_active', 'duplicate_reason', 'duplicate_approved_by', 'created_by'];

    protected $casts = ['is_primary' => 'boolean', 'is_active' => 'boolean'];

    public const SYMBOLOGIES = ['EAN13', 'EAN8', 'UPCA', 'GTIN14', 'CODE128', 'GS1_128', 'QR', 'DATAMATRIX', 'INTERNAL'];

    public const SOURCES = ['SUPPLIER', 'CUSTOMER', 'MANUFACTURER', 'INTERNAL'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(ItemPackaging::class, 'packaging_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id');
    }
}
