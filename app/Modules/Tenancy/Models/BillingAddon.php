<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sellable add-on stacked on top of a base plan: extra users (per-user rate),
 * AI token packs, or storage packs. `price_monthly`/`units` are null for
 * quote-only tiers (e.g. Corporate AI). Type ∈ user | ai_pack | storage_pack.
 */
class BillingAddon extends Model
{
    protected $table = 'billing_addons';

    protected $fillable = ['type', 'code', 'name', 'unit_label', 'units', 'price_monthly', 'sort', 'is_active'];

    protected $casts = [
        'units' => 'integer',
        'price_monthly' => 'decimal:2',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('type', $type);
    }
}
