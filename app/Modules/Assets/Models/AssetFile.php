<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AssetFile extends Model
{
    protected $table = 'asset_files';

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'user_id',
        'asset_id',
        'document',
        'date_added',
        'status',
    ];

    protected $casts = [
        'date_added' => 'date',
        'status' => 'integer',
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
    public function asset()
    {
        return $this->belongsTo(Assets::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
