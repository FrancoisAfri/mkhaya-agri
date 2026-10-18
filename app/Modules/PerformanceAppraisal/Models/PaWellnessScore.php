<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaWellnessScore extends Model
{
    protected $table = 'pa_wellness_scores';

    protected $fillable = [
        'employee_id', 'cycle_id', 'wellness_score', 'wellness_grade',
        'kpi_score', 'feedback_score', 'checkin_score', 'engagement_score',
        'burnout_risk', 'disengagement_level', 'signals', 'is_ai_generated', 'generated_by',
    ];

    protected $casts = [
        'signals'          => 'array',
        'is_ai_generated'  => 'boolean',
    ];

    public function employee(): BelongsTo  { return $this->belongsTo(User::class, 'employee_id'); }
    public function cycle(): BelongsTo     { return $this->belongsTo(PaCycle::class); }
    public function generator(): BelongsTo { return $this->belongsTo(User::class, 'generated_by'); }

    public static function gradeFromScore(int $score): string
    {
        return match(true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score >= 40 => 'D',
            default      => 'F',
        };
    }
}
