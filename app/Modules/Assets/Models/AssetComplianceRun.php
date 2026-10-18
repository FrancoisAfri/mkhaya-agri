<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetComplianceRun extends Model
{
    use Uuids;

    protected $table = 'asset_compliance_runs';

    protected $fillable = [
        'uuid', 'run_by', 'scope', 'asset_category_id', 'total_checked',
        'pass_count', 'fail_count', 'overdue_count', 'not_applicable_count',
        'compliance_rate', 'run_at',
    ];

    protected $casts = [
        'run_at'          => 'datetime',
        'compliance_rate' => 'float',
    ];

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AssetComplianceCheck::class, 'run_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
