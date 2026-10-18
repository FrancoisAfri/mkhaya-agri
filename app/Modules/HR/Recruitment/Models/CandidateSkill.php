<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateSkill extends Model
{
    use HasFactory;

    protected $table = 'candidate_skills';

    protected $fillable = [
        'candidate_id',
        'skill',
        'proficiency_level',
        'years_experience',
        'ai_extracted',
    ];

    protected $casts = [
        'ai_extracted' => 'boolean',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
