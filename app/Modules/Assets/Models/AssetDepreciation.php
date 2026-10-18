<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AssetDepreciation extends Model
{
    protected $table = 'asset_depreciations';

    protected $fillable = [
        'uuid',
        'notes',
        'user_id',
        'asset_id',
        'months',
        'years',
        'amount_monthly',
        'initial_amount',
        'balance_amount',
    ];

    protected $casts = [
        'months' => 'integer',
        'years' => 'integer',
        'amount_monthly' => 'decimal:2',
        'initial_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
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

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
