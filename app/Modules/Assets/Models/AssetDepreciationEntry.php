<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetDepreciationEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationEntry extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetDepreciationEntryFactory::new();
    }

    protected $table = 'asset_depreciation_entries';

    protected $fillable = [
        'schedule_id', 'asset_id', 'period_year', 'period_month', 'period_sequence',
        'opening_book_value', 'depreciation_amount', 'closing_book_value',
        'is_posted', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'opening_book_value'  => 'decimal:2',
        'depreciation_amount' => 'decimal:2',
        'closing_book_value'  => 'decimal:2',
        'posted_at'           => 'datetime',
        'is_posted'           => 'boolean',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AssetDepreciationSchedule::class, 'schedule_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
