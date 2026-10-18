<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryHearingOutcome extends Model
{
    protected $table = 'disciplinary_hearing_outcomes';

    protected $fillable = [
        'hearing_id', 'outcome_id', 'sanction_id', 'verdict',
        'reasoning', 'sanction_detail', 'effective_date', 'appeal_deadline',
        'appeal_lodged', 'appeal_grounds', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'effective_date'  => 'date',
        'appeal_deadline' => 'date',
        'appeal_lodged'   => 'boolean',
        'decided_at'      => 'datetime',
    ];

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryOutcome::class, 'outcome_id');
    }

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(DisciplinarySanction::class, 'sanction_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function verdictColor(): string
    {
        return match ($this->verdict) {
            'guilty'       => 'error',
            'not_guilty'   => 'success',
            'inconclusive' => 'warning',
            default        => 'grey',
        };
    }
}
