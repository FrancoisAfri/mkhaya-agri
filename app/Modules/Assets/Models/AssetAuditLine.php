<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line in an asset stock-take: one expected asset, or a scan result
 * (found / missing / unexpected).
 *
 * Tenant-scoped at the app layer (BelongsToCompany) since this table post-dates
 * the RLS blanket migration.
 */
class AssetAuditLine extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_audit_lines';

    protected $fillable = [
        'uuid',
        'company_id',
        'audit_id',
        'asset_id',
        'scanned_code',
        'status',
        'scanned_by',
        'scanned_at',
        'notes',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public const STATUS_EXPECTED   = 'expected';
    public const STATUS_FOUND      = 'found';
    public const STATUS_MISSING    = 'missing';
    public const STATUS_UNEXPECTED = 'unexpected';

    public function audit(): BelongsTo
    {
        return $this->belongsTo(AssetAudit::class, 'audit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
