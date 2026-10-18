<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Someone on a project, in a role scoped to that project (PM-1023).
 *
 * `user_id` is nullable on purpose: a member may be a person with no login — a subcontractor's
 * foreman who exists as a name and a phone number. Their identity then hangs off `external_ref_id`,
 * which resolves to an HR employee when that module is licensed and to a PM-local record when it is
 * not (PM-1300b). A member without a user simply has no permissions to evaluate.
 */
class ProjectMember extends Model
{
    use AuditsChanges, BelongsToCompany, HasFactory, HasPublicUuid, LogsActivity, SoftDeletes;


    /** Factories live under Database\Factories\ProjectManagement, not the default path. */
    protected static function newFactory(): \Database\Factories\ProjectManagement\ProjectMemberFactory
    {
        return \Database\Factories\ProjectManagement\ProjectMemberFactory::new();
    }

    protected $table = 'pm_project_members';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'user_id', 'external_ref_id', 'role_key',
        'display_name', 'email', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function externalRef(): BelongsTo
    {
        return $this->belongsTo(ExternalRef::class, 'external_ref_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
