<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One configured payroll approval step. Ordered by step_number; each routes to a
 * role (anyone holding it) or a specific employee. Per-company (BelongsToCompany).
 */
class PayrollApprovalConfig extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_approval_configs';

    protected $fillable = [
        'company_id', 'step_number', 'name', 'approver_type', 'role_name', 'employee_id', 'is_active',
    ];

    protected $casts = [
        'step_number' => 'integer',
        'is_active'   => 'boolean',
    ];
}
