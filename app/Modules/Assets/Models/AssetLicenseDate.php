<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AssetLicenseDate extends Model
{
    protected $table = 'asset_license_dates';

    protected $fillable = [
        'uuid',
        'purchase_date',
        'expiration_date',
        'renewal_date',
        'license_id',
        'user_id',
        'status',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiration_date' => 'date',
        'renewal_date' => 'date',
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

    public function license()
    {
        return $this->belongsTo(AssetLicense::class, 'license_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
