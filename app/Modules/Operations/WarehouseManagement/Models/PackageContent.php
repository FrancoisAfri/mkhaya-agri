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

/** What is in a package. */
class PackageContent extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_package_contents';

    protected $fillable = ['company_id', 'package_id', 'order_line_id', 'item_id', 'lot_id', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function line(): BelongsTo { return $this->belongsTo(FulfilmentLine::class, 'order_line_id'); }

    public function package(): BelongsTo { return $this->belongsTo(Package::class, 'package_id'); }
}
