<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEarningsLine extends Model
{
    protected $table = 'payroll_earnings_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'line_type' => EarningsLineType::class,
        'amount'    => 'decimal:2',
        'quantity'  => 'decimal:4',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
