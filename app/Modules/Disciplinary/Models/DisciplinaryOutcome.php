<?php

namespace App\Modules\Disciplinary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryOutcome extends Model
{
    protected $table = 'disciplinary_outcomes';

    protected $fillable = [
        'name', 'description', 'requires_sanction', 'active',
    ];

    protected $casts = [
        'requires_sanction' => 'boolean',
        'active'            => 'boolean',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class, 'outcome_id');
    }
}
