<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateCertification extends Model
{
    use HasFactory;

    protected $table = 'candidate_certifications';

    protected $fillable = [
        'candidate_id',
        'name',
        'issuer',
        'issued_at',
        'expires_at',
        'certificate_url',
        'ai_extracted',
    ];

    protected $casts = [
        'issued_at'    => 'date',
        'expires_at'   => 'date',
        'ai_extracted' => 'boolean',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    // Helpers

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
