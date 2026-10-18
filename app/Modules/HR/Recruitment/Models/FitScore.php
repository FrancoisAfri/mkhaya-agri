<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FitScore extends Model
{
    use HasFactory;

    protected $table = 'candidate_fit_scores';

    // All score fields are written exclusively by CalculateFitScore job — not user-fillable
    protected $fillable = [];

    protected $guarded = ['id'];

    protected $casts = [
        'overall_score'       => 'decimal:2',
        'skills_score'        => 'decimal:2',
        'experience_score'    => 'decimal:2',
        'qualification_score' => 'decimal:2',
        'cultural_score'      => 'decimal:2',
        'calculated_at'       => 'datetime',
    ];

    // Relationships

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function explanations()
    {
        return $this->hasMany(FitExplanation::class);
    }
}
