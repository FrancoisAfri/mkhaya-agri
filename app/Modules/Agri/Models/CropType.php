<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CropType extends AgriModel
{
    public const CATEGORIES = ['grain', 'oilseed', 'legume', 'vegetable', 'fruit', 'fodder', 'fibre', 'sugar', 'nut', 'herb', 'other'];

    public const YIELD_UNITS = ['t', 'kg', 'bales', 'crates', 'bins', 'l', 'heads'];

    protected $table = 'crop_types';

    protected $casts = ['is_active' => 'boolean'];

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }
}
