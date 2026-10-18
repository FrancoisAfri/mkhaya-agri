<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollSimulationItem extends Model
{
    use LogsActivity;

    protected $table = 'payroll_simulation_items';

    protected $fillable = [
        'simulation_id',
        'employee_id',
        'department_id',
        'current_value',
        'simulated_value',
        'difference',
        'percentage_change',
        'item_type',
        'notes',
    ];

    protected $casts = [
        'current_value'     => 'decimal:2',
        'simulated_value'   => 'decimal:2',
        'difference'        => 'decimal:2',
        'percentage_change' => 'decimal:4',
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

    public function simulation(): BelongsTo
    {
        return $this->belongsTo(PayrollSimulation::class, 'simulation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
