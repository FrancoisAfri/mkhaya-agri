<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M14 — Financial Lifecycle Tracking
 * 
 * Complete financial history from acquisition to disposal.
 */
class AssetFinancialLifecycle extends Model
{
    protected $table = 'asset_financial_lifecycle';

    protected $fillable = [
        'uuid',
        'asset_id',
        'acquisition_cost',
        'total_depreciation',
        'total_maintenance_cost',
        'total_repair_cost',
        'total_upgrade_cost',
        'disposal_proceeds',
        'disposal_date',
        'gain_loss_on_disposal',
        'total_cost_of_ownership',
    ];

    protected $casts = [
        'acquisition_cost'         => 'decimal:2',
        'total_depreciation'       => 'decimal:2',
        'total_maintenance_cost'   => 'decimal:2',
        'total_repair_cost'        => 'decimal:2',
        'total_upgrade_cost'       => 'decimal:2',
        'disposal_proceeds'        => 'decimal:2',
        'disposal_date'            => 'date',
        'gain_loss_on_disposal'    => 'decimal:2',
        'total_cost_of_ownership'  => 'decimal:2',
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
