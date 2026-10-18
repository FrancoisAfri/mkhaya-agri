<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterviewResponse extends Model
{
    use HasFactory;

    protected $table = 'interview_responses';

    protected $fillable = [
        'interview_question_id',
        'candidate_id',
        'response',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    // Relationships

    public function question()
    {
        return $this->belongsTo(InterviewQuestion::class, 'interview_question_id');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
