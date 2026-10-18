<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money spent on a project that is not somebody's hours (EPIC-040).
 *
 * Materials, plant hire, a subcontractor's invoice, a trip to the merchant. The lifecycle is the
 * timesheet's, for the same reason: until a supervisor has stood behind it, it is a claim, and a
 * cost report built from claims is one nobody can act on.
 */
class CostEntry extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_cost_entries';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'category', 'description',
        'amount', 'currency', 'incurred_on', 'status', 'supplier_ref_id', 'document_id',
        'invoice_number', 'is_billable', 'created_by', 'approved_by', 'approved_at',
        'paid_at', 'rejection_reason', 'posted_reference',
            // The tenant's own coding scheme, free text on purpose (§8).
        'cost_code',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'incurred_on' => 'date',
        'is_billable' => 'boolean',
        'approved_at' => 'datetime',
        'paid_at'     => 'datetime',
    ];

    public const CATEGORIES = ['material', 'plant', 'subcontractor', 'expense', 'travel', 'other'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'paid'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function supplierRef(): BelongsTo
    {
        return $this->belongsTo(ExternalRef::class, 'supplier_ref_id');
    }

    /** Money that has left the account. */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    /** Approved but unpaid: committed, and the reason budget headroom is smaller than it looks. */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** Everything a supervisor has stood behind, paid or not. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'paid']);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
