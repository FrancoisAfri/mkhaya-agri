<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Physical audit / stock-take run over a snapshot of the asset population.
 *
 * Tenant-scoped at the app layer (BelongsToCompany) since this table post-dates
 * the RLS blanket migration.
 */
class AssetAudit extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_audits';

    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'location_id',
        'status',
        'started_by',
        'started_at',
        'completed_at',
        'expected_count',
        'found_count',
        'missing_count',
        'unexpected_count',
        'notes',
    ];

    protected $casts = [
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'expected_count'   => 'integer',
        'found_count'      => 'integer',
        'missing_count'    => 'integer',
        'unexpected_count' => 'integer',
    ];

    public const STATUS_OPEN      = 'open';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function lines(): HasMany
    {
        return $this->hasMany(AssetAuditLine::class, 'audit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
