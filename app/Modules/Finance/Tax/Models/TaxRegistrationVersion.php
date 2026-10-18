<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** TAX-REG-005: an immutable snapshot of a registration change. */
class TaxRegistrationVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'tax_registration_versions';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['snapshot' => 'array', 'changes' => 'array', 'effective_date' => 'date', 'created_at' => 'datetime'];
}
