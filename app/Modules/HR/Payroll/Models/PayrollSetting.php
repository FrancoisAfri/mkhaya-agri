<?php

namespace App\Modules\HR\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollSetting extends Model
{
    use LogsActivity;

    protected $table = 'payroll_settings';

    protected $fillable = [
        'currency',
        // What BCEA §33 requires a payslip to state about the employer, and what SARS returns are
        // filed under. Without these the payslip could not name the employer at all.
        'employer_name', 'employer_trading_name', 'employer_address',
        'paye_reference', 'uif_reference', 'sdl_reference', 'employer_contact',
        'pay_frequency',
        'pay_day',
        'overtime_rate',
        'paye_enabled',
        'uif_enabled',
        'sdl_enabled',
        'uif_employee_rate',
        'uif_employer_rate',
        'sdl_rate',
        'payroll_budget',
        'settings_json',
        'is_active',
    ];

    protected $casts = [
        'pay_day'           => 'integer',
        'overtime_rate'     => 'decimal:2',
        'paye_enabled'      => 'boolean',
        'uif_enabled'       => 'boolean',
        'sdl_enabled'       => 'boolean',
        'uif_employee_rate' => 'decimal:4',
        'uif_employer_rate' => 'decimal:4',
        'sdl_rate'          => 'decimal:4',
        'payroll_budget'    => 'decimal:2',
        'settings_json'     => 'array',
        'is_active'         => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logFillable();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->causer_id   = auth()->id();
        $activity->causer_type = auth()->user() ? get_class(auth()->user()) : null;

        $properties = $activity->properties->toArray();
        $properties['ip_address'] = request()->ip();
        $properties['user_agent'] = request()->userAgent();
        $activity->properties = collect($properties);
    }
}
