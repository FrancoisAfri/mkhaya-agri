<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manufacturing's promise against one sales order line (MFG-017): when the customer needs it,
 * when production can have it ready, and how it is made. The sales order itself is Finance's and
 * is only ever read.
 */
class CustomerCommitment extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const OPEN = 'OPEN';
    public const CANCELLED = 'CANCELLED';

    /** Held against the line by an ordinary stock reservation with this source. */
    public const RESERVATION_SOURCE = 'SALES_ORDER_LINE';

    /** A production order made for a customer names the sales order line as its demand. */
    public const DEMAND_TYPE = 'CUSTOMER_ORDER';

    protected $table = 'mfg_customer_commitments';

    protected $fillable = ['uuid', 'company_id', 'sales_order_id', 'sales_order_line_id', 'stock_item_id', 'item_id', 'warehouse_id',
        'factory_id', 'strategy', 'required_date', 'promised_date', 'lead_days', 'configuration', 'status', 'created_by'];

    protected $casts = ['required_date' => 'date', 'promised_date' => 'date', 'lead_days' => 'integer'];

    public function salesOrder(): BelongsTo { return $this->belongsTo(FinSalesOrder::class, 'sales_order_id'); }

    public function salesLine(): BelongsTo { return $this->belongsTo(FinSalesOrderLine::class, 'sales_order_line_id'); }

    public function stockItem(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'stock_item_id')->withoutGlobalScopes(); }

    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function factory(): BelongsTo { return $this->belongsTo(Factory::class, 'factory_id'); }
}
