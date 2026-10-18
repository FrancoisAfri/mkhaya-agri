<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\MisuseFlagType;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMisuseDetectionRule extends Model
{
    use Uuids;

    protected $table = 'asset_misuse_detection_rules';

    protected $fillable = [
        'uuid', 'name', 'flag_type', 'is_active', 'parameters',
        'applies_to_category', 'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_active'  => 'boolean',
        'flag_type'  => MisuseFlagType::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'applies_to_category');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
