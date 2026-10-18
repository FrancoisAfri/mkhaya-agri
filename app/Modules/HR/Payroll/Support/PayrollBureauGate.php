<?php

namespace App\Modules\HR\Payroll\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether the Payroll Bureau capability is activated for this deployment. Bureau
 * features (multi-company agent account) are hidden unless a bureau has bought
 * and activated the module; a normal single-company payroll never sees them.
 */
class PayrollBureauGate
{
    public static function active(): bool
    {
        return Cache::remember('module_active_payroll_bureau', 300, fn () =>
            DB::table('security_modules')->where('code_name', 'payroll_bureau')->where('active', 1)->exists());
    }
}
