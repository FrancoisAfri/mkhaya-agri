<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Why stock changed (WMS-020, Part A objective 3): loss, damage, found, count variance, sample. */
class ReasonCode extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_reason_codes';

    protected $fillable = ['uuid', 'company_id', 'code', 'name', 'category', 'direction', 'approval_qty', 'approval_value', 'always_requires_approval', 'is_active', 'requires_evidence', 'approve_high_risk'];

    protected $casts = ['approval_qty' => 'decimal:4', 'approval_value' => 'decimal:2', 'always_requires_approval' => 'boolean', 'is_active' => 'boolean', 'requires_evidence' => 'boolean', 'approve_high_risk' => 'boolean'];

    public const CATEGORIES = ['LOSS', 'DAMAGE', 'FOUND', 'COUNT', 'QUALITY', 'RETURN', 'SAMPLE', 'OTHER'];

    /** Installed for every company the first time it adjusts stock; editable afterwards. */
    public const DEFAULTS = [
        ['COUNT', 'Count variance', 'COUNT', 'BOTH', null, 5000, false],
        ['DAMAGED', 'Damaged in warehouse', 'DAMAGE', 'OUT', null, 2000, false],
        ['DAMAGED_IN', 'Damaged on arrival', 'DAMAGE', 'OUT', null, 2000, false],
        ['EXPIRED', 'Expired', 'LOSS', 'OUT', null, 2000, false],
        ['THEFT', 'Theft or unexplained loss', 'LOSS', 'OUT', null, null, true],
        ['FOUND', 'Found stock', 'FOUND', 'IN', null, 2000, false],
        ['SAMPLE', 'Sample or promotional use', 'SAMPLE', 'OUT', null, 1000, false],
        ['QUALITY', 'Quality rejection write-off', 'QUALITY', 'OUT', null, 2000, false],
        ['WRITE_OFF', 'Write-off (other)', 'LOSS', 'OUT', null, null, true],
        ['CORRECTION', 'Data entry correction', 'OTHER', 'BOTH', null, 2000, false],
        ['BREAKAGE', 'Breakage', 'DAMAGE', 'OUT', null, 2000, false, true],
        ['SPILLAGE', 'Spillage or leakage', 'DAMAGE', 'OUT', null, 2000, false, true],
        ['RETURN', 'Customer return', 'RETURN', 'BOTH', null, 5000, false],
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
