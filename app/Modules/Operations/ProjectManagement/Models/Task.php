<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A schedulable unit of work.
 *
 * Two column groups must not be confused:
 *  - planned_* / constraint_* / duration are *input* to the CPM engine, set by users.
 *  - early_*, late_*, float and is_critical are *output*, written only by the scheduling service
 *    after it runs the pure engine (PM-ADR-005). They are excluded from $fillable so a request
 *    payload can never fabricate a critical path.
 *
 * progress_pct is a read-optimised mirror of the derived value. The ledger remains the source of
 * truth; progress_source records whether the number came from evidence ('derived') or from a
 * person typing it ('manual').
 */
class Task extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_tasks';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'wbs_node_id', 'parent_task_id', 'code', 'name',
        // `status` is the meaning — what the ledger, progress derivation and claims read.
        // `status_key` is merely which of the project's own board columns it sits in (T-0008).
        'description', 'status', 'status_key', 'priority', 'schedule_mode', 'constraint_type', 'constraint_date',
        'duration_minutes', 'calendar_id', 'planned_start', 'planned_end', 'actual_start',
        'actual_end', 'estimated_hours', 'planned_quantity', 'quantity_unit',
        'budget_amount', 'currency', 'is_milestone', 'payment_amount', 'payment_claimed', 'created_by',
        // Set when this task is an action out of a meeting (§18).
        'meeting_id',
        // Agile (§24). A story is a task that happens to carry these, which is what lets the
        // board, the scheduler and the timesheets work on an agile project unchanged.
        'sprint_id', 'epic_id', 'release_id', 'story_points', 'backlog_rank',
        'occurrence_of_id', 'occurrence_date', 'is_recurrence_template',
    ];

    protected $casts = [
        'constraint_date'     => 'date',
        'planned_start'       => 'datetime',
        'planned_end'         => 'datetime',
        'commit_start'        => 'datetime',
        'commit_end'          => 'datetime',
        'actual_start'        => 'datetime',
        'actual_end'          => 'datetime',
        'early_start'         => 'datetime',
        'early_finish'        => 'datetime',
        'late_start'          => 'datetime',
        'late_finish'         => 'datetime',
        'scheduled_at'        => 'datetime',
        'occurrence_date'     => 'datetime',
        'is_critical'         => 'boolean',
        'is_milestone'        => 'boolean',
        'story_points'        => 'decimal:2',
        'backlog_rank'        => 'float',
        'payment_amount'      => 'decimal:2',
        'payment_claimed'     => 'boolean',
        'is_recurrence_template' => 'boolean',
        'duration_minutes'    => 'integer',
        'total_float_minutes' => 'integer',
        'free_float_minutes'  => 'integer',
        'estimated_hours'     => 'decimal:2',
        'planned_quantity'    => 'decimal:4',
        'budget_amount'       => 'decimal:2',
        'progress_pct'        => 'decimal:2',
    ];

    public const STATUSES = ['not_started', 'in_progress', 'blocked', 'done', 'cancelled', 'archived'];

    /**
     * Archived: kept, and out of the way.
     *
     * A project accumulates tasks that are neither done nor cancelled — superseded, deferred,
     * raised twice — and leaving them on the board buries the work that is live. Deleting them
     * loses the reason they existed, which is the thing somebody asks about six months later.
     *
     * So it is a status rather than a flag, and the board simply does not draw a column for it:
     * archiving a task takes it off the board, and it is still there when anybody looks.
     */
    public const STATUS_ARCHIVED = 'archived';

    /** The statuses the board draws a column for. */
    public const BOARD_STATUSES = ['not_started', 'in_progress', 'blocked', 'done', 'cancelled'];

    /** Scheduling constraints, MS-Project semantics. */
    public const CONSTRAINT_TYPES = ['asap', 'alap', 'snet', 'snlt', 'fnet', 'fnlt', 'mso', 'mfo'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'task_id')->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id')->orderBy('id');
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(TaskWatcher::class, 'task_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'task_id');
    }

    /** The recurrence this task was generated from, if it was. */
    public function occurrenceOf(): BelongsTo
    {
        return $this->belongsTo(TaskRecurrence::class, 'occurrence_of_id');
    }

    /** The rule attached to this task, when it is a recurrence template. */
    public function recurrence(): HasOne
    {
        return $this->hasOne(TaskRecurrence::class, 'template_task_id');
    }

    /** Links where this task is the predecessor — i.e. what waits on it. */
    public function successorLinks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'predecessor_id');
    }

    /** Links where this task is the successor — i.e. what it waits on. */
    public function predecessorLinks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'successor_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Everything a person is meant to see in a list.
     *
     * A recurrence template is a stencil, not work: it has no real dates, nobody is assigned to it,
     * and showing it alongside its occurrences makes every count wrong by one.
     */
    public function scopeReal($query)
    {
        return $query->where('is_recurrence_template', false);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['done', 'cancelled', self::STATUS_ARCHIVED]);
    }

    /**
     * What belongs on the board.
     *
     * Archived tasks are kept and simply not drawn. Passing true brings them back for the screen
     * that exists to look at them, which is the whole reason archiving is not deletion.
     */
    public function scopeOnBoard($query, bool $includeArchived = false)
    {
        return $includeArchived ? $query : $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }
}
