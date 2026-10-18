<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who hears about a task (PM-1121).
 *
 * `source` records how the subscription arose, which is what lets an automatic one be removed for
 * good: without it, the next comment mentioning someone would re-add the watch they just cleared,
 * and they would conclude the unwatch button is broken.
 */
class TaskWatcher extends Model
{
    use BelongsToCompany, HasPublicUuid;

    public const SOURCES = ['manual', 'creator', 'assignee', 'mention'];

    protected $table = 'pm_task_watchers';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'user_id', 'source', 'is_muted',
    ];

    protected $casts = ['is_muted' => 'boolean'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
