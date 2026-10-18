<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A node in the work breakdown structure — one table, unlimited depth.
 *
 * `path` is a materialised ancestry string ('/12/48/93/') rather than PostgreSQL ltree, so subtree
 * reads need no database extension (see the project-core migration). Both `path` and `wbs_code`
 * are derived from position and maintained by the WBS service on move/reorder; they are not user
 * input, and are excluded from $fillable accordingly.
 */
class WbsNode extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_wbs_nodes';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'parent_id', 'node_type', 'sort_order',
        'name', 'description', 'is_milestone', 'created_by',
    ];

    protected $casts = [
        'is_milestone' => 'boolean',
        'sort_order'   => 'integer',
        'depth'        => 'integer',
    ];

    public const NODE_TYPES = ['phase', 'deliverable', 'activity', 'task_group'];

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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'wbs_node_id');
    }

    // ── Subtree reads ────────────────────────────────────────────────────────

    /**
     * Everything beneath this node, at any depth. Index-backed prefix match — the reason `path`
     * is materialised at all.
     */
    public function scopeDescendantsOf(Builder $query, self $node): Builder
    {
        return $query->where('path', 'like', $node->path . '%')
            ->where('id', '!=', $node->id);
    }

    /** The path this node's children must carry. */
    public function childPath(): string
    {
        return $this->path . $this->id . '/';
    }
}
