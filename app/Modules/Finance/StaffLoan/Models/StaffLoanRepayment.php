<?php

namespace App\Modules\Finance\StaffLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLoanRepayment extends Model
{
    protected $fillable = [
        'loan_id', 'installment_number', 'due_date', 'amount_due',
        'principal_component', 'interest_component', 'amount_paid',
        'balance_before', 'balance_after', 'status', 'paid_at',
        'payment_method', 'reference', 'payroll_deducted', 'notes',
    ];

    protected $casts = [
        'amount_due'           => 'decimal:2',
        'principal_component'  => 'decimal:2',
        'interest_component'   => 'decimal:2',
        'amount_paid'          => 'decimal:2',
        'balance_before'       => 'decimal:2',
        'balance_after'        => 'decimal:2',
        'due_date'             => 'date',
        'paid_at'              => 'datetime',
        'payroll_deducted'     => 'boolean',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StaffLoan::class, 'loan_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }
}
