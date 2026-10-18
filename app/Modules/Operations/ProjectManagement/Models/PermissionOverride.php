<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An explicit allow or deny for one person (PM-1023).
 *
 * Roles answer "what does someone in this position normally get". This answers the exceptions:
 * the contractor who must not see one particular project, or the analyst who needs cost visibility
 * on one job without being given it everywhere.
 *
 * A `deny` beats every grant from every source — that is what "deny rules override" means, and it
 * is the only way to take something away that a role gives.
 */
class PermissionOverride extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_permission_overrides';

    protected $fillable = [
        'uuid', 'company_id', 'user_id', 'project_id', 'permission', 'effect', 'reason', 'created_by',
    ];

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
