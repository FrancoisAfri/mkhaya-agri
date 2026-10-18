<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryEscalation extends Model
{
    protected $table = 'disciplinary_escalations';

    protected $fillable = [
        'employee_id', 'case_id', 'from_level', 'to_level',
        'reason', 'triggered_by', 'ai_recommended', 'ai_reasoning',
        'prior_offences_count', 'approved', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at'          => 'datetime',
        'ai_recommended'       => 'boolean',
        'approved'             => 'boolean',
        'prior_offences_count' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('approved', false);
    }
}
