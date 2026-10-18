<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee on one run's roster.
 *
 * `added_via` separates the people the system put there from the people an officer chose, which is
 * the difference between "everybody on the monthly cycle" and "these three are getting a bonus".
 */
class BureauPayRunEmployee extends Model
{
    use BelongsToCompany;

    protected $table = 'bureau_pay_run_employees';

    public const AUTO = 'auto';
    public const MANUAL = 'manual';
    public const INPUT = 'input';

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(BureauEmployee::class, 'bureau_employee_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BureauPayRun::class, 'bureau_pay_run_id');
    }
}
