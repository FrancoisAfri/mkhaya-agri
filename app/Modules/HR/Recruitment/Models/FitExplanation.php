<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FitExplanation extends Model
{
    use HasFactory;

    protected $table = 'candidate_fit_explanations';

    protected $fillable = [];

    protected $guarded = ['id'];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    // Relationships

    public function fitScore()
    {
        return $this->belongsTo(FitScore::class);
    }
}
