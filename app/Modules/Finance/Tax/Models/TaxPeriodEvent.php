<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxPeriodEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'tax_period_events';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['created_at' => 'datetime'];
}
