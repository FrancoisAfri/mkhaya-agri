<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A recorded Finance payment applied to a tax liability. Reversed, never deleted. */
class TaxPaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_payment_allocations';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['amount' => 'string', 'allocated_at' => 'datetime', 'reversed_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('reversed_at');
    }
}
