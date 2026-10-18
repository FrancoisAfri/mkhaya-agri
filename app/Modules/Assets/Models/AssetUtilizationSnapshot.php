<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetUtilizationSnapshot extends Model
{
    protected $table = 'asset_utilization_snapshots';

    protected $fillable = [
        'report_id', 'asset_id', 'total_operating_hours', 'total_downtime_minutes',
        'idle_time_minutes', 'utilization_rate', 'log_entry_count',
        'period_start', 'period_end',
    ];

    protected $casts = [
        'period_start'          => 'date',
        'period_end'            => 'date',
        'total_operating_hours' => 'float',
        'utilization_rate'      => 'float',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(AssetUtilizationReport::class, 'report_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }
}
