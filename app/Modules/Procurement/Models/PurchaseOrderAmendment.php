<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\SupplierManagement\Models\PurchaseOrder;

class PurchaseOrderAmendment extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_order_amendments';

    protected $fillable = [
        'uuid', 'purchase_order_id', 'type', 'old_amount', 'new_amount',
        'detail', 'reason', 'amended_by',
    ];

    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PurchaseOrderAmendment $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
