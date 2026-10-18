<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A handling unit -- carton, pallet, bag -- with its SSCC and contents (WMS-064). */
class Package extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_packages';

    protected $fillable = ['uuid', 'company_id', 'order_id', 'sscc', 'type', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'tracking_number', 'status', 'packed_by', 'closed_at'];

    protected $casts = ['weight_kg' => 'decimal:3', 'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2', 'height_cm' => 'decimal:2', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public const TYPES = ['CARTON', 'PALLET', 'BAG', 'ENVELOPE', 'OTHER'];

    public function contents(): HasMany { return $this->hasMany(PackageContent::class, 'package_id'); }

    public function order(): BelongsTo { return $this->belongsTo(FulfilmentOrder::class, 'order_id'); }
}
