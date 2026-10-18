<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaEmployeeKpi extends Model
{
    use LogsActivity;
    protected $table = 'pa_employee_kpis';

    protected $fillable = [
        'cycle_id', 'employee_id', 'goal_id', 'title', 'description',
        'target_value', 'unit', 'weighting',
        'self_update_allowed', 'manager_update_allowed', 'status', 'created_by',
    ];

    protected $casts = [
        'target_value'         => 'float',
        'weighting'            => 'float',
        'self_update_allowed'  => 'boolean',
        'manager_update_allowed' => 'boolean',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(PaGoal::class, 'goal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(PaKpiProgressLog::class, 'employee_kpi_id')->orderBy('log_date');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PaKpiEvidence::class, 'employee_kpi_id');
    }

    public function score(): HasOne
    {
        return $this->hasOne(PaKpiScore::class, 'employee_kpi_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Employee KPI created',
                'updated' => 'Employee KPI updated',
                'deleted' => 'Employee KPI deleted',
                default   => $e,
            });
    }

    public function latestValue(): ?float
    {
        return $this->progressLogs()->latest('log_date')->value('value');
    }
}
