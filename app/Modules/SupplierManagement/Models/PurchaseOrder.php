<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'uuid', 'po_number', 'supplier_id', 'contract_id', 'title',
        'amount', 'currency', 'status', 'order_date', 'created_by', 'approved_by', 'approved_at', 'notes',
        'procurement_requisition_id', 'budget_id', 'budget_commitment_id',
        'source', 'expected_delivery', 'delivered_at', 'received_status',
        // WHMI: where the goods are to be delivered and received.
        'warehouse_id',
    ];

    protected $casts = [
        'amount'            => 'decimal:2',
        'order_date'        => 'date',
        'expected_delivery' => 'date',
        'delivered_at'      => 'datetime',
    ];

    /**
     * Addressed by uuid, never by the row id.
     *
     * A purchase order is linked to from invoices, deliveries and the marketplace. A sequential id in that URL says how many a tenant has and invites a guess at the next
     * one; authorisation is what actually refuses it, but the id makes the guess free and makes an
     * accidental leak -- a pasted link, a screenshot, a referrer header -- carry more than it needs to.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            $po->uuid ??= (string) Str::uuid();

            if (empty($po->po_number)) {
                $maxId = (int) static::withTrashed()->max('id');
                $po->po_number = 'PO-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'purchase_order_id');
    }

    // ── Procurement P2P relations (Sprint 4) ────────────────────────────────────
    public function items(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\DeliveryNote::class, 'purchase_order_id');
    }

    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\GoodsReceivedNote::class, 'purchase_order_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\PurchaseOrderAmendment::class, 'purchase_order_id');
    }

    public function assetLinks(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\ProcurementAssetLink::class, 'purchase_order_id');
    }

    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\ProcurementInventoryLink::class, 'purchase_order_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Procurement\Models\ProcurementRequisition::class, 'procurement_requisition_id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Budget\Models\Budget::class, 'budget_id');
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn ($q, $s) => $q->where('status', $s));
    }

    public function scopeOffContract(Builder $query): Builder
    {
        return $query->whereNull('contract_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
