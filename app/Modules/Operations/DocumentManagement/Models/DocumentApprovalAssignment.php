<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's part in an approval.
 *
 * Delegation keeps both ends: who was asked, and who answered. An audit trail that only recorded
 * the deputy would lose the fact that the request went to the finance manager, and one that only
 * recorded the manager would claim they approved something they did not read.
 */
class DocumentApprovalAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'document_approval_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'position' => 'integer',
        'decided_at' => 'datetime',
        'delegated_at' => 'datetime',
        'due_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const SKIPPED = 'skipped';

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentApprovalRequest::class, 'request_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    /** Whoever is actually expected to answer — the delegate if there is one. */
    public function responsibleId(): ?int
    {
        return $this->delegated_to ?: $this->assigned_to;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::PENDING
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * Whether this person may act on it.
     *
     * A delegated assignment belongs to the delegate, not to both — otherwise the original approver
     * could answer something they had already handed on, and the record would say two people
     * decided it.
     */
    public function isFor(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->delegated_to) {
            return (int) $this->delegated_to === (int) $user->id;
        }

        if ($this->assigned_to) {
            return (int) $this->assigned_to === (int) $user->id;
        }

        // A step assigned to a role rather than a person: whoever holds it may answer.
        return $this->assigned_role !== null && $user->hasRole($this->assigned_role);
    }
}
