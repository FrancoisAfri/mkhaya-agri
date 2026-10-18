<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "Come and look before I cover this up" (§23).
 *
 * Missing one is what turns finished work into work that has to be opened again, so the record
 * carries both when it was asked for and when it was asked *by* — the notice period is the point.
 */
class InspectionRequest extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_inspection_requests';

    public const STATUSES = ['requested', 'passed', 'failed', 'cancelled'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'number', 'title', 'element', 'task_id',
        'requested_for', 'requested_at', 'inspector', 'status', 'inspected_at',
        'outcome_note', 'snag_id', 'created_by',
    ];

    protected $casts = [
        'requested_for' => 'datetime',
        'requested_at'  => 'datetime',
        'inspected_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** How much warning the inspector actually got. */
    public function noticeHours(): ?int
    {
        return $this->requested_at && $this->requested_for
            ? (int) $this->requested_at->diffInHours($this->requested_for, false)
            : null;
    }

    /** Asked for, and the moment has passed with nobody recording an outcome. */
    public function isOverdue(): bool
    {
        return $this->status === 'requested' && $this->requested_for->isPast();
    }
}
