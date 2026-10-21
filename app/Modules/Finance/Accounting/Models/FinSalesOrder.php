<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinSalesOrder extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_sales_orders';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'quote_id', 'invoice_id', 'number', 'order_date',
        'currency', 'status', 'subtotal', 'tax_total', 'total', 'customer_ref', 'description', 'created_by',
        'dimensions', 'deliver_on', 'delivery_address', 'source_type', 'source_id',
    ];

    protected $casts = [
        'order_date' => 'date', 'deliver_on' => 'date', 'dimensions' => 'array', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FinSalesOrder $o) => $o->uuid ??= (string) Str::uuid());
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinSalesOrderLine::class, 'order_id');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(FinDeliveryNote::class, 'order_id');
    }
}
