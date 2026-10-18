<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M8 — ROI Reporting
 * 
 * Return on investment analysis for capital planning.
 */
class AssetRoiReport extends Model
{
    protected $table = 'asset_roi_reports';

    protected $fillable = [
        'uuid',
        'asset_id',
        'report_date',
        'total_investment',
        'total_revenue_generated',
        'total_cost_savings',
        'total_operating_cost',
        'net_benefit',
        'roi_percentage',
        'payback_period_months',
    ];

    protected $casts = [
        'report_date'              => 'date',
        'total_investment'         => 'decimal:2',
        'total_revenue_generated'  => 'decimal:2',
        'total_cost_savings'       => 'decimal:2',
        'total_operating_cost'     => 'decimal:2',
        'net_benefit'              => 'decimal:2',
        'roi_percentage'           => 'decimal:2',
        'payback_period_months'    => 'integer',
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
