<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A project. Owns the three things everything below it inherits: currency (PM-ADR-006), timezone,
 * and the working calendar the scheduler uses (PM-ADR-005).
 *
 * Client and manager are deliberately not foreign keys — they resolve through pm_external_refs, so
 * a tenant without CRM or HR licensed still gets a working project with a named client and manager.
 */
class Project extends Model
{
    use HasDocuments;

    use AuditsChanges, BelongsToCompany, HasFactory, HasPublicUuid, LogsActivity, SoftDeletes;


    /** Factories live under Database\Factories\ProjectManagement, not the default path. */
    protected static function newFactory(): \Database\Factories\ProjectManagement\ProjectFactory
    {
        return \Database\Factories\ProjectManagement\ProjectFactory::new();
    }

    protected $table = 'pm_projects';

    /** `id` is never mass-assignable: only `uuid` is client-supplied (PM-ADR-003). */
    protected $fillable = [
        'uuid', 'company_id', 'branch_id', 'department_id', 'code', 'name', 'description',
        'lens_code', 'status', 'stage', 'priority', 'client_ref_id', 'manager_id',
        'currency', 'timezone', 'calendar_id', 'planned_start', 'planned_end',
        'actual_start', 'actual_end', 'location_name', 'geo_lat', 'geo_lng',
        'budget_amount', 'is_billable', 'timesheet_approval_required', 'created_by',
            'portfolio_id', 'program_id', 'template_id',
    ];

    protected $casts = [
        'timesheet_approval_required' => 'boolean',
        'planned_start' => 'date',
        'planned_end'   => 'date',
        'actual_start'  => 'date',
        'actual_end'    => 'date',
        'is_billable'   => 'boolean',
        // Money stays a decimal string end-to-end — casting to float would reintroduce exactly the
        // representation error PM-ADR-006 forbids.
        'budget_amount' => 'decimal:2',
        'geo_lat'       => 'decimal:6',
        'geo_lng'       => 'decimal:6',
    ];

    public const STATUSES = ['draft', 'active', 'on_hold', 'completed', 'cancelled', 'archived'];

    public const STAGES = ['initiation', 'planning', 'execution', 'monitoring', 'closing'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'calendar_id');
    }

    public function clientRef(): BelongsTo
    {
        return $this->belongsTo(ExternalRef::class, 'client_ref_id');
    }

    public function wbsNodes(): HasMany
    {
        return $this->hasMany(WbsNode::class, 'project_id');
    }

    /** Saved plans, newest last. Earned value measures against the most recent one. */
    public function baselines(): HasMany
    {
        return $this->hasMany(Baseline::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'project_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceEvent::class, 'project_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'on_hold']);
    }

    /**
     * Limit to the projects this user may see (PM-1020).
     *
     * Someone with a tenant-wide grant sees everything in their tenant; someone whose access is
     * project-scoped sees only the projects they are a member of. This sits on the query rather
     * than in a controller so the API and the UI cannot drift — "cannot list projects outside
     * their scope" has to be true of the list itself, not of one screen.
     *
     * Tenancy is a separate matter, already handled by CompanyScope and row-level security.
     */
    public function scopeVisibleTo($query, ?\App\Core\User\Models\User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $ids = app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class)
            ->visibleProjectIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    /** The portfolio this project competes for money in (§2). Optional. */
    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id');
    }

    /**
     * The programme this project delivers part of (§2). Optional.
     *
     * The column is `program_id` — Sprint 1's spelling — and is adopted rather than duplicated.
     */
    public function programme()
    {
        return $this->belongsTo(Programme::class, 'program_id');
    }

    /** What this project was created from, so a template's usefulness is measurable (§3). */
    public function template()
    {
        return $this->belongsTo(ProjectTemplate::class, 'template_id');
    }

    public function stageGates()
    {
        return $this->hasMany(StageGate::class, 'project_id')->orderBy('position');
    }
}
