<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\IncidentSeverity;
use App\Modules\Assets\Enums\MisuseFlagStatus;
use App\Modules\Assets\Enums\MisuseFlagType;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetMisuseFlag extends Model
{
    use Uuids, SoftDeletes;

    protected $table = 'asset_misuse_flags';

    protected $fillable = [
        'uuid', 'asset_id', 'digital_twin_id', 'flagged_by', 'flag_type',
        'severity', 'status', 'description', 'evidence', 'detected_at',
        'assigned_investigator', 'investigated_at', 'resolution_notes',
        'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'evidence'        => 'array',
        'detected_at'     => 'datetime',
        'investigated_at' => 'datetime',
        'resolved_at'     => 'datetime',
        'flag_type'       => MisuseFlagType::class,
        'severity'        => IncidentSeverity::class,
        'status'          => MisuseFlagStatus::class,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function digitalTwin(): BelongsTo
    {
        return $this->belongsTo(AssetDigitalTwin::class, 'digital_twin_id');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_investigator');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
