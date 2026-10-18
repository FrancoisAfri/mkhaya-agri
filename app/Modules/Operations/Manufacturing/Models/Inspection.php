<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inspection of one quantity (MFG-009). Opened by the event that needs it, with the stock it is
 * about held in a status nothing can issue; closed only by an authorised disposition.
 *
 * Certificates, test reports and release evidence are filed against it in Document Management.
 */
class Inspection extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasDocuments, HasPublicUuid;

    public const OPEN = 'OPEN';
    public const AWAITING_DISPOSITION = 'AWAITING_DISPOSITION';
    public const CLOSED = 'CLOSED';
    public const STATUSES = [self::OPEN, self::AWAITING_DISPOSITION, self::CLOSED];

    public const PENDING = 'PENDING';
    public const PASSED = 'PASSED';
    public const FAILED = 'FAILED';

    public const ACCEPT = 'ACCEPT';
    public const CONDITIONAL_ACCEPT = 'CONDITIONAL_ACCEPT';
    public const REJECT = 'REJECT';
    public const SCRAP = 'SCRAP';
    public const RETURN_TO_SUPPLIER = 'RETURN_TO_SUPPLIER';
    public const REWORK = 'REWORK';
    public const DISPOSITIONS = [self::ACCEPT, self::CONDITIONAL_ACCEPT, self::REJECT, self::SCRAP, self::RETURN_TO_SUPPLIER, self::REWORK];

    public const SOURCE_TYPE = 'INSPECTION';

    protected $table = 'mfg_inspections';

    protected $fillable = [
        'uuid', 'company_id', 'number', 'stage', 'plan_id', 'stock_item_id', 'lot_id', 'warehouse_id', 'qty',
        'held_status', 'sample_size', 'production_order_id', 'order_operation_id', 'supplier_id',
        'purchase_order_id', 'receipt_reference', 'result', 'status', 'inspected_by', 'inspected_at',
        'disposition', 'disposition_reason', 'disposed_by', 'disposed_at', 'ncr_id', 'notes', 'created_by',
        'bin_id', 'source_type', 'source_id',
    ];

    protected $casts = [
        'qty' => 'decimal:6', 'sample_size' => 'integer', 'inspected_at' => 'datetime', 'disposed_at' => 'datetime',
    ];

    public function plan(): BelongsTo { return $this->belongsTo(InspectionPlan::class, 'plan_id'); }

    public function results(): HasMany { return $this->hasMany(InspectionResult::class, 'inspection_id')->orderBy('sample_no')->orderBy('id'); }

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }

    public function operation(): BelongsTo { return $this->belongsTo(ProductionOrderOperation::class, 'order_operation_id'); }

    public function ncr(): BelongsTo { return $this->belongsTo(Nonconformance::class, 'ncr_id'); }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockLot::class, 'lot_id')->withoutGlobalScopes();
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinWarehouse::class, 'warehouse_id'); }

    public function isOpen(): bool { return $this->status !== self::CLOSED; }
}
