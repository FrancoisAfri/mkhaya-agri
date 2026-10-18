<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-company add-on subscription (extra users / AI packs / storage packs).
 * `price_monthly` is the per-unit snapshot; the monthly line = quantity × price.
 */
class CompanyAddon extends Model
{
    protected $table = 'company_addons';

    protected $fillable = ['company_id', 'addon_code', 'type', 'quantity', 'price_monthly'];

    protected $casts = [
        'quantity' => 'integer',
        'price_monthly' => 'decimal:2',
    ];

    public function lineTotal(): float
    {
        return (float) $this->price_monthly * (int) $this->quantity;
    }
}
