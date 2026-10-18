<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryRehabilitationProgress extends Model
{
    protected $table = 'disciplinary_rehabilitation_progress';

    protected $fillable = [
        'employee_id', 'case_id', 'period_start', 'period_end',
        'behavior_score', 'attendance_score', 'performance_score',
        'compliance_score', 'overall_score', 'trend',
        'assessor_id', 'notes', 'is_ai_generated', 'ai_provider', 'ai_model',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'is_ai_generated' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }
}
