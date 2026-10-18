<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One review in a workflow.
 *
 * Sequential and parallel fall out of `position` rather than a flag: steps sharing a position run
 * at the same time, consecutive positions run in order. A diagram of the workflow is then the same
 * shape as the rows, which is one fewer thing to keep in step.
 *
 * `quorum` is the relationship *within* a step — whether everybody named must approve, or one of
 * them is enough.
 */
class DocumentApprovalStep extends Model
{
    use BelongsToCompany;

    protected $table = 'document_approval_steps';

    protected $guarded = ['id'];

    protected $casts = ['position' => 'integer', 'deadline_days' => 'integer'];

    public const QUORUM_ALL = 'all';
    public const QUORUM_ANY = 'any';

    public const APPROVER_ROLE = 'role';
    public const APPROVER_USER = 'user';

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentApprovalWorkflow::class, 'workflow_id');
    }

    public function requiresEveryone(): bool
    {
        return $this->quorum === self::QUORUM_ALL;
    }
}
