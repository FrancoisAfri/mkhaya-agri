<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\UtilizationScope;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetUtilizationReport extends Model
{
    use Uuids;

    protected $table = 'asset_utilization_reports';

    protected $fillable = [
        'uuid', 'generated_by', 'report_name', 'period_start', 'period_end',
        'scope', 'scope_id', 'total_assets_in_scope', 'summary_stats',
        'top_utilized', 'least_utilized', 'generated_at',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'summary_stats'   => 'array',
        'top_utilized'    => 'array',
        'least_utilized'  => 'array',
        'generated_at'    => 'datetime',
        'scope'           => UtilizationScope::class,
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AssetUtilizationSnapshot::class, 'report_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
