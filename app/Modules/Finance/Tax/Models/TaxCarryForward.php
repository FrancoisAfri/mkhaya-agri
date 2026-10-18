<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A balance carried between years of assessment (§8.1 "carried-forward balances"). */
class TaxCarryForward extends Model
{
    use BelongsToCompany, BelongsToBusiness;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_carry_forwards';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['year_end' => 'date', 'opening' => 'string', 'movement' => 'string', 'closing' => 'string'];
}
