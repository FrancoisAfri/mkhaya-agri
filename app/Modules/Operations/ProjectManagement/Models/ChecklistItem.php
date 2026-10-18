<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sub-step of a task that is not worth being a task (PM-1121).
 *
 * The distinction matters: a checklist item has no dates, no dependencies and no assignee, so it
 * never reaches the scheduling engine. "Tighten the bolts" belongs here; "Erect the tower crane"
 * does not.
 */
class ChecklistItem extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_task_checklist_items';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'text', 'position',
        'is_done', 'done_by', 'done_at', 'created_by',
    ];

    protected $casts = [
        'is_done'  => 'boolean',
        'done_at'  => 'datetime',
        'position' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
