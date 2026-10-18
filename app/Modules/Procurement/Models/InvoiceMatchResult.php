<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceMatchResult extends Model
{
    use BelongsToCompany;

    protected $table = 'invoice_match_results';

    protected $fillable = [
        'supplier_invoice_id', 'type', 'status', 'purchase_order_id', 'goods_received_note_id',
        'po_amount', 'grn_value', 'invoice_amount', 'qty_ok', 'price_ok', 'amount_ok',
        'within_tolerance', 'tolerance_pct', 'variance_amount', 'discrepancies', 'checked_by',
    ];

    protected $casts = [
        'po_amount'        => 'decimal:2',
        'grn_value'        => 'decimal:2',
        'invoice_amount'   => 'decimal:2',
        'variance_amount'  => 'decimal:2',
        'tolerance_pct'    => 'decimal:2',
        'qty_ok'           => 'boolean',
        'price_ok'         => 'boolean',
        'amount_ok'        => 'boolean',
        'within_tolerance' => 'boolean',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\SupplierManagement\Models\SupplierInvoice::class, 'supplier_invoice_id');
    }

    /** @return array<int,array<string,mixed>> */
    public function discrepancyList(): array
    {
        return json_decode((string) $this->discrepancies, true) ?: [];
    }
}
