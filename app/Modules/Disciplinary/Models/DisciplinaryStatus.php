<?php

namespace App\Modules\Disciplinary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryStatus extends Model
{
    protected $table = 'disciplinary_statuses';

    protected $fillable = [
        'name', 'color', 'is_closed', 'sort_order', 'active',
    ];

    protected $casts = [
        'is_closed'  => 'boolean',
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class, 'status_id');
    }
}
