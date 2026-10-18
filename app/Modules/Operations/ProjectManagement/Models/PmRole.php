<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A Project Management role, per tenant (PM-1021, PM-1022).
 *
 * Built-ins are seeded from RoleCatalog and reseeded on upgrade so a new permission reaches every
 * tenant. A clone belongs to the tenant that made it and is never overwritten — that is the whole
 * point of clone-and-edit.
 */
class PmRole extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_roles';

    protected $fillable = [
        'uuid', 'company_id', 'key', 'name', 'description', 'permissions',
        'is_builtin', 'cloned_from_id', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_builtin'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /** Client and Contractor are outside the business; cost and margin are redacted for them. */
    public function isExternal(): bool
    {
        return RoleCatalog::isExternal($this->key);
    }
}
