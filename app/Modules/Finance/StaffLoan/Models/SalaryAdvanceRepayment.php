<?php

namespace App\Modules\Finance\StaffLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvanceRepayment extends Model
{
    protected $fillable = [
        'advance_id', 'due_date', 'amount_due', 'amount_paid',
        'status', 'paid_at', 'method', 'payroll_deducted', 'notes',
    ];

    protected $casts = [
        'amount_due'      => 'decimal:2',
        'amount_paid'     => 'decimal:2',
        'due_date'        => 'date',
        'paid_at'         => 'datetime',
        'payroll_deducted'=> 'boolean',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'advance_id');
    }
}
