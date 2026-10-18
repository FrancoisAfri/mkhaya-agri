<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file on a task (PM-1121).
 *
 * PM stores the reference, never the bytes: `document_id` is whatever the installed DocumentStore
 * handed back — a pm_external_refs uuid locally, the Documents module's own id when that is
 * licensed. Swapping stores therefore does not migrate this table.
 */
class TaskAttachment extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_task_attachments';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'document_id',
        'filename', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
