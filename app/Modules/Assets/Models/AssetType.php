<?php

namespace App\Modules\Assets\Models;

use App\Modules\HR\Hr\Models\LicensesType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetType extends Model
{
    public $table = 'asset_type';

    protected $fillable = [
        'name',
        'description',
        'status','licenceType_id','depreciation_year'
    ];


    public function licences(): BelongsTo
    {
        return $this->belongsTo(LicensesType::class, 'licenceType_id')->orderBy('id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Assets::class, 'asset_type_id');
    }



}
