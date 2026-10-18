<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\DepreciationMethod;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetDepreciationScheduleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetDepreciationSchedule extends Model
{
    use Uuids, SoftDeletes, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetDepreciationScheduleFactory::new();
    }

    protected $table = 'asset_depreciation_schedules';

    protected $fillable = [
        'uuid', 'asset_id', 'depreciation_method', 'useful_life_years',
        'sars_wear_rate', 'purchase_cost', 'salvage_value', 'total_periods',
        'generated_by', 'generated_at', 'is_active',
    ];

    protected $casts = [
        'purchase_cost'      => 'decimal:2',
        'salvage_value'      => 'decimal:2',
        'sars_wear_rate'     => 'decimal:4',
        'generated_at'       => 'datetime',
        'is_active'          => 'boolean',
        'depreciation_method' => DepreciationMethod::class,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class, 'schedule_id')->orderBy('period_sequence');
    }

    public function latestPostedEntry(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetDepreciationEntry::class, 'schedule_id')
            ->where('is_posted', true)
            ->orderByDesc('period_sequence');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
