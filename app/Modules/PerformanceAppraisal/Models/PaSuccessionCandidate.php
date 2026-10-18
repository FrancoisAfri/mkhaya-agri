<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaSuccessionCandidate extends Model
{
    protected $table = 'pa_succession_candidates';

    protected $fillable = [
        'plan_id', 'employee_id', 'readiness_level', 'potential_rating',
        'risk_of_loss', 'retention_priority', 'ai_readiness_score',
        'ai_recommendation', 'notes',
    ];

    protected $casts = [
        'ai_readiness_score' => 'decimal:2',
    ];

    public function plan(): BelongsTo      { return $this->belongsTo(PaSuccessionPlan::class, 'plan_id'); }
    public function employee(): BelongsTo  { return $this->belongsTo(User::class, 'employee_id'); }
}
