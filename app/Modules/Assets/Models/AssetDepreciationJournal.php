<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M14 — Depreciation Journal Entry
 * 
 * GL journal entries generated from depreciation runs.
 * Integrates with accounting module for posting.
 */
class AssetDepreciationJournal extends Model
{
    protected $table = 'asset_depreciation_journals';

    protected $fillable = [
        'uuid',
        'depreciation_id',
        'journal_entry_id',          // FK to accounting module
        'period_start',
        'period_end',
        'depreciation_expense',
        'accumulated_depreciation',
        'track_type',                // accounting | tax
        'posted_at',
        'posted_by_user_id',
    ];

    protected $casts = [
        'period_start'               => 'date',
        'period_end'                 => 'date',
        'depreciation_expense'       => 'decimal:2',
        'accumulated_depreciation'   => 'decimal:2',
        'posted_at'                  => 'datetime',
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
    public function depreciation(): BelongsTo
    {
        return $this->belongsTo(AssetDepreciation::class, 'depreciation_id');
    }
}
