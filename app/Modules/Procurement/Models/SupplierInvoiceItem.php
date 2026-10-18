<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierInvoiceItem extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_invoice_items';

    protected $fillable = [
        'uuid', 'supplier_invoice_id', 'purchase_order_item_id',
        'name', 'sku', 'quantity', 'unit_price', 'line_total', 'tax',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'tax'        => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (SupplierInvoiceItem $m) => $m->uuid ??= (string) Str::uuid());
        static::saving(function (SupplierInvoiceItem $m) {
            if (! $m->line_total) {
                $m->line_total = round((float) $m->quantity * (float) $m->unit_price, 2);
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\SupplierManagement\Models\SupplierInvoice::class, 'supplier_invoice_id');
    }
}
