<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A plan somebody can start from (§3).
 *
 * The whole plan — WBS, tasks, milestones, starter risks and stage gates — lives in `payload` as
 * one JSON document rather than in shadow copies of the real tables. Shadow tables would double
 * every future schema change to `pm_wbs_nodes` and `pm_tasks`, and a template is read exactly once
 * (at creation) and never joined against.
 *
 * `times_used` exists so a template's usefulness can be measured rather than assumed — a library of
 * forty templates where two are ever used is a library nobody maintains.
 */
class ProjectTemplate extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_project_templates';

    /** The industries §3 names, plus a catch-all so nothing is unrecordable. */
    public const INDUSTRIES = [
        'construction', 'it', 'marketing', 'manufacturing', 'research',
        'government', 'engineering', 'events', 'hr', 'training', 'other',
    ];

    protected $fillable = [
        'uuid', 'company_id', 'name', 'industry', 'sub_type', 'description',
        'payload', 'times_used', 'is_builtin', 'is_active', 'created_by',
    ];

    protected $casts = [
        'payload'    => 'array',
        'times_used' => 'integer',
        'is_builtin' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return list<array<string,mixed>> */
    public function section(string $key): array
    {
        return (array) ($this->payload[$key] ?? []);
    }
}
