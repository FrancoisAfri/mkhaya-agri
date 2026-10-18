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
 * One thing waiting for a decision.
 *
 * `subject_label` and `amount` are denormalised on purpose. The approvals inbox is a list of
 * things from four different tables, and a page that joined all four to render thirty rows would
 * be slow for no benefit — the label is what the approver reads, and it is what the thing was
 * called when it was submitted, which is the honest thing to show them.
 */
class ApprovalRequest extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_approval_requests';

    protected $fillable = [
        'uuid', 'company_id', 'flow_id', 'project_id', 'subject_type', 'subject_id',
        'subject_uuid', 'subject_label', 'amount', 'currency', 'status', 'current_position',
        'requested_by', 'note', 'decided_at', 'decision_reason', 'escalated_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'current_position' => 'integer',
        'decided_at'       => 'datetime',
        'escalated_at'     => 'datetime',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'flow_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'request_id')->orderBy('id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** The step this request is sitting on, or null when the ladder has been climbed. */
    public function currentStep(): ?ApprovalStep
    {
        return $this->flow?->steps()->where('position', $this->current_position)->first();
    }

    /** How long it has been waiting — what the inbox sorts by and what escalation measures. */
    public function waitingHours(): int
    {
        return (int) $this->created_at?->diffInHours(now());
    }
}
