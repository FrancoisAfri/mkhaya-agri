<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\MobilityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternalMobilityMatch extends Model
{
    use HasFactory;

    protected $table = 'internal_mobility_matches';

    // All fields set by GenerateInternalMobilityMatches job
    protected $fillable = [];

    protected $guarded = ['id'];

    protected $casts = [
        'match_score'  => 'decimal:2',
        'match_type'   => MobilityType::class,
        'ai_generated' => 'boolean',
        'generated_at' => 'datetime',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
