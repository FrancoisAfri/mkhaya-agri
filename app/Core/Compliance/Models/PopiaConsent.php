<?php

namespace App\Core\Compliance\Models;

use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

/**
 * Tracks explicit POPIA consent per data subject per purpose.
 *
 * Purposes (non-exhaustive):
 *   processing          – lawful basis for holding the record at all
 *   marketing           – sending promotional communications
 *   third_party_sharing – sharing with medical schemes, MCCs, etc.
 *   ai_processing       – feeding data into AI/ML models
 */
class PopiaConsent extends Model
{
    use Uuids;

    protected $table = 'popia_consents';

    protected $fillable = [
        'uuid',
        'subject_type',
        'subject_id',
        'purpose',
        'status',           // granted | declined | revoked
        'consent_version',  // version of the consent wording shown
        'ip_address',
        'user_agent',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'notes',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->morphTo();
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'granted'
            && is_null($this->revoked_at)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'granted')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
