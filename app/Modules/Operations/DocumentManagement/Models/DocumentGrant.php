<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One document, shared with one person, role, department, or everybody.
 *
 * Only ever additive. Nothing here removes access from somebody who already had it — the folder and
 * confidentiality rules are the floor and this raises it for a single document.
 */
class DocumentGrant extends Model
{
    use BelongsToCompany;

    protected $table = 'document_grants';

    protected $guarded = ['id'];

    protected $casts = [
        'can_download' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public const SUBJECT_USER = 'user';
    public const SUBJECT_ROLE = 'role';
    public const SUBJECT_DEPARTMENT = 'department';

    /** Everybody the module already lets in — what "share with the company" means. */
    public const SUBJECT_COMPANY = 'company';

    /**
     * A named group.
     *
     * Distinct from a role: a role is a job somebody holds across the whole system, where a group
     * is a set of people assembled for a purpose — "the external auditors for the 2026 audit" —
     * often temporary and often crossing departments.
     */
    public const SUBJECT_GROUP = 'group';

    public const SUBJECTS = [
        self::SUBJECT_USER,
        self::SUBJECT_ROLE,
        self::SUBJECT_DEPARTMENT,
        self::SUBJECT_COMPANY,
        self::SUBJECT_GROUP,
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopeLive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
