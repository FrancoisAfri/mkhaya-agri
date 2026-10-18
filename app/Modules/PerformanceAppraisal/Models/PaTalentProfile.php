<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaTalentProfile extends Model
{
    use LogsActivity;
    protected $table = 'pa_talent_profiles';
    protected $guarded = [];

    protected $casts = [
        'promotion_readiness_score' => 'float',
        'leadership_score'          => 'float',
        'is_leadership_pipeline'    => 'boolean',
        'kpi_composite_score'       => 'float',
        'skill_depth_avg'           => 'float',
        'composite_score'           => 'float',
        'assessed_at'               => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Talent Profile created',
                'updated' => 'Talent Profile updated',
                'deleted' => 'Talent Profile deleted',
                default   => $e,
            });
    }

    public static function tierFromScore(float $score): string
    {
        return match (true) {
            $score >= 85 => 'top_talent',
            $score >= 70 => 'high_performer',
            $score >= 50 => 'contributor',
            default      => 'emerging',
        };
    }

    public static function readinessLevelFromScore(float $score): string
    {
        return match (true) {
            $score >= 80 => 'ready_now',
            $score >= 60 => 'ready',
            $score >= 40 => 'developing',
            default      => 'not_ready',
        };
    }
}
