<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The project conversation (§19).
 *
 * `author_name` is snapshotted rather than joined. A message from somebody who has since left the
 * company still has to read as theirs — a thread that turns into "Unknown user" the day somebody is
 * offboarded is a thread nobody can follow afterwards.
 */
class ChatMessage extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_chat_messages';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'user_id', 'author_name', 'body',
        'about_type', 'about_uuid', 'attachment_uuid',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
