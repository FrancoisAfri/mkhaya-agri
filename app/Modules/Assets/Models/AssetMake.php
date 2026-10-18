<?php

namespace App\Modules\Assets\Models;

use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetMake extends Model
{
    use Uuids;

    protected $table = 'asset_makes';

    protected $fillable = ['uuid', 'name', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class, 'make_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Assets::class, 'asset_make_id');
    }
}
