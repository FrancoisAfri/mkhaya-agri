<?php

namespace App\Modules\Finance\StaffLoan\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLoanAffordabilityCheck extends Model
{
    protected $fillable = [
        'loan_id', 'advance_id', 'employee_id',
        'monthly_net_pay', 'existing_deductions', 'proposed_deduction',
        'total_deductions_after', 'affordability_ratio', 'disposable_income',
        'passed', 'failure_reason', 'checked_at',
    ];

    protected $casts = [
        'monthly_net_pay'       => 'decimal:2',
        'existing_deductions'   => 'decimal:2',
        'proposed_deduction'    => 'decimal:2',
        'total_deductions_after'=> 'decimal:2',
        'affordability_ratio'   => 'float',
        'disposable_income'     => 'decimal:2',
        'passed'                => 'boolean',
        'checked_at'            => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StaffLoan::class, 'loan_id');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'advance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function affordabilityPct(): float
    {
        return round($this->affordability_ratio * 100, 2);
    }
}
