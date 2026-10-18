<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaPipProgressLog extends Model
{
    use LogsActivity;
    protected $table = 'pa_pip_progress_logs';
    protected $guarded = [];

    protected $casts = [
        'logged_at'    => 'date',
        'progress_pct' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'PIP Progress Log entry created',
                'updated' => 'PIP Progress Log entry updated',
                'deleted' => 'PIP Progress Log entry deleted',
                default   => $e,
            });
    }
}
