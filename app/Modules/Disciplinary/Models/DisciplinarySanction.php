<?php

namespace App\Modules\Disciplinary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinarySanction extends Model
{
    protected $table = 'disciplinary_sanctions';

    protected $fillable = [
        'name', 'description', 'severity_level', 'duration_days', 'active',
    ];

    protected $casts = [
        'active'        => 'boolean',
        'duration_days' => 'integer',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class, 'sanction_id');
    }
}
