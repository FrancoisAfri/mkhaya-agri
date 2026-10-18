<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryCorrectiveAction extends Model
{
    use StoresFileBytes;

    protected $table = 'disciplinary_corrective_actions';

    protected $fillable = [
        'employee_id', 'case_id', 'action_number', 'action_type',
        'title', 'description', 'assigned_by', 'assigned_date', 'due_date',
        'completed_date', 'status', 'progress_percent', 'progress_notes',
        'completion_evidence', 'is_mandatory', 'ai_recommended', 'ai_reasoning',
        'file_uuid', 'original_name', 'mime', 'file_data', 'created_by',
    ];

    protected $casts = [
        'assigned_date'    => 'date',
        'due_date'         => 'date',
        'completed_date'   => 'date',
        'progress_percent' => 'integer',
        'is_mandatory'     => 'boolean',
        'ai_recommended'   => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function coachingSessions(): HasMany
    {
        return $this->hasMany(DisciplinaryCoachingSession::class, 'corrective_action_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', '!=', 'completed')
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '<', now()->toDateString());
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/corrective-actions';
    }
}
