<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaKpiScore extends Model
{
    use LogsActivity;
    protected $table = 'pa_kpi_scores';

    protected $fillable = [
        'employee_kpi_id', 'achieved_value', 'score_pct', 'weighted_score', 'notes', 'scored_by', 'scored_at',
    ];

    protected $casts = [
        'achieved_value'  => 'float',
        'score_pct'       => 'float',
        'weighted_score'  => 'float',
        'scored_at'       => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'KPI Score recorded',
                'updated' => 'KPI Score updated',
                'deleted' => 'KPI Score deleted',
                default   => $e,
            });
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(PaEmployeeKpi::class, 'employee_kpi_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by');
    }
}
