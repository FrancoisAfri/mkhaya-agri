<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterviewQuestion extends Model
{
    use HasFactory;

    protected $table = 'interview_questions';

    protected $fillable = [
        'interview_id',
        'question',
        'category',
        'ai_generated',
        'order_index',
    ];

    protected $casts = [
        'ai_generated' => 'boolean',
    ];

    // Relationships

    public function interview()
    {
        return $this->belongsTo(Interview::class);
    }

    public function responses()
    {
        return $this->hasMany(InterviewResponse::class);
    }
}
