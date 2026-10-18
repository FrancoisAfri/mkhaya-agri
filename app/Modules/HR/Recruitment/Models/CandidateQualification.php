<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateQualification extends Model
{
    use HasFactory;

    protected $table = 'candidate_qualifications';

    protected $fillable = [
        'candidate_id',
        'institution',
        'qualification_type',
        'field_of_study',
        'year_completed',
        'ai_extracted',
        // NOT: is_verified, verified_at — set by verification action only
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'ai_extracted'=> 'boolean',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
