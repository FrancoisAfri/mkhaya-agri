<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaRoleCompetency extends Model
{
    protected $table = 'pa_role_competencies';

    protected $fillable = ['competency_id', 'position_id', 'expected_level', 'is_critical'];

    protected $casts = [
        'expected_level' => 'integer',
        'is_critical'    => 'boolean',
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(PaCompetency::class, 'competency_id');
    }
}
