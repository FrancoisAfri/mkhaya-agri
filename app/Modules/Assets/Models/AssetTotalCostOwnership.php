<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M14 — Total Cost of Ownership (TCO) Analysis
 * 
 * Periodic TCO calculation for replacement decision support.
 */
class AssetTotalCostOwnership extends Model
{
    protected $table = 'asset_total_cost_ownership';

    protected $fillable = [
        'uuid',
        'asset_id',
        'calculation_date',
        'acquisition_cost',
        'maintenance_cost',
        'operating_cost',
        'downtime_cost',
        'disposal_value',
        'total_tco',
        'tco_per_year',
        'tco_per_month',
    ];

    protected $casts = [
        'calculation_date'  => 'date',
        'acquisition_cost'  => 'decimal:2',
        'maintenance_cost'  => 'decimal:2',
        'operating_cost'    => 'decimal:2',
        'downtime_cost'     => 'decimal:2',
        'disposal_value'    => 'decimal:2',
        'total_tco'         => 'decimal:2',
        'tco_per_year'      => 'decimal:2',
        'tco_per_month'     => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }
}
