<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One document going through one workflow. */
class DocumentApprovalRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'document_approval_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'current_position' => 'integer',
    ];

    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentApprovalWorkflow::class, 'workflow_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DocumentApprovalAssignment::class, 'request_id')->orderBy('position');
    }

    /** Still moving, as opposed to decided one way or the other. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::UNDER_REVIEW, self::CHANGES_REQUESTED], true);
    }

    /** The assignments waiting on somebody right now. */
    public function currentAssignments()
    {
        return $this->assignments()
            ->where('position', $this->current_position)
            ->where('status', DocumentApprovalAssignment::PENDING);
    }
}
