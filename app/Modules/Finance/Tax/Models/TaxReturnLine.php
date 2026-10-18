<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxReturnLine extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_return_lines';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['calculated' => 'string', 'adjustment' => 'string', 'final' => 'string', 'adjusted_at' => 'datetime'];
}
