<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A module a company is subscribed to, with the price snapshot it pays.
 * source = bundle (included in its plan) | addon (à la carte extra).
 */
class CompanyModule extends Model
{
    protected $table = 'company_modules';

    protected $fillable = ['company_id', 'module_code', 'price_monthly', 'source'];

    protected $casts = ['price_monthly' => 'decimal:2'];
}
