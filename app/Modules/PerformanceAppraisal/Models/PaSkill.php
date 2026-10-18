<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaSkill extends Model
{
    protected $table = 'pa_skills';

    protected $fillable = [
        'name', 'category', 'description', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(PaEmployeeSkill::class, 'skill_id');
    }
}
