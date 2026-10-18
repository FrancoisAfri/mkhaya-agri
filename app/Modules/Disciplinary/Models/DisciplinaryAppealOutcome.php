<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAppealOutcome extends Model
{
    protected $table = 'disciplinary_appeal_outcomes';

    protected $fillable = [
        'appeal_id', 'decided_by', 'decided_at', 'verdict',
        'reasoning', 'new_sanction_id', 'outcome_notes',
        'sanction_changed', 'case_overturned', 'notify_employee',
    ];

    protected $casts = [
        'decided_at'       => 'datetime',
        'sanction_changed' => 'boolean',
        'case_overturned'  => 'boolean',
        'notify_employee'  => 'boolean',
    ];

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryAppeal::class, 'appeal_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function newSanction(): BelongsTo
    {
        return $this->belongsTo(DisciplinarySanction::class, 'new_sanction_id');
    }
}
