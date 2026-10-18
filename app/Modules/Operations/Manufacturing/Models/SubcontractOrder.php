<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Work sent to a supplier (MFG-017): one subcontracted operation of a production order, or the
 * whole order when the operation is null.
 */
class SubcontractOrder extends Model
{
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const DRAFT = 'DRAFT';
    public const SENT = 'SENT';
    public const PART_RECEIVED = 'PART_RECEIVED';
    public const RECEIVED = 'RECEIVED';
    public const CLOSED = 'CLOSED';
    public const CANCELLED = 'CANCELLED';

    /** Still with the supplier, or still to go. */
    public const OPEN = [self::DRAFT, self::SENT, self::PART_RECEIVED];

    protected $table = 'mfg_subcontract_orders';

    protected $fillable = ['uuid', 'company_id', 'number', 'production_order_id', 'order_operation_id', 'supplier_id', 'qty', 'qty_sent',
        'qty_received', 'qty_rejected', 'unit_price', 'currency', 'due_date', 'status', 'purchase_request_id', 'dispatch_reference',
        'notes', 'created_by', 'sent_by', 'sent_at', 'closed_at'];

    protected $casts = ['qty' => 'decimal:6', 'qty_sent' => 'decimal:6', 'qty_received' => 'decimal:6', 'qty_rejected' => 'decimal:6',
        'unit_price' => 'decimal:4', 'due_date' => 'date', 'sent_at' => 'datetime', 'closed_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }

    public function operation(): BelongsTo { return $this->belongsTo(ProductionOrderOperation::class, 'order_operation_id'); }

    public function supplier(): BelongsTo { return $this->belongsTo(\App\Modules\SupplierManagement\Models\Supplier::class, 'supplier_id')->withoutGlobalScopes(); }

    public function materials(): HasMany { return $this->hasMany(SubcontractMaterial::class, 'subcontract_order_id'); }

    public function receipts(): HasMany { return $this->hasMany(SubcontractReceipt::class, 'subcontract_order_id')->orderBy('received_at'); }

    /** What the supplier has still to send back. */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->qty_sent - (float) $this->qty_received, 6));
    }

    public function cost(): float
    {
        return round((float) $this->qty_received * (float) $this->unit_price, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::SENT, self::PART_RECEIVED], true) && $this->due_date && $this->due_date->lt(today());
    }
}
