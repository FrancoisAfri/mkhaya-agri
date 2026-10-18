<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_customer_return_lines';

    protected $fillable = ['company_id', 'return_id', 'item_id', 'order_line_id', 'lot_id', 'serials', 'qty_authorised', 'qty_received',
        'reason_code', 'condition', 'unit_cost', 'bin_id', 'inspection_id', 'outcome', 'movement_id', 'notes'];

    protected $casts = ['serials' => 'array', 'qty_authorised' => 'decimal:4', 'qty_received' => 'decimal:4', 'unit_cost' => 'decimal:4'];

    public const CONDITIONS = ['UNOPENED', 'OPENED', 'DAMAGED', 'DEFECTIVE', 'WRONG_ITEM', 'EXPIRED'];

    public const REASONS = ['DAMAGED', 'DEFECTIVE', 'WRONG_ITEM', 'NOT_WANTED', 'LATE', 'RECALL', 'OTHER'];

    /** Final outcomes; QUARANTINED and REPAIR still wait for a decision. */
    public const FINAL = ['RESTOCKED', 'SUPPLIER_RETURN', 'DISPOSED', 'REJECTED'];

    public function customerReturn(): BelongsTo { return $this->belongsTo(CustomerReturn::class, 'return_id'); }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function inspection(): BelongsTo { return $this->belongsTo(Inspection::class, 'inspection_id'); }
}
