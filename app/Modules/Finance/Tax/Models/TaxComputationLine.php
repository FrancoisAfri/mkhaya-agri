<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxComputationLine extends Model
{
    use BelongsToCompany;

    public const SECTIONS = [
        'profit' => 'Accounting profit',
        'add_back' => 'Add back: not deductible',
        'deduction' => 'Deduct: allowable, not in profit',
        'exempt' => 'Deduct: exempt income',
        'allowance' => 'Deduct: capital allowances',
        'loss_set_off' => 'Deduct: assessed loss brought forward',
        'credit' => 'Credits: provisional tax paid',
    ];

    protected $table = 'tax_computation_lines';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['amount' => 'string'];
}
