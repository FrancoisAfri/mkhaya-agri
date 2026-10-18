<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryCoachingSession extends Model
{
    protected $table = 'disciplinary_coaching_sessions';

    protected $fillable = [
        'employee_id', 'case_id', 'corrective_action_id', 'coach_id',
        'session_type', 'scheduled_at', 'duration_minutes', 'venue',
        'agenda', 'session_notes', 'outcome', 'status', 'rescheduled_to',
        'employee_consented', 'created_by',
    ];

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'rescheduled_to'    => 'datetime',
        'duration_minutes'  => 'integer',
        'employee_consented'=> 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function correctiveAction(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCorrectiveAction::class, 'corrective_action_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now());
    }
}
