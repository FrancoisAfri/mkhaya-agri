<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ComplianceRuleType;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetComplianceRule extends Model
{
    use Uuids, SoftDeletes;

    protected $table = 'asset_compliance_rules';

    protected $fillable = [
        'uuid', 'name', 'description', 'rule_type', 'applies_to',
        'asset_category_id', 'interval_days', 'grace_period_days',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'interval_days'     => 'integer',
        'grace_period_days' => 'integer',
        'rule_type'         => ComplianceRuleType::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AssetComplianceCheck::class, 'rule_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
