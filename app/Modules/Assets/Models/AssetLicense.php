<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AssetLicense extends Model
{
    use SoftDeletes;

    protected $table = 'asset_license';

    protected $fillable = [
        'uuid',
        'name',
        'details',
        'serial',
        'purchase_date',
        'purchase_cost',
        'order_number',
        'total',
        'notes',
        'user_id',
        'asset_type_id',
        'expiration_date',
        'licence_image',
        'status',
        'licence_status',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiration_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'total' => 'integer',
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

    // Relationships (adjust based on your schema)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
