<?php

namespace App\Modules\HR\Hr\Models;

use App\Modules\Assets\Models\AssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicensesType extends Model
{
    public $table = 'licence_type';

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    public function licence(): HasMany
    {
        return $this->hasMany(AssetType::class, 'assetType_id', 'id');
    }
}
