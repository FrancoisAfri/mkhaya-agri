<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCalibrationSession extends Model
{
    use LogsActivity;
    protected $table = 'pa_calibration_sessions';
    protected $guarded = [];

    protected $casts = [
        'scheduled_date' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Calibration Session created',
                'updated' => 'Calibration Session updated',
                'deleted' => 'Calibration Session deleted',
                default   => $e,
            });
    }

    public function participants(): HasMany
    {
        return $this->hasMany(PaCalibrationParticipant::class, 'session_id');
    }
}
