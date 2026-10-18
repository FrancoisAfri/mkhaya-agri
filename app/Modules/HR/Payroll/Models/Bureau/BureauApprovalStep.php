<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One step in a client's approval chain.
 *
 * A step names who may sign it — anyone holding a role, or one particular person. The distinction
 * matters operationally: "any finance manager" keeps a payroll moving when somebody is on leave,
 * while "this director" is what a client asks for when they want one named signature on the money.
 */
class BureauApprovalStep extends Model
{
    use BelongsToCompany;

    protected $table = 'bureau_approval_steps';

    public const BY_ROLE = 'role';
    public const BY_USER = 'user';

    protected $guarded = ['id'];

    protected $casts = [
        'sequence'            => 'integer',
        'allow_self_approval' => 'boolean',
        'is_active'           => 'boolean',
    ];

    /** What a bureau gets before anybody configures anything: one review, one release. */
    public const DEFAULTS = [
        ['sequence' => 1, 'name' => 'Payroll review', 'approver_type' => self::BY_ROLE, 'role_name' => 'payroll officer'],
        ['sequence' => 2, 'name' => 'Release approval', 'approver_type' => self::BY_ROLE, 'role_name' => 'finance manager'],
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    /**
     * May this person sign this step?
     *
     * The creator is refused unless the step says otherwise, because a chain whose steps can all be
     * signed by the person who calculated the run is a chain that approves nothing.
     */
    public function mayBeSignedBy(?User $user, ?int $runCreatedBy): bool
    {
        if (! $user) {
            return false;
        }

        if (! $this->allow_self_approval && $runCreatedBy !== null && $runCreatedBy === $user->id) {
            return false;
        }

        if ($this->approver_type === self::BY_USER) {
            return $this->approver_id === $user->id;
        }

        return blank($this->role_name) || $user->hasRole($this->role_name);
    }

    /** Who to name on screen when somebody asks who is holding this up. */
    public function approverLabel(): string
    {
        return $this->approver_type === self::BY_USER
            ? ($this->approver?->name ?? 'a named approver')
            : ($this->role_name ?: 'anyone with access');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
