<?php

namespace App\Modules\Assets\Models;

use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetModel extends Model
{
    use Uuids;

    protected $table = 'asset_models';

    protected $fillable = ['uuid', 'make_id', 'name', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function make(): BelongsTo
    {
        return $this->belongsTo(AssetMake::class, 'make_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Assets::class, 'asset_model_id');
    }
}
