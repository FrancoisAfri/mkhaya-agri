<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DisciplinaryAppeal extends Model
{
    use SoftDeletes;
    protected $table = 'disciplinary_appeals';

    protected $fillable = [
        'appeal_number', 'case_id', 'employee_id', 'hearing_id', 'appeal_hearing_id',
        'grounds', 'supporting_argument', 'deadline_date', 'submitted_at',
        'submitted_by', 'chairperson_id', 'hr_rep_id', 'appeal_hearing_date',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'deadline_date'       => 'date',
        'submitted_at'        => 'datetime',
        'appeal_hearing_date' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function appealHearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'appeal_hearing_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairperson_id');
    }

    public function hrRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_rep_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisciplinaryAppealEvidence::class, 'appeal_id');
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(DisciplinaryAppealOutcome::class, 'appeal_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['decided', 'withdrawn', 'lapsed']);
    }
}
