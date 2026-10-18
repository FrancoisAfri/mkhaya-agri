<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One rung of an approval ladder.
 *
 * Approvers are named by rule, never by person where it can be avoided. "Whoever holds Finance
 * Manager" survives a resignation; "Thandi" does not, and a flow that stops working the week
 * somebody leaves is a flow that gets bypassed rather than fixed.
 */
class ApprovalStep extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_approval_steps';

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
     * role     a platform role held tenant-wide
     * pm_role  a role on this project — resolved against pm_project_members
     * user     a named person, for the company that genuinely wants one
     * manager  the submitter's line manager, from HR when installed
     */
    public const APPROVER_TYPES = ['role', 'pm_role', 'user', 'manager'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'flow_id');
    }
}
