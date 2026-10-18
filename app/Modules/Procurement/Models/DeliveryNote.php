<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\SupplierManagement\Models\PurchaseOrder;

class DeliveryNote extends Model
{
    use BelongsToCompany;

    protected $table = 'delivery_notes';

    protected $fillable = [
        'uuid', 'purchase_order_id', 'delivery_number', 'supplier_ref',
        'delivered_at', 'notes', 'captured_by',
    ];

    protected $casts = ['delivered_at' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (DeliveryNote $m) {
            $m->uuid ??= (string) Str::uuid();
            if (empty($m->delivery_number)) {
                $max = (int) static::max('id');
                $m->delivery_number = 'DN'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
