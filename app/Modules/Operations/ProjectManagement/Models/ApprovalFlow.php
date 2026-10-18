<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * How one kind of thing gets approved in this company (EPIC-090).
 *
 * Designed rather than coded, because companies genuinely differ: a two-person contractor wants
 * the owner to see everything, a construction group wants site supervisor → contracts manager →
 * finance above R50,000 and nothing at all below it. Both are this table plus its steps.
 */
class ApprovalFlow extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_approval_flows';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'subject_type', 'is_active', 'threshold_amount',
        'currency', 'project_id', 'escalate_after_hours', 'description', 'created_by',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'threshold_amount'     => 'decimal:2',
        'escalate_after_hours' => 'integer',
    ];

    /** What can be routed. Each is something a person submits and somebody else has to stand behind. */
    public const SUBJECT_TYPES = [
        'timesheet', 'expense', 'document', 'project_stage', 'progress_claim',
        // A change request moves the baseline, so it belongs on the same ladder as the money —
        // and a tenant's "anything over R50 000 goes to the board" threshold then applies to
        // changes without being configured a second time.
        'change_request',
        // Who signs off a subcontract before it goes out, which differs sharply between companies:
        // a two-person practice wants none, a JBCC principal agent wants three. Designed in
        // PM Setup → Approvals rather than in code, and thresholds apply to it like anything else.
        'contract',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'flow_id')->orderBy('position');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'flow_id');
    }

    /** Set when the flow was written for one job rather than the whole tenant. */
    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Does an amount clear the threshold and therefore need routing at all?
     *
     * A null amount always routes: "we do not know what this costs" is not a reason to skip the
     * person whose job is to ask.
     */
    public function routes(?float $amount): bool
    {
        if ($this->threshold_amount === null) {
            return true;
        }

        return $amount === null || $amount >= (float) $this->threshold_amount;
    }
}
