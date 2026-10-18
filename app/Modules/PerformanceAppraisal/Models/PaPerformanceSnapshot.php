<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaPerformanceSnapshot extends Model
{
    use LogsActivity;

protected $table = 'pa_performance_snapshots';

    protected $fillable = [
        'employee_id', 'cycle_id', 'snapshot_date',
        'overall_score', 'kpi_completion_rate', 'data',
        'employee_score', 'manager_score', 'score_360', 'final_score',
        'appraisal_status', 'employee_comments', 'manager_comments',
        'submitted_at', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'snapshot_date'       => 'date',
        'overall_score'       => 'float',
        'kpi_completion_rate' => 'float',
        'data'                => 'array',
        'employee_score'      => 'float',
        'manager_score'       => 'float',
        'score_360'           => 'float',
        'final_score'         => 'float',
        'submitted_at'        => 'datetime',
        'approved_at'         => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['appraisal_status', 'employee_score', 'manager_score', 'score_360', 'final_score', 'approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Performance snapshot created',
                'updated' => 'Appraisal snapshot updated',
                'deleted' => 'Performance snapshot deleted',
                default   => $e,
            });
    }
}
