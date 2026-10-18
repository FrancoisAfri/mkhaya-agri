<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request to move the baseline (§13).
 *
 * The reason this exists as its own record rather than "just edit the plan": a project that quietly
 * changes its own dates and budget has no variance to report, and every overrun becomes invisible
 * by construction. A change request forces the impact to be stated *before* the decision, and the
 * decision goes through the module's one approval engine.
 *
 * `impact_days` is signed on purpose — a change can pull a date in, and a register that could only
 * express delay would misreport descoping as a schedule loss.
 */
class ChangeRequest extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_change_requests';

    public const KINDS = ['scope', 'budget', 'timeline', 'requirement'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'implemented'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'code', 'title', 'description', 'kind', 'reason',
        'impact_scope', 'impact_cost', 'impact_days', 'currency', 'impact_quality', 'impact_risk',
        'status', 'requested_by', 'approval_uuid', 'decided_at', 'decision_note',
        'implemented_at', 'baseline_id',
    ];

    protected $casts = [
        'impact_cost'     => 'decimal:2',
        'impact_days'     => 'integer',
        'decided_at'      => 'datetime',
        'implemented_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    /** Approved but not yet applied — the state where the plan and the decision disagree. */
    public function isAwaitingImplementation(): bool
    {
        return $this->status === 'approved' && $this->implemented_at === null;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
