<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\ConsentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateConsent extends Model
{
    use HasFactory;

    protected $table = 'candidate_consents';

    protected $fillable = [
        'candidate_id',
        'consent_type',
        'status',
        'ip_address',
        'user_agent',
        'notes',
        // NOT fillable: granted_at, declined_at, revoked_at — server-set only
    ];

    protected $casts = [
        'status'      => ConsentStatus::class,
        'granted_at'  => 'datetime',
        'declined_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    // Helpers

    public function isGranted(): bool
    {
        return $this->status === ConsentStatus::Granted;
    }
}
