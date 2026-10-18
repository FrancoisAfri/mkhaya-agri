<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaKpiProgressLog extends Model
{
    use LogsActivity;

    protected $table = 'pa_kpi_progress_logs';

    protected $fillable = ['employee_kpi_id', 'value', 'notes', 'updated_by', 'log_date'];

    protected $casts = [
        'value'    => 'float',
        'log_date' => 'date',
    ];

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(PaEmployeeKpi::class, 'employee_kpi_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['employee_kpi_id', 'value', 'log_date', 'notes', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'KPI progress logged',
                'updated' => 'KPI progress entry updated',
                'deleted' => 'KPI progress entry deleted',
                default   => $e,
            });
    }
}
