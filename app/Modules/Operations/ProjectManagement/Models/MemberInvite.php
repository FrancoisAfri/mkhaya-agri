<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An outstanding invitation for an external party to reach the portal.
 *
 * Deliberately not audited with AuditsChanges: the interesting events are issued, accepted and
 * revoked, which are recorded here as timestamps and emitted to the audit log by the service. A
 * change diff on a token row would mostly record the token being consumed.
 */
class MemberInvite extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    /**
     * The token in the clear, set once when the invitation is issued and never persisted.
     *
     * A declared property rather than an Eloquent attribute on purpose: an attribute would join
     * `$attributes` and a later save() would try to write a column that does not exist — and,
     * worse, would put the plain token somewhere it could be read back.
     */
    public ?string $plainToken = null;

    /**
     * Whether the invitation email actually left the building.
     *
     * Not a column: it is true of one attempt, not of the invitation. The caller uses it to decide
     * whether to hand the link over by some other means.
     */
    public bool $delivered = false;

    protected $table = 'pm_member_invites';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'member_id', 'email', 'token_hash', 'role_key',
        'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'revoked_by',
    ];

    /**
     * The token never appears in an array or JSON representation.
     *
     * It is not stored — only its hash is — but hiding the hash as well means a controller that
     * spreads this model into an Inertia payload cannot hand an attacker the value to brute-force
     * against. Payloads in this module are built field by field for the same reason.
     */
    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'member_id');
    }

    /** Still usable: not taken up, not withdrawn, not out of time. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Why this invitation cannot be used, as a translation key — or null when it can.
     *
     * One method rather than three checks at the call site, so the accept screen and the tests
     * cannot disagree about which reason takes precedence.
     */
    public function refusalReason(): ?string
    {
        return match (true) {
            $this->accepted_at !== null => 'project_management.invites.already_accepted',
            $this->revoked_at !== null  => 'project_management.invites.revoked',
            $this->expires_at->isPast() => 'project_management.invites.expired',
            default                     => null,
        };
    }
}
