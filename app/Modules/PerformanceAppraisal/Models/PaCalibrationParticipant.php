<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCalibrationParticipant extends Model
{
    use LogsActivity;
    protected $table = 'pa_calibration_participants';
    protected $guarded = [];

    protected $casts = [
        'original_score'  => 'float',
        'calibrated_score'=> 'float',
        'finalized'       => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Calibration Participant added',
                'updated' => 'Calibration Participant score updated',
                'deleted' => 'Calibration Participant removed',
                default   => $e,
            });
    }

    public function adjustment(): float
    {
        if ($this->original_score === null || $this->calibrated_score === null) return 0;
        return round($this->calibrated_score - $this->original_score, 2);
    }
}
