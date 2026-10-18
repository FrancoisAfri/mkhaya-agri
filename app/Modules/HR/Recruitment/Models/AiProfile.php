<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProfile extends Model
{
    use HasFactory;

    protected $table = 'candidate_ai_profiles';

    // All fields are set by GenerateAiSummary job — none are user-fillable
    protected $fillable = [];

    protected $guarded = ['id'];

    protected $casts = [
        'strengths'             => 'array',
        'weaknesses'            => 'array',
        'flags'                 => 'array',
        'keywords'              => 'array',
        'parsed_skills'         => 'array',
        'parsed_experience'     => 'array',
        'parsed_qualifications' => 'array',
        'generated_at'          => 'datetime',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
