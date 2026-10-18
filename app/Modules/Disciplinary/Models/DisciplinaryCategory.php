<?php

namespace App\Modules\Disciplinary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryCategory extends Model
{
    protected $table = 'disciplinary_categories';

    protected $fillable = [
        'name', 'code', 'description', 'severity_level', 'active', 'created_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class, 'category_id');
    }
}
