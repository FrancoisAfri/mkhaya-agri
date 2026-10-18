<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One rung of a budget approval ladder.
 *
 * Approvers are named by rule wherever it can be helped. "Whoever holds Finance Manager" survives a
 * resignation; "Thandi" does not, and a flow that stops working the week somebody leaves is a flow
 * that gets bypassed rather than fixed.
 */
class BudgetApprovalStep extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budget_approval_steps';

    protected $fillable = [
        'uuid', 'company_id', 'flow_id', 'position', 'name',
        'approver_type', 'approver_ref', 'min_approvals', 'allow_self_approval',
    ];

    protected $casts = [
        'position'            => 'integer',
        'min_approvals'       => 'integer',
        'allow_self_approval' => 'boolean',
    ];

    /**
     * role          a platform role held tenant-wide
     * user          a named person, for the company that genuinely wants one
     * manager       the submitter's line manager, from HR when it is installed
     * budget_owner  whoever owns the budget this request is about (budget_owners)
     */
    public const APPROVER_TYPES = ['role', 'user', 'manager', 'budget_owner'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BudgetApprovalFlow::class, 'flow_id');
    }
}
