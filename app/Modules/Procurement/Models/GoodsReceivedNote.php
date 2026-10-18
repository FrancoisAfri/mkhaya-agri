<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\SupplierManagement\Models\PurchaseOrder;

class GoodsReceivedNote extends Model
{
    use BelongsToCompany;

    protected $table = 'goods_received_notes';

    protected $fillable = [
        'uuid', 'purchase_order_id', 'grn_number', 'status', 'received_value',
        'received_by', 'received_at', 'notes',
        // WHMI receiving (WMS-030..033).
        'warehouse_id', 'supplier_id', 'source', 'non_po_reason', 'approval_status', 'approved_by', 'approved_at',
        'delivery_note_ref', 'vehicle_reg', 'appointment_id', 'discrepancy_status', 'inspection_status', 'posted_at',
        'idempotency_key', 'company_id',
    ];

    protected $casts = [
        'received_value' => 'decimal:2',
        'received_at'    => 'datetime',
        'approved_at' => 'datetime', 'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GoodsReceivedNote $m) {
            $m->uuid ??= (string) Str::uuid();
            if (empty($m->grn_number)) {
                // Two receipts saved at the same moment read the same max and collide on the
                // (company, number) key; serialise numbering within the creating transaction.
                if (\Illuminate\Support\Facades\DB::transactionLevel() > 0 && \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                    \Illuminate\Support\Facades\DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('grn-number')]);
                }
                $max = (int) static::max('id');
                $m->grn_number = 'GRN'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** The warehouse the goods were received into (WHMI). */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinWarehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivedItem::class, 'goods_received_note_id');
    }
}
