<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxReturnSource extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'tax_return_sources';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['amount' => 'string'];
}
