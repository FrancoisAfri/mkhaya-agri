<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseRequestItem extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_request_items';

    protected $fillable = [
        'uuid', 'purchase_request_id', 'item_type', 'name', 'description',
        'budget_category_id', 'supplier_id', 'quantity', 'unit_price', 'line_total',
        'source', 'external_ref',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PurchaseRequestItem $m) => $m->uuid ??= (string) Str::uuid());
        static::saving(function (PurchaseRequestItem $m) {
            $m->line_total = round((float) $m->quantity * (float) $m->unit_price, 2);
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
