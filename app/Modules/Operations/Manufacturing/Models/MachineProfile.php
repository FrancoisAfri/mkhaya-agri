<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the plant knows about a machine beyond the asset register: whether it may be used, and the
 * run-hour meter its usage-based maintenance counts from (MFG-013).
 */
class MachineProfile extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    public const NONE = 'NONE';
    public const MAINTENANCE = 'MAINTENANCE';
    public const SAFETY = 'SAFETY';

    protected $table = 'mfg_machine_profiles';

    protected $fillable = [
        'company_id', 'asset_id', 'hold_status', 'hold_reason', 'held_by', 'held_at', 'requires_return_to_service',
        'released_by', 'released_at', 'release_notes', 'run_hours_baseline',
    ];

    protected $casts = [
        'held_at' => 'datetime', 'released_at' => 'datetime', 'requires_return_to_service' => 'boolean', 'run_hours_baseline' => 'decimal:2',
    ];

    public function asset(): BelongsTo { return $this->belongsTo(\App\Modules\Assets\Models\Assets::class, 'asset_id'); }

    public function isHeld(): bool { return $this->hold_status !== self::NONE; }
}
