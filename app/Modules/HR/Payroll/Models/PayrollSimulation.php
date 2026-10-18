<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollSimulation extends Model
{
    use LogsActivity;

    protected $table = 'payroll_simulations';

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'created_by',
        'base_run_id',
        'simulation_type',
        'parameters',
        'total_current_cost',
        'total_simulated_cost',
        'total_difference',
        'percentage_change',
        'status',
        'notes',
    ];

    protected $casts = [
        'parameters'           => 'array',
        'total_current_cost'   => 'decimal:2',
        'total_simulated_cost' => 'decimal:2',
        'total_difference'     => 'decimal:2',
        'percentage_change'    => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logOnly(['name', 'simulation_type', 'status']);
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function baseRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'base_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollSimulationItem::class, 'simulation_id');
    }
}
