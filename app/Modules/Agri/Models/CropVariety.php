<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropVariety extends AgriModel
{
    protected $table = 'crop_varieties';

    protected $casts = ['is_active' => 'boolean'];

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }
}
