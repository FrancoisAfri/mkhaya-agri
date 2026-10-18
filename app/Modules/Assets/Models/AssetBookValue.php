<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M14 — Asset Book Value Snapshot
 * 
 * Monthly snapshot of book values (accounting + tax tracks).
 * Used for financial reporting and trend analysis.
 */
class AssetBookValue extends Model
{
    protected $table = 'asset_book_values';

    protected $fillable = [
        'uuid',
        'asset_id',
        'snapshot_date',
        'accounting_book_value',
        'tax_book_value',
        'accumulated_depreciation_accounting',
        'accumulated_depreciation_tax',
        'impairment_loss',
        'revaluation_surplus',
    ];

    protected $casts = [
        'snapshot_date'                       => 'date',
        'accounting_book_value'               => 'decimal:2',
        'tax_book_value'                      => 'decimal:2',
        'accumulated_depreciation_accounting' => 'decimal:2',
        'accumulated_depreciation_tax'        => 'decimal:2',
        'impairment_loss'                     => 'decimal:2',
        'revaluation_surplus'                 => 'decimal:2',
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
