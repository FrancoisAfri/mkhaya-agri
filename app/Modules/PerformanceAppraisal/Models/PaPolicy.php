<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaPolicy extends Model
{
    protected $table = 'pa_policies';
    protected $guarded = [];

    protected $casts = [
        'condition_config' => 'array',
        'is_active'        => 'boolean',
    ];

    public function violations(): HasMany
    {
        return $this->hasMany(PaPolicyViolation::class, 'policy_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PaPolicyException::class, 'policy_id');
    }
}
