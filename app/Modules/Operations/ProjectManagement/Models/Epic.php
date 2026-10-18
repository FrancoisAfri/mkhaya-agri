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
 * A body of work that spans sprints (§24).
 *
 * Deliberately thin: an epic is a label and a target, and its content is the tasks that point at
 * it. Giving it its own estimate and status that could disagree with its stories is how a board
 * ends up with an epic marked done over eleven open stories.
 */
class Epic extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_epics';

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'key', 'name', 'description',
        'colour', 'status', 'owner_user_id', 'target_date',
    ];

    protected $casts = ['target_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Task::class, 'epic_id');
    }
}
