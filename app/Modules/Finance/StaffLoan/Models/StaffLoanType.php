<?php

namespace App\Modules\Finance\StaffLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffLoanType extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name', 'description', 'min_amount', 'max_amount',
        'min_term_months', 'max_term_months', 'interest_rate', 'interest_type',
        'requires_permanent_status', 'requires_probation_completion',
        'executive_approval_threshold', 'is_active',
    ];

    protected $casts = [
        'min_amount'                  => 'decimal:2',
        'max_amount'                  => 'decimal:2',
        'interest_rate'               => 'decimal:2',
        'executive_approval_threshold'=> 'decimal:2',
        'requires_permanent_status'   => 'boolean',
        'requires_probation_completion'=> 'boolean',
        'is_active'                   => 'boolean',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(StaffLoan::class, 'loan_type_id');
    }
}
