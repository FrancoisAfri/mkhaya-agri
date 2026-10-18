<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AssetLog extends Model
{
    protected $table = 'asset_log';

    protected $fillable = [
        'uuid',
        'user_id',
        'action_type',
        'asset_id',
        'checkedout_to',
        'store_id',
        'status',
    ];

    protected $casts = [
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
