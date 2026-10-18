<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSkillProfile extends Model
{
    use HasFactory;

    protected $table = 'employee_skill_profiles';

    protected $fillable = [
        'user_id',
        'skill',
        'proficiency_level',
        'years_experience',
        'last_assessed_at',
    ];

    protected $casts = [
        'last_assessed_at' => 'datetime',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }
}
