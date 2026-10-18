<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaGoal extends Model
{
    use LogsActivity;
    protected $table = 'pa_goals';

    protected $fillable = [
        'cycle_id', 'parent_goal_id', 'title', 'description', 'level',
        'owner_id', 'owner_unit_id', 'start_date', 'end_date',
        'status', 'target_value', 'unit', 'current_value', 'weight', 'created_by',
    ];

    protected $casts = [
        'start_date'    => 'date',
        'end_date'      => 'date',
        'target_value'  => 'float',
        'current_value' => 'float',
        'weight'        => 'float',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PaGoal::class, 'parent_goal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(PaGoal::class, 'parent_goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function ownerUnit(): BelongsTo
    {
        return $this->belongsTo(CompanyUnit::class, 'owner_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(PaEmployeeKpi::class, 'goal_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Performance Goal created',
                'updated' => 'Performance Goal updated',
                'deleted' => 'Performance Goal deleted',
                default   => $e,
            });
    }

    public function progressPct(): float
    {
        if (!$this->target_value || $this->target_value == 0) {
            return 0;
        }

        return min(100, round(($this->current_value / $this->target_value) * 100, 1));
    }
}
