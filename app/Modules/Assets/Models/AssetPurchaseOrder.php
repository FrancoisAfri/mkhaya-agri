<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M11 — Purchase Order
 * 
 * PO generated from approved procurement request.
 */
class AssetPurchaseOrder extends Model
{
    protected $table = 'asset_purchase_orders';

    protected $fillable = [
        'uuid',
        'po_number',
        'procurement_id',
        'supplier_id',
        'issued_by_user_id',
        'po_date',
        'expected_delivery_date',
        'total_amount',
        'status',                    // issued | acknowledged | delivered | invoiced | closed
        'terms_and_conditions',
    ];

    protected $casts = [
        'po_date'                => 'date',
        'expected_delivery_date' => 'date',
        'total_amount'           => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
            if (!$model->po_number) {
                $model->po_number = 'PO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    // Relationships
    public function procurement(): BelongsTo
    {
        return $this->belongsTo(AssetProcurement::class, 'procurement_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AssetSupplier::class, 'supplier_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
