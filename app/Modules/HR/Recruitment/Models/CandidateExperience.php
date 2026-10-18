<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateExperience extends Model
{
    use HasFactory;

    protected $table = 'candidate_experience';

    protected $fillable = [
        'candidate_id',
        'company_name',
        'job_title',
        'start_date',
        'end_date',
        'is_current',
        'description',
        'ai_extracted',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'is_current'   => 'boolean',
        'ai_extracted' => 'boolean',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    // Helpers

    public function getDurationMonthsAttribute(): ?int
    {
        $end = $this->is_current ? now() : $this->end_date;
        return $end ? (int) $this->start_date->diffInMonths($end) : null;
    }
}
