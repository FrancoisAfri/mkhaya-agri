<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery back from the subcontractor, and the inspection it went into. */
class SubcontractReceipt extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_subcontract_receipts';

    protected $fillable = ['company_id', 'subcontract_order_id', 'qty', 'supplier_reference', 'inspection_id', 'received_by', 'received_at'];

    protected $casts = ['qty' => 'decimal:6', 'received_at' => 'datetime'];

    public function inspection(): BelongsTo { return $this->belongsTo(Inspection::class, 'inspection_id'); }
}
