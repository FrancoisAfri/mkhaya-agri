<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;

class BureauPayslipLine extends Model
{
    protected $table = 'bureau_payslip_lines';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'quantity' => 'decimal:2', 'taxable' => 'boolean'];
}
