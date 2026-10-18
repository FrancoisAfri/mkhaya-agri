<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxReconciliationItem extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_reconciliation_items';

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'links' => 'array', 'explained_at' => 'datetime',
        'source_amount' => 'string', 'target_amount' => 'string', 'difference' => 'string',
    ];
}
