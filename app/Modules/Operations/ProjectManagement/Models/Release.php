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
 * What went out, and what was in it (§24).
 *
 * `revision` is the field that makes this record worth keeping. A release row with a version and a
 * date answers nothing six months later; one with the tag or commit that was actually deployed
 * answers "what was in 2.4.0" without anybody guessing.
 */
class Release extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_releases';

    public const STATUSES = ['planned', 'released', 'rolled_back'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'version', 'name', 'notes', 'target_date',
        'released_at', 'status', 'environment', 'revision', 'pipeline_url', 'released_by',
    ];

    protected $casts = ['target_date' => 'date', 'released_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Task::class, 'release_id');
    }
}
