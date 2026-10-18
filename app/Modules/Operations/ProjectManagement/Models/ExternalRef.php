<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The seam between Project Management and the rest of the platform.
 *
 * A project member may be an HR employee; a client may be a CRM customer; a resource may be a
 * Fleet vehicle. None of those modules is guaranteed to be licensed, so PM never foreign-keys into
 * them. It stores a link plus a denormalised `display_name` and `snapshot`, which means:
 *
 *  - with the source module licensed, the link resolves live and syncs;
 *  - without it, PM still renders a named member/client and stays fully usable standalone;
 *  - if the source record later disappears, sync_status becomes 'orphaned' rather than the PM
 *    record breaking.
 *
 * source_module 'local' means "no counterpart module — PM owns these details".
 */
class ExternalRef extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_external_refs';

    protected $fillable = [
        'uuid', 'company_id', 'local_type', 'local_id', 'source_module', 'source_entity',
        'source_id', 'display_name', 'snapshot', 'last_synced_at', 'sync_status',
    ];

    protected $casts = [
        'snapshot'       => 'array',
        'last_synced_at' => 'datetime',
    ];

    public const LOCAL_TYPES = ['project_member', 'project_client', 'resource', 'supplier'];

    /** ok = resolved; stale = not synced recently; orphaned = source gone; conflict = both changed. */
    public const SYNC_STATUSES = ['ok', 'stale', 'orphaned', 'conflict'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** True when this link is PM-owned data rather than a pointer into another module. */
    public function isLocal(): bool
    {
        return $this->source_module === 'local';
    }
}
