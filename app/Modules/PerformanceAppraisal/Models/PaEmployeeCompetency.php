<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaEmployeeCompetency extends Model
{
    protected $table = 'pa_employee_competencies';

    protected $fillable = [
        'employee_id', 'competency_id', 'cycle_id', 'current_level', 'target_level',
        'self_rated', 'assessed_by', 'assessed_at', 'notes',
    ];

    protected $casts = [
        'current_level' => 'integer',
        'target_level'  => 'integer',
        'self_rated'    => 'boolean',
        'assessed_at'   => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(PaCompetency::class, 'competency_id');
    }

    public function gap(): int
    {
        return max(0, (int) $this->target_level - (int) $this->current_level);
    }
}
