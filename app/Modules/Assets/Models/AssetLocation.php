<?php

namespace App\Modules\Assets\Models;

use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetLocationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetLocation extends Model
{
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetLocationFactory::new();
    }

    protected $table = 'asset_locations';

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'parent_id',
        'address',
        'gps_lat',
        'gps_lng',
        'status',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:8',
        'gps_lng' => 'decimal:8',
        'status'  => 'integer',
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
        return $this->hasMany(Assets::class, 'location_id');
    }

    public function fullPath(): string
    {
        $parts = collect([$this->name]);
        $parent = $this->parent;
        while ($parent) {
            $parts->prepend($parent->name);
            $parent = $parent->parent;
        }
        return $parts->implode(' > ');
    }
}
