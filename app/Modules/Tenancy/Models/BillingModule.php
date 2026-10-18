<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sellable (or core) module in the à la carte catalog, with its own monthly
 * price. Core modules (is_sellable = false) are always included in every plan.
 */
class BillingModule extends Model
{
    protected $table = 'billing_modules';

    protected $fillable = ['code', 'name', 'category', 'price_monthly', 'websites_per_month', 'backlinks_per_month', 'entities_included', 'is_sellable', 'is_active', 'sort'];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'websites_per_month' => 'integer',
        'backlinks_per_month' => 'integer',
        'entities_included' => 'integer',
        'is_sellable' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** Price map keyed by module code. */
    public static function priceMap(): array
    {
        return static::pluck('price_monthly', 'code')->map(fn ($p) => (float) $p)->toArray();
    }
}
