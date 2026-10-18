<?php

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Enums\SarsAllowanceSection;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    use SoftDeletes;
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetCategoryFactory::new();
    }

    protected $table = 'asset_categories';

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'parent_id',
        'sars_wear_rate',
        'sars_allowance_section',
        'default_depreciation_method',
        'default_useful_life_years',
        'status',
    ];

    protected $casts = [
        'sars_wear_rate'             => 'decimal:4',
        'default_useful_life_years'  => 'integer',
        'status'                     => 'integer',
        'sars_allowance_section'     => SarsAllowanceSection::class,
        'default_depreciation_method' => DepreciationMethod::class,
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Assets::class, 'asset_category_id');
    }
}
