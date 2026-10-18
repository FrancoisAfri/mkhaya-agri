<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One priced line of a standard cost, with how its rate was found. */
class StandardCostLine extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_standard_cost_lines';

    protected $fillable = [
        'company_id', 'standard_cost_id', 'category', 'reference', 'stock_item_id', 'routing_operation_id',
        'quantity', 'uom', 'rate', 'amount', 'basis',
    ];

    protected $casts = ['quantity' => 'decimal:6', 'rate' => 'decimal:6', 'amount' => 'decimal:4'];
}
