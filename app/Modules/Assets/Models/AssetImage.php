<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AssetImage extends Model
{
    protected $table = 'asset_images';

    protected $fillable = ['asset_id', 'path', 'original_name', 'size', 'sort_order'];

    protected $appends = ['url'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
