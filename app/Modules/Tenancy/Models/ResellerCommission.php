<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One accrued monthly commission row: reseller X earns `commission_amount` from client Y
 * for period YYYY-MM, computed off the client's subscription amount that month. Append-only
 * in spirit — status moves pending -> paid (or void). Platform-level, no tenant scope.
 */
class ResellerCommission extends Model
{
    protected $fillable = [
        'uuid', 'reseller_id', 'company_id', 'subscription_invoice_id', 'period',
        'base_amount', 'commission_type', 'commission_value', 'commission_amount',
        'status', 'paid_at', 'reference',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ResellerCommission $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
